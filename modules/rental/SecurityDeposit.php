<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Audit,Auth,PagePolicy};
use PDO;
use RuntimeException;

final class SecurityDeposit
{
    public const KIND='Обеспечительный платёж';
    public const BASIS='closing.security-basis';
    public const PLAN='closing.security-plan';
    public const HOLD='closing.security-hold';
    public const RETAIN='closing.security-retain';
    public const RETURNED='closing.security-returned';
    public const TRANSFER='closing.security-transfer';
    public const TITLES=[
        self::BASIS=>'Подтвердить основание по обеспечению',self::PLAN=>'Сохранить расчёт возврата',
        self::HOLD=>'Указать остаток до сверки',self::RETAIN=>'Учесть согласованное удержание',
        self::RETURNED=>'Учесть подтверждённый возврат',self::TRANSFER=>'Перенести обеспечение по D20',
    ];
    public const MODES=['full'=>'Полный возврат без претензий','claims'=>'Претензии или открытая сверка','transfer'=>'Перенос по подписанному D20'];
    private const BASIS_NS='rental_security_basis';
    private const PLAN_NS='rental_security_plan';
    private const LEDGER='rental_security_movements';

    public static function role(string $action): string
    {
        return $action===self::BASIS?'lawyer':'accountant';
    }

    public static function access(int $id,string $action): array
    {
        $role=self::role($action);
        Service::roles([$role]);
        Auth::requirePermission($role==='lawyer'?'rental.approve.legal':'finance.write');
        PagePolicy::requireCommand($action);
        $contract=Access::contract($id,2);
        if (!in_array($contract['status'],['reconciling','ended','closed'],true))
            throw new RuntimeException('Операция по обеспечению доступна из финальной сверки прежнего договора.',409);
        return $contract;
    }

    public static function obligation(PDO $db,int $contract): ?array
    {
        $q=$db->prepare("SELECT * FROM invoices WHERE contract_id=? AND kind=? AND status<>'revoked' ORDER BY id");
        $q->execute([$contract,self::KIND]);
        $rows=$q->fetchAll();
        if (count($rows)!==1 || $rows[0]['period']!=='Обеспечение' || $rows[0]['amount']===null
            || bccomp($rows[0]['amount'],'0',2)<=0 || $rows[0]['status']!=='active') return null;
        return $rows[0];
    }

