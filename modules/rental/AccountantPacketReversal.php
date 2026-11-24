<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\Audit;
use Modules\Integrations\{ServerWorkflowMax, WorkflowMaxEvent};
use PDO;
use RuntimeException;

/** Operator recovery for an unsigned activation with an untouched accounting packet. */
final class AccountantPacketReversal
{
    public const ACTION = 'contract.undo-accountant-packet';

    public static function execute(PDO $db, array $actor, array $contract, array $input): array
    {
        Service::roles(['admin']);
        if (PHP_SAPI!=='cli' || !$db->inTransaction() || getenv('APP_STAND') || getenv('IPPHP_ALLOW_EXTERNAL')
            || getenv('IPPHP_RENTAL_MAX_POSTCOMMIT')) {
            throw new RuntimeException('Отмена доступна оператору в локальном режиме без отправок.',403);
        }
        // Held by the operator through commit, so an in-flight MAX send cannot pass the receipt check.
        if ((int)$db->query("SELECT IS_USED_LOCK('ipphp-provider:max')=CONNECTION_ID()")->fetchColumn()!==1) {
            throw new RuntimeException('Требуется блокировка отправок MAX на время операции.',409);
        }
        $reason=DirectoryDetails::text($input,'reason',600);
        $eventId=filter_var($input['event_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        $id=(int)$contract['id'];
        if ($reason==='' || !$eventId || ($input['number']??null)!==$contract['number']) {
            throw new RuntimeException('Укажите точный номер договора, событие подтверждения и причину.',422);
        }
        if ($contract['status']!=='active' || !empty($contract['signed']) || !RegistryState::managed($contract)) {
            throw new RuntimeException('Отмена доступна только сразу после подтверждения неподписанного договора.',409);
        }

        $q=$db->prepare('SELECT id,created_at,source_json FROM rental_events WHERE contract_id=? ORDER BY id DESC LIMIT 1');
        $q->execute([$id]);
        $row=$q->fetch();
        $event=$row?WorkflowEvents::decode($row):null;
        if (($event['event_id']??null)!==$eventId || $event['action']!=='contract.packet'
            || $event['entity_kind']!=='contract' || $event['entity_id']!==$id || $event['contract_id']!==$id
            || $event['event_kind']!=='package' || $event['actor_role']!=='accountant'
            || $event['from_status']!=='accounting' || $event['to_status']!=='active'
            || $event['entity_version']!==(int)$contract['version']
            || ($event['accountant_packet_confirmation']['external_operations']??null)!==AccountantPacketSummary::EXTERNAL_DISABLED) {
            throw new RuntimeException('Последнее событие договора отличается от указанного подтверждения.',409);
        }
        $q=$db->prepare("SELECT id,record_version,raw_sha256 FROM rental_packet_confirmations WHERE record_kind='contract'
            AND record_id=? ORDER BY workflow_revision DESC LIMIT 1");
        $q->execute([$id]);
        $receipt=$q->fetch();
        if (!$receipt || (int)$receipt['record_version']!==(int)$contract['version']) {
            throw new RuntimeException('Не найдена квитанция подтверждения текущей версии.',409);
        }
        $proof=PacketApprovalRecord::read($db,'contract',$id,[
            'binding_id'=>$receipt['id'],'version'=>$receipt['record_version'],'sha256'=>$receipt['raw_sha256'],
        ]);
        $confirmation=$proof['snapshot']['confirmation'];
        if ($confirmation['command_key']!==$event['command_key']
            || $confirmation['actor_id']!==$event['actor_id']
            || $confirmation['command_fingerprint']!==$event['command_fingerprint']
            || $confirmation['result_sha256']!==$event['command_result_sha256']) {
            throw new RuntimeException('Квитанция относится к другому подтверждению.',409);
        }

        self::assertNoEffects($db,$id,$event);
        LegalHandoff::assertCounterparty($db,$contract);
        $q=$db->prepare("SELECT file_id,baseline_hash FROM rental_approvals WHERE entity_type='contract' AND entity_id=? AND role='lawyer'");
        $q->execute([$id]);
        $legal=$q->fetch();
        if (!$legal || $legal['file_id']!==$proof['snapshot']['file']['id']
            || $legal['baseline_hash']!==$proof['snapshot']['legal']['baseline_hash']
            || $legal['baseline_hash']!==Evidence::approvalSignature($db,$id,$legal['file_id'])) {
            throw new RuntimeException('Юридический пакет изменился после подтверждения.',409);
        }

        $db->prepare("UPDATE contracts SET status='accounting',return_to=NULL,reason=NULL,version=version+1 WHERE id=?")->execute([$id]);
        $details=['status'=>'accounting','version'=>(int)$contract['version']+1,
            'original_event_id'=>$eventId,'original_confirmation_id'=>$receipt['id'],'reason'=>$reason];
        Service::event($db,$actor,self::ACTION,'Договор №'.$contract['number'].' возвращён на проверку бухгалтерии. '.$reason,$id,$details);
        Audit::record(self::ACTION,'contract',$id,$contract,$details);
        return ['id'=>$id,'status'=>'accounting','message'=>'Договор возвращён на проверку бухгалтерии.','version'=>$details['version']];
    }

    private static function assertNoEffects(PDO $db,int $id,array $event): void
    {
        $queries=[
            'SELECT 1 FROM invoices WHERE contract_id=? LIMIT 1',
            'SELECT 1 FROM rental_documents WHERE contract_id=? LIMIT 1',
            'SELECT 1 FROM rental_object_holds WHERE contract_id=? LIMIT 1',
            "SELECT 1 FROM rental_files WHERE entity_type='contract' AND entity_id=? AND kind='signed-contract' LIMIT 1",
            "SELECT 1 FROM rental_delivery_intents WHERE entity_type='contract' AND entity_id=? LIMIT 1",
            "SELECT 1 FROM reservations WHERE JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.cancellation.contract_id'))=? LIMIT 1",
            "SELECT 1 FROM audit_log WHERE entity_type='reservation' AND action='reservation.auto-cancel'
                AND JSON_UNQUOTE(JSON_EXTRACT(after_json,'$.contract_id'))=? LIMIT 1",
        ];
        foreach ($queries as $sql) {
            $q=$db->prepare($sql);
            $q->execute([(string)$id]);
            if ($q->fetchColumn()) throw new RuntimeException('Есть связанные документы, расчёты или изменения резервирования. Требуется сверка.',409);
        }
        if (InitialInvoice::receipt($db,$id) || OnecRequest::read($db,$id)) {
            throw new RuntimeException('По договору есть связанные операции со счетами или 1С. Требуется сверка.',409);
        }
        $q=$db->prepare("SELECT 1 FROM messaging_attempts WHERE provider='max' AND request_key IN (?,?,?) LIMIT 1");
        $q->execute([WorkflowMaxEvent::requestKey($event),ServerWorkflowMax::requestKey($event),
            ServerWorkflowMax::requestKey($event,true)]);
        if ($q->fetchColumn()) throw new RuntimeException('По подтверждению уже есть попытка отправки MAX. Требуется сверка.',409);
    }
}
