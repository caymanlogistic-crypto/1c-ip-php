<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\Audit;
use PDO;
use RuntimeException;

final class FinalSettlement
{
    public const TERMS='closing.terms';
    public const FINANCE='closing.financial-basis';
    public const AGREEMENT='closing.agreement';
    public const AGREEMENT_FILE='closing-agreement';
    public const ACTIONS=[self::TERMS,self::FINANCE,self::AGREEMENT];
    public const VARIABLES=['open'=>'Открытый расчёт','counted'=>'Сумма подтверждена','exempt'=>'Не применяется по подтверждённому основанию'];
    public const AGREEMENT_STATES=['signed'=>'Отдельное соглашение подписано','not_required'=>'Отдельное соглашение не требуется','unresolved'=>'Применимость требует решения'];
    private const NS='rental_closing_basis';

    public static function fact(PDO $db,string $namespace,string $key): array
    {
        $q=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $q->execute([$namespace,$key]);
        return Importer::decodeExact($q->fetchColumn() ?: '{}');
    }

    public static function read(PDO $db,int $id): array
    {
        return self::fact($db,self::NS,(string)$id);
    }

    public static function stage(array $contract): void
    {
        if (!FinalReview::ready($contract)) throw new RuntimeException('Договор не на финальной сверке.',409);
    }

    public static function timeline(array $input): array
    {
        $dates=[];
        foreach (['notice_date','notice_due','last_payable_day'] as $field) {
            $value=DirectoryDetails::text($input,$field,10);
            $dates[$field]=$value===''?null:Service::date($value);
        }
        if ($dates['notice_date'] && $dates['notice_date']>date('Y-m-d'))
            throw new RuntimeException('Фактическое уведомление не может быть датировано будущим.',422);
        return $dates+['notice_rule'=>DirectoryDetails::text($input,'notice_rule',600)];
    }

    public static function start(PDO $db,int $id,array $input): void
    {
        if (($input['closing_scope'] ?? '')==='1') self::store($db,$id,['timeline'=>self::timeline($input)]);
    }

    public static function files(PDO $db,int $id): array
    {
        $q=$db->prepare("SELECT * FROM rental_files WHERE entity_type='contract' AND entity_id=? AND kind=? ORDER BY upload_order DESC");
        $q->execute([$id,self::AGREEMENT_FILE]);
        return array_column($q->fetchAll(),null,'id');
    }

