<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\Audit;
use PDO;
use RuntimeException;

/** Retire one local, unsigned test case and retain its records for audit. */
final class LocalTestCycleReset
{
    public const ACTION='workspace.retire-test-cycle';

    public static function execute(PDO $db,array $actor,array $input): array
    {
        ZeroPoint::requireAdmin();
        AccountantPacketReversal::requireLocalOperator($db);
        $contract=Service::get($db,'contracts',(int)($input['id']??0));
        $party=Service::get($db,'parties',(int)($input['party_id']??0));
        Service::version($contract,$input);
        Service::version($party,['version'=>$input['party_version']??null]);
        $id=(int)$contract['id'];
        $partyId=(int)$party['id'];
        $eventId=(int)($input['event_id']??0);
        $reason=DirectoryDetails::text($input,'reason',600);
        if ($id<1 || $partyId!==(int)$contract['party_id'] || ($input['number']??null)!==$contract['number']
            || ($input['inn']??null)!==$party['inn'] || !$party['inn'] || !$eventId || $reason==='') {
            throw new RuntimeException('Укажите точный договор, контрагента, событие подтверждения и причину.',422);
        }
        // Only a local card created in ERP, with no linked source identity.
        if (Importer::decodeExact($party['source_json']) || ContractDraft::deleted($contract)) {
            throw new RuntimeException('Карточка уже скрыта или требует отдельной сверки с источником.',409);
        }
        [$event,$receipt]=AccountantPacketReversal::currentConfirmation($db,$contract,$eventId);
        AccountantPacketReversal::assertNoEffects($db,$id,$event,allowFinalMax:true);
        self::assertIsolated($db,$id,$partyId);

        $key=(string)$input['command_key'];
        ContractHistory::remember($db,$contract,$actor,$key);
        $marker=['key'=>$key,'at'=>date(DATE_ATOM),'actor_id'=>(int)$actor['id'],
            'reason'=>$reason,'contract_id'=>$id,'party_id'=>$partyId,'original_event_id'=>$eventId,
            'original_confirmation_id'=>$receipt['id']];
        $raw=Importer::json($marker);
        $db->prepare("UPDATE contracts SET status='cancelled',return_to=NULL,reason=?,version=version+1,
            source_json=JSON_SET(source_json,'$.workspace_cleared',CAST(? AS JSON)) WHERE id=?")->execute([$reason,$raw,$id]);
        $db->prepare("UPDATE parties SET version=version+1,
            source_json=JSON_SET(source_json,'$.workspace_cleared',CAST(? AS JSON)) WHERE id=?")->execute([$raw,$partyId]);
        $message='Договор и тестовая карточка контрагента убраны из рабочего списка.';
        Service::event($db,$actor,self::ACTION,$message,$id,$marker);
        Audit::record(self::ACTION,'contract',$id,$contract,$marker+['status'=>'cancelled']);
        Audit::record(self::ACTION,'party',$partyId,$party,$marker);
        return ['id'=>$id,'status'=>'cancelled','message'=>$message,'party_id'=>$partyId];
    }

    private static function assertIsolated(PDO $db,int $id,int $party): void
    {
        $checks=[
            ['SELECT 1 FROM contracts WHERE party_id=? AND id<>? LIMIT 1',[$party,$id]],
            ['SELECT 1 FROM payments WHERE party_id=? LIMIT 1',[$party]],
            ['SELECT 1 FROM reservations WHERE party_id=? OR contract_id=? LIMIT 1',[$party,$id]],
            ["SELECT 1 FROM rental_source_bindings WHERE (entity_type='party' AND entity_id=?)
                OR (entity_type='contract' AND entity_id=?) LIMIT 1",[$party,$id]],
        ];
        foreach ($checks as [$sql,$args]) {
            $q=$db->prepare($sql);
            $q->execute($args);
            if ($q->fetchColumn()) throw new RuntimeException('Есть связанные рабочие записи. Требуется отдельная сверка.',409);
        }
    }
}
