<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,Auth,DB,View};
use PDO;
use RuntimeException;

final class ExpiryContinuation
{
    public const NS='rental_expiry_continuation';

    public static function origin(?array $record): array
    {
        return $record ? (Importer::decodeExact($record['source_json'])['expiry_origin'] ?? []) : [];
    }

    private static function link(PDO $db,int $id,string $end): array
    {
        $q=$db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $q->execute([self::NS,ExpiryNotice::key($id,$end)]);
        return Importer::decodeExact($q->fetchColumn() ?: '{}');
    }

    public static function links(array $contract,array $notice,bool $write): string
    {
        $id=(int)$contract['id'];
        $end=$notice['old_end'];
        $decision=$notice['response']['decision'];
        $link=self::link(DB::pdo(),$id,$end);
        if ($link) {
            $kind=$link['kind']==='contract'?'contract':'document';
            try {
                if ($kind==='contract') Access::contract($link['id']);
                else Access::document($link['id']);
                return '<div class="demo-row">'.RentalUI::button('Открыть связанный '.($kind==='contract'?'договор':'проект ДС'),
                    '/rental/'.$kind.'?id='.$link['id']).'</div>';
            } catch (RuntimeException $error) {
                if ($error instanceof \PDOException || !in_array($error->getCode(),[403,404],true)) throw $error;
                return '<p class="registry-ui-secondary">Связанная запись сохранена. Её открывает сотрудник с доступом к этому этапу.</p>';
            }
        }
        if (!$write || Auth::user()['role']==='lawyer')
            return '<p class="registry-ui-secondary">Выбранный процесс продолжает менеджер.</p>';
        if ($contract['status']!=='active' || $contract['end_date']!==$end)
            return '<p class="registry-ui-secondary">Срок или состояние договора изменились. Сверьте дальнейшее действие.</p>';
        $params=http_build_query(['expiry_contract'=>$id,'expiry_end'=>$end]);
        [$label,$url]=match($decision) {
            'addendum'=>['Продление и резервирование','/rental/addendum-form?contract_id='.$id.'&'.$params],
            'contract'=>['Подготовить связанный договор','/rental/new-contract?'.$params],
            default=>['Перейти к завершению договора','/rental/action-form?id='.$id.'&action=contract.reconcile'],
        };
        return '<div class="demo-row">'.RentalUI::button($label,$url).'</div>';
    }

    public static function context(PDO $db,?array $record,array $input,string $kind): array
    {
        $old=self::origin($record);
        $id=(int)($old['contract_id'] ?? $input['expiry_contract'] ?? 0);
        if (!$id) return [];
        $end=Service::date((string)($old['end'] ?? $input['expiry_end'] ?? ''));
        $notice=ExpiryNotice::read($db,$id,$end);
        $contract=Access::contract($id);
        if (($notice['state'] ?? null)!=='replied' || ($notice['response']['decision'] ?? '')!==$kind)
            throw new RuntimeException('Ответ по уведомлению не подтверждает этот маршрут.',409);
        $link=self::link($db,$id,$end);
        if ($record && (!$link || (int)$record['id']!==$link['id']))
            throw new RuntimeException('Связь проекта со сроком изменилась.',409);
        if (!$link || $record) {
            if ($contract['status']!=='active' || $contract['end_date']!==$end
                || (int)$contract['party_id']!==$notice['offer']['party_id'])
                throw new RuntimeException('Предыдущий срок или контрагент изменились. Сверьте основание продолжения.',409);
        }
        return ['contract_id'=>$id,'end'=>$end,'offer'=>$notice['offer'],'link'=>$link];
    }

    public static function rows(array $context,string $kind): array
    {
        $offer=$context['offer'];
        $rows=$offer['terms'];
        if (!$rows) throw new RuntimeException('Подтвердите состав аренды на границе сроков.',409);
        foreach ($rows as &$row) {
            if (!$row['start_date'] || $row['end_date']!==$offer['old_end'] || $row['amount']===null)
                throw new RuntimeException('Уточните индивидуальные сроки и цены помещений перед продолжением.',409);
            $row['start_date']=$kind==='addendum'?$row['start_date']:$offer['new_start'];
            $row['end_date']=$offer['new_end'];
            if (count($rows)===1) $row['amount']=$offer['new_amount'];
            elseif ($offer['price_mode']==='percent')
                $row['amount']=Billing::round(bcmul($row['amount'],bcadd('1',bcdiv($offer['percent'],'100',8),8),8));
            elseif ($offer['price_mode']==='amount') $row['amount']=null;
        }
        unset($row);
        return $rows;
    }

