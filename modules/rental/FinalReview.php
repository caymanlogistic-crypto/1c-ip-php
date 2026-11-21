<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,Auth};
use PDO;
use RuntimeException;

final class FinalReview
{
    public const RETURN_FINANCE='closing.return-finance';
    public const RETURN_LEGAL='closing.return-legal';
    public const RESUBMIT='closing.resubmit';
    public const ACTIONS=[self::RETURN_FINANCE,self::RETURN_LEGAL,self::RESUBMIT];
    public const LABELS=['finance'=>'Бухгалтер','legal'=>'Юрист'];
    private const NS='rental_closing_review';

    public static function ready(array $contract): bool
    {
        return in_array($contract['status'],['reconciling','ended'],true);
    }

    public static function reviewer(string $kind): string
    {
        return match($kind) {
            'finance'=>'accountant','legal'=>'lawyer',default=>throw new RuntimeException('Неизвестная проверка.',422),
        };
    }

    public static function marker(PDO $db,int $id,string $kind): array
    {
        return FinalSettlement::fact($db,self::NS,$id.':'.$kind);
    }

    public static function approvals(PDO $db,int $id): array
    {
        $q=$db->prepare('SELECT r.*,u.name actor_name FROM rental_reviews r LEFT JOIN users u ON u.id=r.actor_id WHERE r.contract_id=?');
        $q->execute([$id]);
        return array_column($q->fetchAll(),null,'kind');
    }

    public static function current(PDO $db,array $contract,string $kind,?array $row,?string $signature=null): bool
    {
        if (!$row || in_array(self::marker($db,(int)$contract['id'],$kind)['state'] ?? '',['returned','reassigned'],true)) return false;
        $signature??=Evidence::signature($db,(int)$contract['id']);
        return hash_equals($signature,$row['baseline_hash']);
    }

    public static function assertApproval(PDO $db,array $contract,array $actor,string $kind): void
    {
        Access::contract((int)$contract['id'],2);
        $marker=self::marker($db,(int)$contract['id'],$kind);
        if (($marker['state'] ?? '')==='returned')
            throw new RuntimeException('Сначала менеджер должен передать исправления на повторную проверку.',409);
        if (($marker['state'] ?? '')==='reassigned' && $actor['role']!=='admin' && $marker['reviewer_id']!==(int)$actor['id'])
            throw new RuntimeException('Повторная проверка назначена сотруднику, который вернул задачу.',403);
        FinalSettlement::assertReview($db,$contract,$kind);
    }

    public static function approved(PDO $db,array $contract,array $actor,string $kind,?array $previous): void
    {
        $id=(int)$contract['id'];
        $old=self::marker($db,$id,$kind);
        if ($old) self::write($db,$id,$kind,array_replace($old,['state'=>'approved']));
        Audit::record('closing.review-approved','contract',$id,$previous,['kind'=>$kind,'reviewer'=>$actor['name'],
            'when'=>date(DATE_ATOM),'state'=>'approved']);
    }

    public static function clear(PDO $db,int $id,string $kind): void
    {
        $field=$kind==='finance'?'finance_review':'legal_review';
        $db->prepare('UPDATE contracts SET '.$field.'=0 WHERE id=?')->execute([$id]);
        $db->prepare('DELETE FROM rental_reviews WHERE contract_id=? AND kind=?')->execute([$id,$kind]);
        if ($kind==='finance') {
            $db->prepare("UPDATE invoices SET control_closed=0 WHERE contract_id=? AND status='active' AND control_closed=1
                AND COALESCE(kind,'')<>'Обеспечительный платёж'")->execute([$id]);
        }
    }

    public static function history(PDO $db,int $id): array
    {
        $q=$db->prepare("SELECT after_json FROM audit_log WHERE entity_type='contract' AND entity_id=?
            AND action IN ('closing.return-finance','closing.return-legal','closing.resubmit','closing.review-approved') ORDER BY id DESC");
        $q->execute([(string)$id]);
        return array_map(Importer::decodeExact(...),$q->fetchAll(PDO::FETCH_COLUMN));
    }

    public static function execute(PDO $db,array $actor,string $action,array $input): array
    {
        $id=(int)($input['id'] ?? 0);
        $kind=match($action) {
            self::RETURN_FINANCE=>'finance',self::RETURN_LEGAL=>'legal',default=>(string)($input['review_kind'] ?? ''),
        };
        $role=$action===self::RESUBMIT?'manager':self::reviewer($kind);
        Service::roles([$role]);
        Access::contract($id,2);
        $contract=Service::get($db,'contracts',$id);
        Service::version($contract,$input);
        if (!self::ready($contract)) throw new RuntimeException('Договор не на финальной сверке.',409);
        self::reviewer($kind);
        $old=self::marker($db,$id,$kind);
        $comment=DirectoryDetails::text($input,'comment',1500);
        if ($action===self::RESUBMIT) {
            if (($old['state'] ?? '')!=='returned' || ($input['confirmed'] ?? '')!=='1')
                throw new RuntimeException('Подтвердите, что внесли исправления по этому возврату.',409);
            $next=array_replace($old,['state'=>'reassigned','resubmitted_by'=>$actor['name'],'resubmitted_at'=>date(DATE_ATOM),'response'=>$comment]);
            $message='Исправления переданы сотруднику на повторную проверку.';
        } else {
            if (($old['state'] ?? '')==='returned') throw new RuntimeException('Задача уже возвращена менеджеру.',409);
            if (($old['state'] ?? '')==='reassigned' && $actor['role']!=='admin' && $old['reviewer_id']!==(int)$actor['id'])
                throw new RuntimeException('Повторная проверка назначена другому сотруднику.',403);
            $previous=self::approvals($db,$id)[$kind] ?? null;
            $next=['kind'=>$kind,'state'=>'returned','reviewer_id'=>(int)$actor['id'],'reviewer'=>$actor['name'],
                'when'=>date(DATE_ATOM),'comment'=>$comment,'previous_review'=>$previous,
                'previous_state'=>self::current($db,$contract,$kind,$previous)?'Подтверждено':(($old['state'] ?? '')==='reassigned'
                    ?'На повторной проверке':'Ожидалось подтверждение')];
            self::clear($db,$id,$kind);
            $message='Проверка возвращена менеджеру на доработку.';
        }
        self::write($db,$id,$kind,$next);
        $db->prepare('UPDATE contracts SET version=version+1 WHERE id=?')->execute([$id]);
        Audit::record($action,'contract',$id,$old ?: null,$next);
        return ['id'=>$id,'message'=>$message];
    }

    private static function write(PDO $db,int $id,string $kind,array $data): void
    {
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')->execute([self::NS,$id.':'.$kind,Importer::json($data)]);
    }
}
