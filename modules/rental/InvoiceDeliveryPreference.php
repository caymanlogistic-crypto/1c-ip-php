<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\Auth;
use PDO;
use RuntimeException;

/** The client rule for this document type. It grants no transport capability. */
final class InvoiceDeliveryPreference
{
    public const SAVE = 'invoice.set-recipient-policy';
    public const CHANNELS = ['edo'=>'ЭДО','email'=>'Почта','both'=>'ЭДО и согласованный дубль по почте'];
    private const NS = 'rental_document_delivery';

    public static function read(PDO $db, array $party): array
    {
        $id = (int)$party['id'];
        $query = $db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $query->execute([self::NS,$id.':rent-invoice']);
        $json = $query->fetchColumn();
        $rule = $json === false ? [] : Importer::decodeExact($json);
        $mode = $rule['mode'] ?? '';
        $email = in_array($mode,['email','both'],true) ? RecipientContext::clients($party) : [];
        $edo = in_array($mode,['edo','both'],true) ? ($rule['edo'] ?? '') : '';
        $questions = [];
        $emailEnvelope = [];
        $managerCopy = '';
        if ($mode === 'email' && $email) {
            try {
                $recipient = RecipientContext::resolve($db,$party);
                $emailEnvelope = $recipient['recipients'];
                $managerCopy = $recipient['managerEmail'];
            } catch (DeliveryContextException $error) {
                $questions[] = $error->getMessage();
            }
        }
        if (!isset(self::CHANNELS[$mode])) $questions[] = 'Согласуйте способ доставки счёта за аренду для этого клиента.';
        if (in_array($mode,['email','both'],true) && !$email) $questions[] = 'Укажите получателя счёта в документных контактах клиента.';
        if (in_array($mode,['edo','both'],true) && $edo === '') $questions[] = 'Подтвердите получателя счёта в ЭДО.';
        return ['mode'=>$mode,'email'=>$email,'edo'=>$edo,'email_envelope'=>$emailEnvelope,'manager_copy'=>$managerCopy,
            'questions'=>$questions,'rule'=>$rule];
    }

    public static function save(PDO $db,array $actor,array $contract,array $input): array
    {
        RegularInvoice::requireAccess(2);
        $party = Service::get($db,'parties',(int)$contract['party_id']);
        $before = self::read($db,$party)['rule'];
        if (($input['rule_revision'] ?? '') !== hash('sha256',Importer::canonicalJson($before)))
            throw new RuntimeException('Настройка клиента изменилась. Откройте форму заново.',409);
        $mode = (string)($input['mode'] ?? '');
        $edo = DirectoryDetails::text($input,'edo',160);
        $reason = DirectoryDetails::text($input,'reason',400);
        if (!isset(self::CHANNELS[$mode]) || ($input['confirmed'] ?? '') !== '1' || $reason === '')
            throw new RuntimeException('Подтвердите порядок доставки счёта по договорённости с клиентом.',422);
        $rule = ['mode'=>$mode,'edo'=>$mode === 'email' ? '' : $edo,'reason'=>$reason,
            'actor_id'=>(int)$actor['id'],'command_key'=>$input['command_key']];
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')->execute([self::NS,$party['id'].':rent-invoice',Importer::json($rule)]);
        \App\Audit::record(self::SAVE,'party',$party['id'],$before,$rule);
        return ['id'=>$contract['id'],'message'=>'Настройка доставки счёта сохранена.'];
    }

    public static function emailQuestion(PDO $db,array $invoice,?array $party = null): ?string
    {
        $proof = json_decode($invoice['source_json'],true)['regular_invoice'] ?? null;
        if (!$proof) return null;
        $saved = $proof['delivery'];
        if (($saved['mode'] ?? '') !== 'email' || empty($saved['email']) || empty($saved['email_envelope'])) {
            return 'Штатная почта доступна после подтверждения единственного канала «Почта» и получателей для этого счёта.';
        }
        if ($party === null) {
            $query = $db->prepare('SELECT p.* FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.id=?');
            $query->execute([$invoice['contract_id']]);
            $party = $query->fetch();
        }
        if (!$party) return 'Уточните получателей счёта.';
        $current = self::read($db,$party);
        $addresses = static function(array $values): array {
            $values = array_map(mb_strtolower(...),$values);
            sort($values,SORT_STRING);
            return $values;
        };
        if ($current['mode'] !== 'email' || $addresses($saved['email']) !== $addresses($current['email'])
            || $addresses($saved['email_envelope']) !== $addresses($current['email_envelope']))
            return 'После сохранения счёта изменились способ доставки или получатели. Нужна сверка.';
        return null;
    }

    public static function status(array $invoice, ?array $intent): string
    {
        $receipt = $intent ? json_decode($intent['receipt_json'] ?? 'null',true) : null;
        if ($intent && $intent['status'] === 'accepted' && !empty($receipt['accepted']) && $invoice['sent']) {
            return (int)$intent['entity_version']+1 === (int)$invoice['version']
                ? 'Транспорт подтвердил отправку по почте'
                : 'Транспорт подтвердил отправку прежней версии по почте';
        }
        return 'Счёт сохранён. Отправка не подтверждена';
    }
}