    public static function validate(array $context,array $input,string $kind): void
    {
        if (!$context) return;
        $offer=$context['offer'];
        $terms=self::rows($context,$kind);
        $ids=array_map('intval',array_column($terms,'id'));
        $selected=array_values(array_unique(array_map('intval',(array)($input['objects'] ?? []))));
        sort($ids,SORT_NUMERIC);
        sort($selected,SORT_NUMERIC);
        $start=$kind==='contract'?$offer['new_start']:$offer['old_start'];
        if ($ids!==$selected || ($input['start_date'] ?? '')!==$start || ($input['end_date'] ?? '')!==$offer['new_end']
            || ($kind==='contract' && ((int)($input['party_id'] ?? 0)!==$offer['party_id'] || !empty($input['booking_id'])))
            || ($kind==='addendum' && ((int)($input['contract_id'] ?? 0)!==$context['contract_id']
                || ($input['kind'] ?? '')!=='renewal' || ($input['effective_date'] ?? '')!==$offer['new_start'])))
            throw new RuntimeException('Сохраните те же объекты, контрагента и согласованные границы следующего срока.',422);
        if ($kind==='contract' && ($input['intent'] ?? 'draft')==='submit') {
            $prior=Service::get(DB::pdo(),'contracts',$context['contract_id']);
            if (array_diff_key(array_intersect_key(OccupiedBilling::exceptions($prior),array_flip($ids)),OccupiedBilling::draftRows($input))) {
                throw new RuntimeException('Для расчётных объектов подтвердите исключение на новый срок.',409);
            }
        }
        $sum='0.00';
        foreach ($terms as $term) {
            $id=(int)$term['id'];
            if (($input['term_start'][$id] ?? $start)!==$term['start_date']
                || ($input['term_end'][$id] ?? $offer['new_end'])!==$offer['new_end'])
                throw new RuntimeException('Сверьте непрерывные границы срока по каждому помещению.',422);
            $sum=bcadd($sum,Service::money($input['term_amount'][$id] ?? ''),2);
        }
        if (bccomp($sum,$offer['new_amount'],2)!==0)
            throw new RuntimeException('Сумма цен объектов должна совпадать с предложенной ставкой на новый срок.',422);
    }

    public static function bind(PDO $db,array $context,int $id,string $kind): void
    {
        if (!$context || $context['link']) return;
        $origin=['contract_id'=>$context['contract_id'],'end'=>$context['end']];
        $table=$kind==='contract'?'contracts':'rental_documents';
        $db->prepare("UPDATE $table SET source_json=JSON_SET(source_json,'$.expiry_origin',CAST(? AS JSON)) WHERE id=?")
            ->execute([Importer::json($origin),$id]);
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
            ->execute([self::NS,ExpiryNotice::key($origin['contract_id'],$origin['end']),Importer::json(['kind'=>$kind,'id'=>$id])]);
        Audit::record('contract.expiry-linked',$kind==='contract'?'contract':'document',$id,null,$origin);
    }

    public static function beforeActivation(PDO $db,array $contract): void
    {
        if (!$prior=self::origin($contract)) return;
        $old=Service::get($db,'contracts',$prior['contract_id']);
        if ($old['end_date']!==$prior['end'] || $contract['start_date']!==ExpiryNotice::nextDay($prior['end'])
            || (int)$old['party_id']!==(int)$contract['party_id'])
            throw new RuntimeException('Предыдущий срок изменился. Сверьте непрерывность связанного договора.',409);
    }

    public static function editorContext(?array $record,string $kind): array
    {
        return self::context(DB::pdo(),$record,$_GET,$kind);
    }

    public static function editorNote(array $context): string
    {
        return '<span class="registry-ui-modal-help">'.View::e('Основание · уведомление по договору № '.$context['offer']['contract_number']
            .' · ставка следующего срока '.Controller::money($context['offer']['new_amount'])).'</span>';
    }

    public static function fields(array $context): string
    {
        return PremisesActsCard::hidden('expiry_contract',$context['contract_id']).PremisesActsCard::hidden('expiry_end',$context['end']);
    }
}