    public static function legalBasisFiles(PDO $db,int $id): array
    {
        $q=$db->prepare("SELECT f.* FROM rental_files f
            LEFT JOIN rental_documents d ON f.entity_type='document' AND f.entity_id=d.id
            WHERE (f.entity_type='contract' AND f.entity_id=? AND f.kind='signed-contract')
                OR (d.contract_id=? AND d.status IN ('signed','applied') AND f.kind='signed-addendum')
            ORDER BY f.upload_order DESC");
        $q->execute([$id,$id]);
        return array_column($q->fetchAll(),null,'id');
    }

    public static function invoices(PDO $db,int $id): array
    {
        $q=$db->prepare("SELECT id,number,amount,paid,period,kind,source_json FROM invoices WHERE contract_id=? AND status='active'
            AND COALESCE(kind,'')<>'Обеспечительный платёж' ORDER BY id");
        $q->execute([$id]);
        return array_column($q->fetchAll(),null,'id');
    }

    public static function balance(PDO $db,int $id,array $finance): array
    {
        if (!isset($finance['debt'],$finance['penalty'])) return ['question'=>'Подтвердите сумму долга и неустойки.'];
        $target=bcadd($finance['debt'],$finance['penalty'],2);
        $gross='0.00';
        $paid='0.00';
        $invoices=self::invoices($db,$id);
        foreach ($finance['invoice_ids'] as $invoiceId) {
            $invoice=$invoices[$invoiceId] ?? null;
            if (!$invoice || $invoice['amount']===null || $invoice['paid']===null
                || bccomp($invoice['paid'],'0',2)<0 || bccomp($invoice['paid'],$invoice['amount'],2)>0)
                return ['question'=>'Уточните сумму или учтённую оплату выбранного окончательного счёта.'];
            $gross=bcadd($gross,$invoice['amount'],2);
            $paid=bcadd($paid,$invoice['paid'],2);
        }
        if (bccomp($target,$gross,2)!==0)
            return ['question'=>'Свяжите окончательные счета на подтверждённую сумму долга и неустойки.'];
        $remaining=bcsub($target,$paid,2);
        return compact('target','paid','remaining');
    }

    public static function scoped(PDO $db,int $id): bool
    {
        return (bool)self::read($db,$id);
    }

    public static function assertReview(PDO $db,array $contract,string $kind): void
    {
        $id=(int)$contract['id'];
        $data=self::read($db,$id);
        // A legacy in-flight review keeps its original rules until its closing basis is recorded.
        if (!$data) return;
        if ($kind==='legal') {
            $decision=$data['agreement'] ?? [];
            $mode=$decision['state'] ?? 'unresolved';
            if ($mode==='unresolved')
                throw new RuntimeException('Уточните, требуется ли отдельное итоговое соглашение, и зафиксируйте юридическое основание.',409);
            $files=$mode==='signed'?self::files($db,$id):self::legalBasisFiles($db,$id);
            $file=$files[$decision['id'] ?? ''] ?? null;
            if (!$file || !hash_equals($decision['sha256'],$file['sha256']))
                throw new RuntimeException('Подтвердите сохранённый подписанный файл для выбранного юридического основания.',409);
            Evidence::integrity($file);
            return;
        }
        $finance=$data['finance'] ?? [];
        if (($finance['variable'] ?? 'open')==='open')
            throw new RuntimeException('Переменные расходы остаются открытыми. Подтвердите сумму или основание, по которому они не применяются.',409);
        $balance=self::balance($db,$id,$finance);
        if (isset($balance['question'])) throw new RuntimeException($balance['question'],409);
        if (bccomp($balance['remaining'],'0',2)>0)
            throw new RuntimeException('Окончательные счета ещё не погашены по учётным данным.',409);
        if ($question=self::omittedInvoiceQuestion($db,$id,$finance))
            throw new RuntimeException($question,409);
    }

    public static function omittedInvoiceQuestion(PDO $db,int $id,array $finance): ?string
    {
        $selected=array_fill_keys($finance['invoice_ids'] ?? [],true);
        foreach (self::invoices($db,$id) as $invoice) {
            if (isset($selected[$invoice['id']])) continue;
            $source=Importer::decodeExact($invoice['source_json']);
            $identified=!empty($source['regular_invoice']) || !empty($source['manual_regular_invoice'])
                || !empty($source['separate_initial_invoice']);
            $remaining=InvoiceFacts::remaining($invoice);
            if ($remaining===null || ($remaining!=='0.00' && !$identified))
                return 'Сверьте исходные суммы и применимость счёта № '.$invoice['number'].' к финальному расчёту.';
            if (bccomp($remaining,'0.00',2)>0)
                return 'По действующему счёту № '.$invoice['number'].' остаётся непогашенная сумма по учёту. Проверьте его в итоговых расчётах.';
        }
        return null;
    }

    public static function execute(PDO $db,array $actor,string $action,array $input): array
    {
        $kind=match($action){self::TERMS=>'timeline',self::FINANCE=>'finance',default=>'agreement'};
        Service::roles([match($kind){'timeline'=>'manager','finance'=>'accountant',default=>'lawyer'}]);
        $id=(int)($input['id'] ?? 0);
        Access::contract($id,2);
        $contract=Service::get($db,'contracts',$id);
        Service::version($contract,$input);
        self::stage($contract);
        $before=self::read($db,$id);
        if ($kind==='timeline') {
            $reason=DirectoryDetails::text($input,'reason',1500);
            if ($reason==='') throw new RuntimeException('Укажите основание завершения.',422);
            $value=self::timeline($input);
            $db->prepare('UPDATE contracts SET finish_reason=? WHERE id=?')->execute([$reason,$id]);
        } elseif ($kind==='agreement') {
            $value=self::legalInput($db,$id,$input);
        } else {
            $value=self::financialInput($db,$id,$input);
        }
        if (Importer::canonicalJson($before[$kind] ?? null)===Importer::canonicalJson($value)
            && ($kind!=='timeline' || $reason===$contract['finish_reason']))
            return ['id'=>$id,'message'=>'Это основание уже сохранено.'];
        $keys=match($kind){'timeline'=>['finance','legal'],'finance'=>['finance'],default=>['legal']};
        foreach ($keys as $key) FinalReview::clear($db,$id,$key);
        self::store($db,$id,array_replace($before,[$kind=>$value]));
        $db->prepare('UPDATE contracts SET version=version+1 WHERE id=?')->execute([$id]);
        Audit::record($action,'contract',$id,$before[$kind] ?? null,$value+['actor'=>$actor['name'],'when'=>date(DATE_ATOM)]);
        return ['id'=>$id,'message'=>'Основание финальной сверки сохранено.'];
    }

    public static function signedDate(array $input): string
    {
        $date=Service::date((string)($input['signed_on'] ?? ''));
        if ($date>date('Y-m-d')) throw new RuntimeException('Подписание не может быть датировано будущим.',422);
        return $date;
    }

    private static function legalInput(PDO $db,int $id,array $input): array
    {
        $mode=(string)($input['agreement_state'] ?? '');
        $reason=DirectoryDetails::text($input,'legal_basis',1000);
        if (!isset(self::AGREEMENT_STATES[$mode])) throw new RuntimeException('Укажите применимость итогового соглашения.',422);
        if ($mode==='unresolved') return ['state'=>$mode,'reason'=>$reason];
        $files=$mode==='signed'?self::files($db,$id):self::legalBasisFiles($db,$id);
        $selected=(string)($input[$mode==='signed'?'file_id':'basis_file_id'] ?? '');
        $file=$files[$selected] ?? null;
        if (!$file || ($input['confirmed'] ?? '')!=='1' || ($mode==='not_required' && $reason===''))
            throw new RuntimeException('Выберите подписанный файл и подтвердите основание. Для выезда без соглашения укажите пункт или условие.',422);
        Evidence::integrity($file);
        return ['state'=>$mode,'reason'=>$reason,'id'=>$file['id'],'sha256'=>$file['sha256']]
            +($mode==='signed'?['signed_on'=>self::signedDate($input)]:[]);
    }

    private static function financialInput(PDO $db,int $id,array $input): array
    {
        $variable=(string)($input['variable'] ?? '');
        $variableSource=DirectoryDetails::text($input,'variable_source',1000);
        if (!isset(self::VARIABLES[$variable]) || ($variable!=='open' && $variableSource===''))
            throw new RuntimeException('Укажите сведения от поставщика или подтверждённое основание по переменным расходам.',422);
        $amount=$variable==='counted'?Service::money($input['variable_amount'] ?? ''):null;
        $source=DirectoryDetails::text($input,'finance_source',1000);
        $debt=DirectoryDetails::text($input,'debt',25);
        $penalty=DirectoryDetails::text($input,'penalty',25);
        if (($debt!=='' || $penalty!=='') && ($source==='' || ($input['confirmed'] ?? '')!=='1'))
            throw new RuntimeException('Укажите основание долга и неустойки и подтвердите сверку.',422);
        $ids=array_values(array_unique(array_map('intval',(array)($input['invoice_ids'] ?? []))));
        sort($ids,SORT_NUMERIC);
        if (array_diff($ids,array_keys(self::invoices($db,$id))))
            throw new RuntimeException('Окончательный счёт должен принадлежать этому договору.',422);
        return ['variable'=>$variable,'variable_source'=>$variableSource,'variable_amount'=>$amount,'finance_source'=>$source,
            'debt'=>$debt===''?null:Service::money($debt),'penalty'=>$penalty===''?null:Service::money($penalty),'invoice_ids'=>$ids];
    }

    private static function store(PDO $db,int $id,array $data): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')
            ->execute([self::NS,(string)$id,Importer::json($data)]);
    }
}