    public static function movements(PDO $db,int $id): array
    {
        $q=$db->prepare("SELECT fact_json FROM rental_facts WHERE namespace=? AND operative=1 AND
            (JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.contract'))=? OR JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.target'))=?)
            ORDER BY id");
        $q->execute([self::LEDGER,$id,$id]);
        return array_map(Importer::decodeExact(...),$q->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function read(PDO $db,array $contract): array
    {
        $id=(int)$contract['id'];
        $invoice=self::obligation($db,$id);
        $moves=self::movements($db,$id);
        $incoming=$invoice?SecurityDepositEvidence::incoming($db,$invoice,$contract):['rows'=>[],'received'=>'0.00','unverified'=>true];
        $held=$retained=$returned=$credited=$out='0.00';
        foreach ($moves as $move) {
            if (($move['target'] ?? null)===$id) {
                $credited=bcadd($credited,$move['amount'],2);
                continue;
            }
            switch ($move['kind']) {
                case self::HOLD: $held=$move['amount']; break;
                case self::RETAIN:
                    $retained=bcadd($retained,$move['amount'],2);
                    $held=bcsub($held,$move['from_hold'],2);
                    break;
                case self::RETURNED: $returned=bcadd($returned,$move['amount'],2); break;
                case self::TRANSFER: $out=bcadd($out,$move['amount'],2); break;
            }
        }
        $limit=bcadd($incoming['received'],$credited,2);
        $spent=bcadd(bcadd($retained,$returned,2),$out,2);
        $free=bcsub(bcsub($limit,$spent,2),$held,2);
        $basis=self::basis($db,$id);
        $plan=self::savedPlan($db,$id);
        $question=!$invoice?'Сверьте единственное действующее обязательство по обеспечению и исходный счёт.':
            ($incoming['unverified']?'Сверьте источник поступления и распределение по счёту обеспечения.':
            (bccomp($free,'0',2)<0?'Сверьте исходный приход с уже учтёнными операциями.':null));
        $due=$invoice && !$incoming['unverified'] ? self::payable($db,$invoice,$invoice['paid'],$credited) : null;
        return $incoming+compact('invoice','moves','held','retained','returned','credited','out','spent','free','due','basis','plan','question');
    }

    public static function payable(PDO $db,array $invoice,string $paid,?string $credited=null): string
    {
        $left=bcsub($invoice['amount'],$paid,2);
        if ($invoice['kind']!==self::KIND) return $left;
        $credited??=self::invoiceCredit($db,$invoice);
        return bcsub($left,$credited,2);
    }

    public static function invoiceCredit(PDO $db,array $invoice): string
    {
        if ($invoice['kind']!==self::KIND) return '0.00';
        $amount='0.00';
        foreach (self::movements($db,(int)$invoice['contract_id']) as $move) {
            if (($move['target_invoice'] ?? null)===(int)$invoice['id']) $amount=bcadd($amount,$move['amount'],2);
        }
        return $amount;
    }

    public static function protectInvoice(PDO $db,array $invoice): void
    {
        if ($invoice['kind']===self::KIND && self::movements($db,(int)$invoice['contract_id']))
            throw new RuntimeException('По обеспечению уже есть операции. Исходный счёт и проведённая оплата сохранены для сверки.',409);
    }

    public static function basis(PDO $db,int $id): array
    {
        return FinalSettlement::fact($db,self::BASIS_NS,(string)$id);
    }

    public static function savedPlan(PDO $db,int $id): array
    {
        return FinalSettlement::fact($db,self::PLAN_NS,(string)$id);
    }

    public static function proposal(PDO $db,array $contract,array $ledger): array
    {
        $basis=$ledger['basis'];
        if (($basis['mode'] ?? '')!=='full') return [];
        try {
            $file=SecurityDepositEvidence::agreement($db,$contract);
            if (!self::same($file,$basis['document'])) throw new RuntimeException('Подтвердите актуальное соглашение без претензий.',409);
            FinalSettlement::assertReview($db,$contract,'finance');
            if ($ledger['question']) throw new RuntimeException($ledger['question'],409);
            if (bccomp($ledger['held'],'0',2)>0 || bccomp($ledger['retained'],'0',2)>0 || bccomp($ledger['out'],'0',2)>0)
                throw new RuntimeException('По обеспечению есть удержание, резерв или перенос. Сверьте применимость полного возврата.',409);
        } catch (RuntimeException $error) {
            if ($error instanceof \PDOException) throw $error;
            return ['question'=>$error->getMessage()];
        }
        $signature=SecurityDepositEvidence::returnSignature($db,$contract);
        if (isset($signature['question'])) return $signature;
        $basisProof=$signature+['agreement_id'=>$file['id'],'agreement_sha256'=>$file['sha256']];
        return ['amount'=>$ledger['free'],'evidence'=>$basisProof]
            +SecurityDepositEvidence::returnDeadline($signature,$file['sha256'],$ledger['plan']);
    }

    public static function execute(PDO $db,array $actor,string $action,array $input): array
    {
        $id=(int)($input['id'] ?? 0);
        self::access($id,$action);
        $basis=self::basis($db,$id);
        $target=$action===self::BASIS?(int)($input['target'] ?? 0):($action===self::TRANSFER?(int)($basis['target'] ?? 0):0);
        if ($action===self::TRANSFER && isset($input['target']) && (int)$input['target']!==$target)
            throw new RuntimeException('Перенос доступен только на договор, подтверждённый в подписанном D20.',409);
        if ($target===$id) throw new RuntimeException('Укажите другой связанный договор.',422);
        $ids=$target?[$id,$target]:[$id];
        sort($ids,SORT_NUMERIC);
        $contracts=[];
        foreach ($ids as $lockId) {
            if ($lockId!==$id) Access::contract($lockId,2);
            $contracts[$lockId]=Service::get($db,'contracts',$lockId);
        }
        $contract=$contracts[$id];
        Service::version($contract,$input);
        $reason=DirectoryDetails::text($input,'reason',1000);
        if ($reason==='' || ($input['confirmed'] ?? '')!=='1')
            throw new RuntimeException('Укажите основание и подтвердите действие.',422);
        $by=['actor_id'=>(int)$actor['id'],'actor'=>$actor['name'],'role'=>$actor['role'],'recorded_at'=>date(DATE_ATOM),'reason'=>$reason];
        if ($action===self::BASIS) {
            $mode=(string)($input['mode'] ?? '');
            if (!isset(self::MODES[$mode]) || ($mode==='transfer')!==($target>0))
                throw new RuntimeException('Выберите применимый вид основания и новый договор только для переноса.',422);
            $document=SecurityDepositEvidence::agreement($db,$contract);
            $reference=$target?['source_number'=>DirectoryDetails::text($input,'source_number',100),
                'target_number'=>DirectoryDetails::text($input,'target_number',100)]:[];
            if ($target) SecurityDepositEvidence::successor($db,$contract,$contracts[$target],$reference);
            $amount=$mode==='full'?null:Service::money($input['amount'] ?? '');
            if ($target && bccomp($amount,'0',2)<=0) throw new RuntimeException('Укажите сумму из подписанного D20.',422);
            $value=compact('mode','target','amount','document','reference')+$by;
            $details=compact('mode','target','amount','document','reference','reason');
            if (self::same(array_intersect_key($basis,$details),$details)) return self::already($id);
            self::save($db,self::BASIS_NS,(string)$id,$value);
            return self::record($db,$id,$action,$basis,$value);
        }
        if (!$basis || !self::same(SecurityDepositEvidence::agreement($db,$contract),$basis['document']))
            throw new RuntimeException('Наиль должен подтвердить текущее подписанное основание по обеспечению.',409);
        $invoiceIds=[];
        foreach ($contracts as $record) {
            if ($invoice=self::obligation($db,(int)$record['id'])) $invoiceIds[]=(int)$invoice['id'];
        }
        sort($invoiceIds,SORT_NUMERIC);
        foreach ($invoiceIds as $invoiceId) Service::get($db,'invoices',$invoiceId);
        $ledger=self::read($db,$contract);
        if ($ledger['question']) throw new RuntimeException($ledger['question'],409);
        if ($action===self::PLAN) {
            $plan=self::proposal($db,$contract,$ledger);
            if (empty($plan['deadline']['date']) || bccomp($plan['amount'] ?? '0','0',2)<=0)
                throw new RuntimeException($plan['question'] ?? $plan['deadline']['question'] ?? 'Нет подтверждённого остатка к возврату.',409);
            if ($plan['saved'] && ($ledger['plan']['amount'] ?? '')===$plan['amount']) return self::already($id);
            $value=array_intersect_key($plan,array_flip(['evidence','deadline','amount']))+$by;
            self::save($db,self::PLAN_NS,(string)$id,$value);
            return self::record($db,$id,$action,$ledger['plan'],$value);
        }
        $event=['kind'=>$action,'contract'=>$id,'invoice'=>(int)$ledger['invoice']['id'],'document'=>$basis['document'],
            'date'=>$basis['document']['date']]+$by;
        $key=(string)$input['command_key'];
        $amount=$basis['amount'];
        if ($action===self::HOLD || $action===self::RETAIN) {
            if ($basis['mode']!=='claims') throw new RuntimeException('Удержание и резерв требуют согласованного основания по претензиям.',409);
            $amount=Service::money($input['amount'] ?? '');
            if ($action===self::HOLD) {
                if ($amount===$ledger['held']) return self::already($id);
                if (bccomp($amount,bcadd($ledger['free'],$ledger['held'],2),2)>0)
                    throw new RuntimeException('Резерв превышает доступную сумму.',409);
            } else {
                $key='retain:'.$id.':'.$basis['document']['sha256'];
                $held=($input['from_hold'] ?? '')==='1'?$amount:'0.00';
                if (bccomp($amount,'0',2)<=0 || bccomp(bcadd($ledger['retained'],$amount,2),$basis['amount'],2)>0
                    || bccomp($held,$ledger['held'],2)>0 || bccomp(bcsub($amount,$held,2),$ledger['free'],2)>0)
                    throw new RuntimeException('Уточните сумму удержания по соглашению и доступный остаток.',409);
                $event['from_hold']=$held;
            }
        } elseif ($action===self::RETURNED) {
            $document=SecurityDepositEvidence::outgoing($db,$contract,(string)($input['bank_document'] ?? ''));
            $key='refund:'.hash('sha256',$document['source_id']);
            if (FinalSettlement::fact($db,self::LEDGER,$key)) throw new RuntimeException('Это списание уже учтено в обеспечении.',409);
            if ($basis['mode']==='full') {
                $proposal=self::proposal($db,$contract,$ledger);
                if (!isset($proposal['amount'])) throw new RuntimeException($proposal['question'] ?? 'Нет подтверждённого основания возврата.',409);
            } elseif ($basis['mode']!=='claims') throw new RuntimeException('Для возврата нужно отдельное подтверждённое основание.',409);
            $amount=$document['amount'];
            if ($document['date']<$basis['document']['date'] || (isset($proposal) && $document['date']<$proposal['evidence']['act_signed_on'])
                || bccomp($amount,$ledger['free'],2)>0)
                throw new RuntimeException('Сверьте дату списания и доступное обеспечение. Ранее учтённые суммы сохранены.',409);
            $event['bank_document']=$document;
            $event['date']=$document['date'];
        } else {
            if ($basis['mode']!=='transfer' || !$target) throw new RuntimeException('Нужно согласованное основание D20.',409);
            SecurityDepositEvidence::successor($db,$contract,$contracts[$target],$basis['reference']);
            $destination=self::read($db,$contracts[$target]);
            if (!$destination['invoice'] || $destination['question'] || $destination['due']===null
                || bccomp($amount,$destination['due'],2)>0 || bccomp($amount,$ledger['free'],2)>0)
                throw new RuntimeException('Сумма D20 превышает остаток прежнего или обязательство нового договора либо требуется сверка поступлений.',409);
            $key='transfer:'.$id.':'.$basis['document']['sha256'];
            $event+=['target'=>$target,'target_invoice'=>(int)$destination['invoice']['id'],'reference'=>$basis['reference']];
        }
        if (FinalSettlement::fact($db,self::LEDGER,$key)) throw new RuntimeException('Операция по этому основанию уже учтена.',409);
        $event['amount']=$amount;
        $event['receipts']=array_values(array_filter($ledger['rows'],static fn(array $row)=>$row['proof']!==null));
        self::save($db,self::LEDGER,$key,$event);
        if ($target) {
            $db->prepare('UPDATE contracts SET version=version+1 WHERE id=?')->execute([$target]);
            Audit::record($action,'contract',$target,null,$event);
        }
        return self::record($db,$id,$action,null,$event);
    }

    private static function record(PDO $db,int $id,string $action,?array $before,array $value): array
    {
        $db->prepare('UPDATE contracts SET version=version+1 WHERE id=?')->execute([$id]);
        Audit::record($action,'contract',$id,$before,$value);
        return ['id'=>$id,'message'=>'Операция по обеспечению учтена.'];
    }

    private static function already(int $id): array
    {
        return ['id'=>$id,'message'=>'Сведения уже сохранены.'];
    }

    private static function same(array $left,array $right): bool
    {
        return Importer::canonicalJson($left)===Importer::canonicalJson($right);
    }

    private static function save(PDO $db,string $namespace,string $key,array $data): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')
            ->execute([$namespace,$key,Importer::json($data)]);
    }
}
