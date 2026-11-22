<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit, Auth, PagePolicy};
use Modules\Integrations\{Config, Support, YakovlevImport};
use PDO;
use RuntimeException;

final class InvoiceContractAssignment
{
    public const SAVE = 'invoice.assign-source-contract';
    private const NAMESPACE = 'rental_yakovlev_invoice_assignment';
    private const STATUSES = ['active','reconciling','ended','closed'];
    public const COLLECTION = 'Document_СчетНаОплатуПокупателю';

    public static function canEdit(array $user): bool
    {
        return MonthlyRentReconciliationPage::available($user) && Auth::can('finance.write')
            && PagePolicy::level('rental.invoice-list', $user) >= 2;
    }

    public static function index(PDO $db): array
    {
        $query = $db->prepare('SELECT source_key,fact_json FROM rental_facts WHERE namespace=? AND operative=1');
        $query->execute([self::NAMESPACE]);
        return array_map(Support::decode(...), $query->fetchAll(PDO::FETCH_KEY_PAIR));
    }

    public static function contractId(array $archive, ?array $assignment): ?int
    {
        return $assignment && ($assignment['sha256'] ?? null) === $archive['raw_sha256']
            ? (int)$assignment['contract_id'] : null;
    }

    public static function assignedContract(PDO $db, array $archive): ?int
    {
        $query = $db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $query->execute([self::NAMESPACE,$archive['id']]);
        $value = $query->fetchColumn();
        return self::contractId($archive, $value === false ? null : Support::decode($value));
    }

    public static function archive(PDO $db, string $id, bool $lock = false): array
    {
        $query = $db->prepare("SELECT * FROM external_documents WHERE id=? AND provider=? AND account_id='invoices'"
            .($lock ? ' FOR UPDATE' : ''));
        $query->execute([$id,YakovlevImport::PROVIDER]);
        return $query->fetch() ?: throw new RuntimeException('Счёт в архиве 1С не найден.',404);
    }

    public static function partyRef(array $party): ?string
    {
        $source = Support::decode($party['party_source'] ?? '{}');
        return $source['source_party_guid'] ?? $source['Ref_Key'] ?? null;
    }

    public static function options(PDO $db, array $original): array
    {
        $owner = $original['Контрагент_Key'] ?? $original['Контрагент'] ?? null;
        if (!$owner) return [];
        $rows = $db->query("SELECT c.id,c.number,c.version,c.start_date,c.end_date,p.name,p.source_json party_source
            FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.status IN ('active','reconciling','ended','closed')
            AND p.blocked=0 ORDER BY c.document_date DESC,c.id DESC")->fetchAll();
        return array_values(array_filter($rows, static fn(array $lease): bool=>self::partyRef($lease)===$owner));
    }

    public static function invoiceMatches(PDO $db, string $ref, bool $lock = false): array
    {
        $query = $db->prepare("SELECT i.* FROM invoices i WHERE JSON_UNQUOTE(JSON_EXTRACT(i.source_json,'$.Ref_Key'))=?
            OR EXISTS (SELECT 1 FROM rental_source_bindings b WHERE b.entity_type='invoice' AND b.entity_id=i.id
                AND b.collection=? AND b.relation_kind='invoice_control' AND b.source_id=?) ORDER BY i.id"
            .($lock ? ' FOR UPDATE' : ''));
        $query->execute([$ref,self::COLLECTION,$ref]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    public static function save(PDO $db, array $actor, array $input): array
    {
        if (!self::canEdit($actor)) throw new RuntimeException('Привязать счёт может бухгалтер с правом редактирования.',403);
        $documentId = (string)($input['document_id'] ?? '');
        $reason = trim((string)($input['reason'] ?? ''));
        if (!preg_match('/\A([1-9]\d*):([1-9]\d*)\z/D',(string)($input['lease'] ?? ''),$choice)
            || $reason === '' || mb_strlen($reason)>500) {
            throw new RuntimeException('Выберите договор и укажите основание привязки.',422);
        }
        $archive = self::archive($db,$documentId,true);
        if ((int)$archive['version'] !== (int)($input['document_version'] ?? 0) || $archive['status'] !== 'Проведён') {
            throw new RuntimeException('Счёт изменился или не проведён в 1С. Откройте его заново.',409);
        }
        $store = Config::store();
        $document = Support::decode($store->read($archive['raw_path'],$archive['raw_sha256']));
        $source = $document['fields']['record'];
        if (!MonthlyRentReconciliation::rentLines($source)) {
            throw new RuntimeException('В помесячной сверке доступны счета за аренду.',422);
        }
        $query = $db->prepare('SELECT c.*,p.blocked,p.source_json party_source
            FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.id=? FOR UPDATE');
        $query->execute([(int)$choice[1]]);
        $lease = $query->fetch();
        $owner = $source['Контрагент_Key'] ?? $source['Контрагент'] ?? null;
        if (!$lease || (int)$lease['version'] !== (int)$choice[2] || $lease['blocked']
            || !in_array($lease['status'],self::STATUSES,true) || !$owner || $owner !== self::partyRef($lease)) {
            throw new RuntimeException('Договор изменился или принадлежит другому контрагенту.',409);
        }
        $existing = self::invoiceMatches($db,$source['Ref_Key'],true);
        if (count($existing)>1 || ($existing && (int)$existing[0]['contract_id'] !== (int)$lease['id'])) {
            throw new RuntimeException('Счёт уже учтён в ERP по другой связи. Сверьте его карточку.',409);
        }
        $query = $db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1 FOR UPDATE');
        $query->execute([self::NAMESPACE,$documentId]);
        $before = Support::decode($query->fetchColumn() ?: '{}');
        $revision = (int)($before['revision'] ?? 0);
        if ($revision !== (int)($input['assignment_revision'] ?? -1)) {
            throw new RuntimeException('Привязка уже изменена. Откройте счёт заново.',409);
        }
        $assignment = ['contract_id'=>(int)$lease['id'],'sha256'=>$archive['raw_sha256'],'reason'=>$reason,'revision'=>$revision+1];
        $db->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json),operative=1")
            ->execute([self::NAMESPACE,$documentId,Support::json($assignment)]);
        Audit::record(self::SAVE,'external-document',$documentId,$before,$assignment);
        $payment = (new YakovlevRentalSync($db,$store))->accountAssignedInvoice($archive,$document);
        return ['contract_id'=>(int)$lease['id'],'payment'=>$payment];
    }
}
