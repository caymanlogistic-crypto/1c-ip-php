<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\PagePolicy;
use Modules\Integrations\CaptureReader;
use PDO;
use RuntimeException;

/** Normal accountant confirmation. Reading this class never creates approval. */
final class NativePacket
{
    public const PREVIEW_CHANGED = 'NATIVE_PACKET_PREVIEW_CHANGED';

    private static function row(PDO $p, string $sql, array $args = []): array
    {
        $q = $p->prepare($sql);
        $q->execute($args);
        return $q->fetch(PDO::FETCH_ASSOC) ?: throw new RuntimeException('NATIVE_PACKET_RECORD_MISSING');
    }

    private static function rows(PDO $p, string $sql, array $args): array
    {
        $q = $p->prepare($sql);
        $q->execute($args);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function actor(PDO $p, array $actor, bool $write = true): array
    {
        $locking = $p->inTransaction() ? ' FOR UPDATE' : '';
        $u = self::row($p, "SELECT u.id,u.name,u.role AS role_id,u.auth_version,u.active,r.grants_configured,
            CASE WHEN u.role='admin' THEN 'admin' ELSE rp.kind END AS workflow_role
            FROM users u JOIN roles r ON r.id=u.role LEFT JOIN role_profiles rp ON rp.role_id=u.role WHERE u.id=?".$locking,
            [(int)($actor['id'] ?? 0)]);
        $roles = $write ? ['accountant', 'admin'] : ['manager', 'lawyer', 'accountant', 'director', 'admin'];
        if (!$u['active'] || !in_array($u['workflow_role'], $roles, true)
            || (int)$u['auth_version'] !== (int)($actor['auth_version'] ?? -1)
            || $u['role_id'] !== ($actor['role_id'] ?? null)) {
            throw new RuntimeException('NATIVE_PACKET_ACTOR_DENIED', 403);
        }
        foreach ($write ? ['rental.read', 'finance.write'] : ['rental.read'] as $grant) {
            $q = $p->prepare("SELECT 1 FROM role_permissions WHERE role_id=? AND permission_key IN (?,'*') LIMIT 1".$locking);
            $q->execute([$u['role_id'], $grant]);
            if (!$q->fetchColumn()) throw new RuntimeException('NATIVE_PACKET_ACTOR_DENIED', 403);
        }
        if ($u['role_id'] !== 'admin' && $u['grants_configured']) {
            $q = $p->prepare("SELECT level FROM role_page_grants WHERE role_id=? AND page_key='rental.approvals'".$locking);
            $q->execute([$u['role_id']]); $level = (int)($q->fetchColumn() ?: 0);
        } else $level = PagePolicy::levelForRole($u['role_id'], $u['workflow_role'], (bool)$u['grants_configured'], 'rental.approvals');
        if ($level < ($write ? 2 : 1)) {
            throw new RuntimeException('NATIVE_PACKET_ACTOR_DENIED', 403);
        }
        $u['id'] = (int)$u['id'];
        $u['auth_version'] = (int)$u['auth_version'];
        unset($u['active'], $u['grants_configured']);
        return $u;
    }

    private static function kind(string $kind): void
    {
        if (!in_array($kind, ['contract', 'document'], true)) throw new RuntimeException('NATIVE_PACKET_KIND_INVALID');
    }

    private static function hash(array $value): string
    {
        return hash('sha256', Importer::canonicalJson(self::semanticValue($value)));
    }

    /** Store the complete DTO; only the shared semantic projection enters comparison. */
    private static function semanticValue(array $value):array
    {
        foreach($value as$key=>$child){
            if($key==='billing_targets'&&is_array($child))$value[$key]=\App\BillingInvoiceTargets::semanticSnapshot($child);
            elseif($key==='terms'&&is_array($child)&&isset($value['part_state']))
                $value[$key]=\App\BillingInvoiceTargets::semanticSnapshot(['current_terms'=>$child])['current_terms'];
            elseif(is_array($child))$value[$key]=self::semanticValue($child);
        }
        return $value;
    }

    private static function invoiceSnapshot(PDO $p, int $id): array
    {
        $invoice = self::row($p, 'SELECT * FROM invoices WHERE id=?', [$id]);
        unset($invoice['source_json']);
        foreach (['id', 'version', 'contract_id'] as $key) $invoice[$key] = (int)$invoice[$key];
        $invoice['objects'] = self::rows($p, 'SELECT object_id FROM invoice_objects WHERE invoice_id=? ORDER BY object_id', [$id]);
        $invoice['coverage'] = self::rows($p, 'SELECT object_id,start_date,end_date FROM invoice_coverage WHERE invoice_id=? ORDER BY object_id,start_date,end_date', [$id]);
        $invoice['scope_state'] = ['objects' => $invoice['objects'] ? 'recorded' : 'unknown', 'coverage' => $invoice['coverage'] ? 'recorded' : 'unknown'];
        $invoice['scope_admission'] = \App\InvoiceScope::read($p, $id);
        $invoice['scope_state']['authority'] = $invoice['scope_admission'] === null ? 'unadmitted' : 'typed_admission';
        if ($invoice['scope_admission'] !== null) {
            $invoice['scope_state']['objects'] = strtolower($invoice['scope_admission']['objects_state']);
            $invoice['scope_state']['coverage'] = strtolower($invoice['scope_admission']['periods_state']);
        }
        $operating=\App\OperatingInvoiceContext::snapshot($p,$id);
        if($operating!==null)$invoice['operating_membership']=$operating; // Legal B records facts, not effect permission.
        $targets=\App\BillingInvoiceTargets::snapshot($p,$id);
        if($targets!==null)$invoice['billing_targets']=$targets;
        return $invoice;
    }

    /** The rows whose exact values the accountant sees before committing B. */
    private static function context(PDO $p, string $kind, int $id, bool $separatePolicy = false): array
    {
        self::kind($kind);
        $record = self::row($p, 'SELECT * FROM '.($kind === 'contract' ? 'contracts' : 'rental_documents').' WHERE id=?', [$id]);
        $contract = $kind === 'contract' ? $record : self::row($p, 'SELECT * FROM contracts WHERE id=?', [$record['contract_id']]);
        $party = self::row($p, 'SELECT * FROM parties WHERE id=?', [$contract['party_id']]);
        $organization = self::row($p, 'SELECT * FROM organization WHERE id=1');
        $legal = self::row($p, "SELECT * FROM rental_approvals WHERE entity_type=? AND entity_id=? AND role='lawyer'", [$kind, $id]);
        $file = self::row($p, 'SELECT * FROM rental_files WHERE id=?', [$legal['file_id']]);
        Evidence::integrity($file);
        $handoff=$kind==='contract'?LegalHandoff::approvedFiles($p,$id,$file):null;
        if ($handoff===null) {
            $latest=Evidence::latest($p,$kind,$id,(string)$file['kind'],(int)$file['version']);
            if ($latest['id']!==$file['id']) throw new RuntimeException('NATIVE_PACKET_LEGAL_FILE_SUPERSEDED');
        }
        $signature = $kind === 'contract'
            ? Evidence::approvalSignature($p, $id, $file['id'])
            : Evidence::documentSignature($p, $record, $file['id']);
        if (!hash_equals($legal['baseline_hash'], $signature)) throw new RuntimeException('NATIVE_PACKET_LEGAL_BASELINE_CHANGED');
        if ($kind === 'document' && !hash_equals((string)$record['base_hash'], Evidence::documentBase($p, (int)$contract['id']))) {
            throw new RuntimeException('NATIVE_PACKET_DOCUMENT_BASE_CHANGED');
        }
        $terms = self::rows($p, $kind === 'contract'
            ? 'SELECT object_id,start_date,end_date,amount,effective_date,superseded_on FROM contract_terms WHERE contract_id=? ORDER BY id'
            : 'SELECT dt.object_id,dt.start_date,dt.end_date,dt.amount,'.\App\ContractOccupancy::selectColumns('dt').' FROM document_terms dt WHERE dt.document_id=? ORDER BY dt.id', [$id]);
        $partState=\App\BillingPartTerms::state($p,$kind,$id);
        if($partState!==null){
            if(($partState['state']??null)!=='KNOWN')throw new RuntimeException('NATIVE_PACKET_PART_TERMS_UNCONFIRMED',409);
            $terms=$kind==='contract'?\App\BillableTerms::forContract($p,$id):\App\BillableTerms::forDocument($p,$id);
        }
        // Occupancy meaning is frozen, never used to remove billable/legal-address rows.
        $objects = self::rows($p, 'SELECT co.object_id,'.\App\ContractOccupancy::selectColumns('co').' FROM contract_objects co WHERE co.contract_id=? ORDER BY co.object_id', [$contract['id']]);
        $templateKind = $kind === 'document' ? 'addendum' : TemplateAssets::contractKind($p, $contract);
        $template = TemplateAssets::signature(TemplateAssets::active($p, $templateKind));
        $financial_state = AddendumBilling::financialState($p, (int)$contract['id']);
        // All parent invoices remain visible to B, including nonmember context.
        // Only selected outbound members are subject to the effect-ready guard.
        foreach($financial_state as&$financialInvoice){
            $targets=\App\BillingInvoiceTargets::snapshot($p,(int)$financialInvoice['id']);
            if($targets!==null)$financialInvoice['billing_targets']=$targets;
        }
        unset($financialInvoice);
        // Whole typed business rows are frozen, excluding historical JSON archives.
        foreach ([&$record, &$contract, &$party, &$organization, &$file, &$legal] as &$row) unset($row['source_json']);
        unset($row);
        $context=compact('record', 'contract', 'party', 'organization', 'legal', 'file', 'terms', 'objects', 'template', 'financial_state');
        if ($handoff!==null) $context['legal_handoff']=$handoff;
        elseif ($pack=WordPack::evidence($p,$file['id'])) $context['word_pack']=$pack;
        if($partState!==null)$context['part_state']=$partState;
        if ($kind === 'contract' && $separatePolicy) $context['initial_terms'] = InitialInvoice::approvalTerms($p,$id);
        return $context;
    }

    /** Presentation only; the complete typed terms and source receipt stay in context. */
    private static function previewTerms(array $context):array
    {
        return array_map(static function(array $term)use($context):array{
            $row=['object_id'=>isset($context['part_state'])?$term['object_id']:(int)$term['object_id'],
                'start'=>$term['start_date'],'end'=>$term['end_date'],'amount'=>$term['amount']];
            if(isset($context['part_state']))foreach(['target_kind','target_key','source_term_key','part_id','parent_object_id',
                'part_version','term_revision','evidence_sha256','admission_sha256']as$key)if(array_key_exists($key,$term))$row[$key]=$term[$key];
            return $row;
        },$context['terms']);
    }

    private static function documentMember(array $invoice, array $plan, int $documentId): bool
    {
        if (($plan['document_id'] ?? null) !== null) {
            return (int)$plan['document_id'] === $documentId
                && (int)$invoice['document_id'] === $documentId && $invoice['planning_document_id'] === null;
        }
        return ($plan['planning_document_id'] ?? null) !== null
            && (int)$plan['planning_document_id'] === $documentId
            && (int)$invoice['planning_document_id'] === $documentId && $invoice['document_id'] === null;
    }

    private static function plannedInvoices(PDO $p, string $kind, array $context): array
    {
        $c = $context['contract'];
        $d = $context['record'];
        if ($kind === 'contract') {
            $existing = [];
            $receipt = InitialInvoice::receipt($p,(int)$c['id']);
            $exclude = $receipt && !InitialInvoice::stillCoversIssue($p,$c,$receipt) ? $receipt['invoice_ids'] : [];
            foreach (InitialInvoice::issuedFor($p,(int)$c['id']) as $invoice) {
                if (in_array((int)$invoice['id'],$exclude,true)) continue;
                // Pending deadlines remain visible to B as financial facts. They grant no attachment authority.
                if ($invoice['status'] !== 'active' || !$invoice['issued'] || !$invoice['due']
                    || $invoice['amount'] === null || bccomp($invoice['amount'],'0',2)<=0) continue;
                $existing[] = $invoice;
            }
            return $existing;
        }
        $planned = AddendumBilling::planned($p, $c, $d);
        foreach ($planned as &$invoice) {
            $invoice['amount'] = Service::money($invoice['amount']);
            if (bccomp($invoice['amount'], '0', 2) <= 0) throw new RuntimeException('NATIVE_PACKET_AMOUNT_INVALID');
            $invoice['issued'] = date('Y-m-d');
            $existing = self::rows($p, 'SELECT id,contract_id,document_id,planning_document_id,status,amount,kind,period,version,issued FROM invoices WHERE coverage_key=?'
                .($p->inTransaction()?' FOR UPDATE':''), [$invoice['coverage_key']]);
            if ($d['status'] === 'approved') {
                // A fresh human confirmation of an approved packet must use its real existing
                // invoice membership and amounts. It never creates or recalculates financials.
                if (count($existing) !== 1 || (int)$existing[0]['contract_id'] !== (int)$c['id']
                    || !self::documentMember($existing[0], $invoice, (int)$d['id'])
                    || $existing[0]['status'] === 'revoked' || $existing[0]['kind'] !== $invoice['kind']
                    || $existing[0]['period'] !== $invoice['period'] || $existing[0]['amount'] === null
                    || bccomp((string)$existing[0]['amount'], '0', 2) <= 0 || !$existing[0]['issued']) {
                    throw new RuntimeException('NATIVE_PACKET_EXISTING_MEMBERSHIP_UNCONFIRMED');
                }
                $invoice['amount'] = Service::money($existing[0]['amount']);
                $invoice['issued'] = $existing[0]['issued'];
            }
            $invoice['existing'] = $existing;
        }
        unset($invoice);
        return $planned;
    }

    private static function validateLegalTerms(string $kind,array $context):void
    {
        foreach ($context['terms'] as $term) {
            // The actual DS writer validates raw positivity before rounding. A
            // proven 0.001 -> 0.00 is legal evidence, not an invoice effect grant.
            $provenDocumentZero=$kind==='document'&&($term['target_kind']??null)==='billing-part'
                &&($context['part_state']['owner_kind']??null)==='document'&&$term['amount']!==null
                &&bccomp($term['amount'],'0',12)===0&&PartTerms::roundedZeroProven($context['part_state']??null,$term);
            if (!$term['start_date'] || !$term['end_date'] || $term['end_date'] < $term['start_date'] || $term['amount'] === null
                || (bccomp($term['amount'], '0', 4) <= 0&&!$provenDocumentZero)) {
                throw new RuntimeException('NATIVE_PACKET_EXPLICIT_TERMS_REQUIRED');
            }
        }
        if (!$context['terms']&&!($kind==='document'&&($context['part_state']['state']??null)==='KNOWN'
            &&($context['part_state']['known_empty']??null)===true)) throw new RuntimeException('NATIVE_PACKET_EXPLICIT_TERMS_REQUIRED');
    }

    public static function preview(PDO $p, string $kind, int $id, array $actor): array
    {
        $user = self::actor($p, $actor);
        $context = self::context($p, $kind, $id, true);
        if (!in_array($context['record']['status'], ['accounting', 'approved'], true)) throw new RuntimeException('NATIVE_PACKET_NOT_AT_ACCOUNTANT');
        self::validateLegalTerms($kind,$context);
        $runtime = self::row($p, 'SELECT epoch,revision,source_pair_sha256 FROM rental_runtime_context WHERE id=1');
        $view = ['record_kind' => $kind, 'record_id' => $id, 'actor' => $user, 'context' => $context,
            'invoices' => self::plannedInvoices($p, $kind, $context), 'workflow' => $runtime];
        if ($kind === 'contract') $view['initial_invoice_policy'] = InitialInvoice::POLICY;
        return ['expected_packet_hash' => self::hash($view), 'record' => ['kind' => $kind, 'id' => $id, 'version' => (int)$context['record']['version']], 'view' => $view];
    }

    /** Called under the same command transaction, before any B state transition. */
    public static function validateBeforeB(PDO $p, array $actor, string $action, array $input): ?array
    {
        if (!in_array($action, ['contract.packet', 'document.packet'], true) || empty($input['packet_confirmed'])) return null;
        if (!$p->inTransaction()) throw new RuntimeException('NATIVE_PACKET_TRANSACTION_REQUIRED');
        $p->query('SELECT id FROM rental_runtime_context WHERE id=1 FOR UPDATE')->fetchColumn();
        $kind = $action === 'contract.packet' ? 'contract' : 'document';
        $preview = self::preview($p, $kind, (int)($input['id'] ?? 0), $actor);
        if (!is_string($input['expected_packet_hash'] ?? null) || !hash_equals($preview['expected_packet_hash'], $input['expected_packet_hash'])) {
            throw new RuntimeException(self::PREVIEW_CHANGED, 409);
        }
        return $preview;
    }

    /** Every successful new command advances the revision; replays never call this. */
    public static function captureAfterB(PDO $p, array $actor, string $action, array $input, array $result, string $commandKey, string $fingerprint, ?array $validated = null): ?array
    {
        if (!$p->inTransaction()) throw new RuntimeException('NATIVE_PACKET_TRANSACTION_REQUIRED');
        $runtime = self::row($p, 'SELECT * FROM rental_runtime_context WHERE id=1 FOR UPDATE');
        $p->exec('UPDATE rental_runtime_context SET revision=revision+1 WHERE id=1');
        if ($validated === null) {
            if (!empty($input['packet_confirmed']) && in_array($action, ['contract.packet', 'document.packet'], true)) throw new RuntimeException('NATIVE_PACKET_PREVALIDATION_REQUIRED');
            return null;
        }
        $user = self::actor($p, $actor);
        $kind = $validated['record']['kind'];
        $id = (int)$validated['record']['id'];
        if (($kind === 'contract' ? 'contract.packet' : 'document.packet') !== $action || (int)($result['id'] ?? 0) !== $id
            || !hash_equals(self::hash($validated['view']), $validated['expected_packet_hash'])
            || !hash_equals($validated['expected_packet_hash'], (string)($input['expected_packet_hash'] ?? ''))
            || $validated['view']['actor'] !== $user || $validated['view']['workflow']['epoch'] !== $runtime['epoch']
            || (string)$validated['view']['workflow']['revision'] !== (string)$runtime['revision']) {
            throw new RuntimeException('NATIVE_PACKET_CAPTURE_CONTEXT_CHANGED');
        }
        $checks = [];
        foreach (['counterparty', 'financial', 'document'] as $check) {
            if (empty($input[$check.'_confirmed'])) throw new RuntimeException('NATIVE_PACKET_THREE_CHECKS_REQUIRED');
            $checks[$check] = true;
        }
        $command = self::row($p, 'SELECT * FROM rental_commands WHERE command_key=?', [$commandKey]);
        if ($command['action'] !== $action || !hash_equals($command['fingerprint'], $fingerprint)) {
            throw new RuntimeException('NATIVE_PACKET_COMMAND_RECEIPT_CHANGED');
        }
        $context = self::context($p, $kind, $id, true);
        $expectedStatus = $kind === 'contract' ? 'active' : 'approved';
        if ($context['record']['status'] !== $expectedStatus || (int)$context['record']['version'] !== $validated['record']['version'] + 1) throw new RuntimeException('NATIVE_PACKET_POST_B_VERSION_CHANGED');
        $before = $validated['view']['context'];
        $after = $context;
        // Only the addendum command still books invoices during B.
        if ($kind === 'document') unset($before['financial_state'], $after['financial_state']);
        foreach ($kind === 'contract' ? ['record', 'contract'] : ['record'] as $key) {
            unset($before[$key]['status'], $before[$key]['version'], $after[$key]['status'], $after[$key]['version']);
        }
        if (!hash_equals(self::hash($before), self::hash($after))) throw new RuntimeException('NATIVE_PACKET_POST_B_CONTENT_CHANGED');
        $invoices = [];
        foreach ($validated['view']['invoices'] as $planned) {
            $invoice = $kind === 'contract'
                ? self::row($p,'SELECT * FROM invoices WHERE id=?',[$planned['id']])
                : self::row($p,'SELECT * FROM invoices WHERE coverage_key=?',[$planned['coverage_key']]);
            if ((int)$invoice['contract_id'] !== (int)$context['contract']['id'] || $invoice['kind'] !== $planned['kind'] || $invoice['period'] !== $planned['period']
                || bccomp((string)$invoice['amount'], $planned['amount'], 2) !== 0
                || ($kind === 'document' && !self::documentMember($invoice, $planned, $id))) throw new RuntimeException('NATIVE_PACKET_INVOICE_MISMATCH');
            $invoices[] = self::invoiceSnapshot($p, (int)$invoice['id']);
        }
        $confirmationId = bin2hex(random_bytes(16));
        $steps = [['step_id' => $confirmationId.':party', 'kind' => 'counterparty', 'subject_id' => 'party:'.$context['party']['id']]];
        foreach ($invoices as $invoice) $steps[] = ['step_id' => $confirmationId.':invoice:'.$invoice['id'], 'kind' => 'customer_invoice', 'subject_id' => 'invoice:'.$invoice['id']];
        $snapshot = ['record' => ['kind' => $kind, 'id' => $id, 'version' => (int)$context['record']['version']],
            'workflow' => ['epoch' => $runtime['epoch'], 'revision' => (int)$runtime['revision'] + 1],
            'confirmation' => ['command_key' => $commandKey, 'actor_id' => $user['id'], 'checks' => $checks,
                'command_fingerprint' => $command['fingerprint'], 'result_sha256' => hash('sha256', $command['result_json']),
                'preview_sha256' => $validated['expected_packet_hash'], 'audit_ref' => 'rental-command:'.$commandKey],
            'context' => $context, 'organization' => $context['organization'], 'party' => $context['party'], 'contract' => $context['contract'],
            'file' => ['id' => $context['file']['id'], 'sha256' => $context['file']['sha256'], 'revision' => (int)$context['file']['version']],
            'legal' => ['baseline_hash' => $context['legal']['baseline_hash'], 'file_id' => $context['file']['id']],
            'preview' => ['terms' => self::previewTerms($context),
                'vat' => $context['organization']['vat'], 'vatIncluded' => true],
            'invoices' => $invoices, 'invoice_ids' => array_column($invoices, 'id'), 'native_steps' => $steps];
        if ($kind === 'contract') $snapshot['initial_invoice_policy'] = InitialInvoice::POLICY;
        $raw = Importer::canonicalJson($snapshot);
        $sha = hash('sha256', $raw);
        $packetSignature = $validated['expected_packet_hash'];
        $pins = ['project_id' => CaptureReader::PROJECT, 'source_pair_sha256' => $runtime['source_pair_sha256'], 'format' => 'ipya.php.normal-b.v1'];
        $p->prepare('INSERT INTO rental_packet_confirmations(id,record_kind,record_id,record_version,actor_id,role_id,auth_version,command_key,checks_json,snapshot_json,snapshot_sha256,legal_baseline,legal_file_id,legal_file_sha256,file_revision,workflow_epoch,workflow_revision,source_pins_json,snapshot_text,raw_sha256,originalapproval_signature_text,packet_signature_text) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([
            $confirmationId, $kind, $id, $snapshot['record']['version'], $user['id'], $user['role_id'], $user['auth_version'], $commandKey,
            Importer::json($checks), $raw, $sha, $context['legal']['baseline_hash'], $context['file']['id'], $context['file']['sha256'], $snapshot['file']['revision'],
            $runtime['epoch'], (int)$runtime['revision'] + 1, Importer::json($pins), $raw, $sha, $context['legal']['baseline_hash'], $packetSignature,
        ]);
        return ['binding_id' => $confirmationId, 'version' => $snapshot['record']['version'], 'sha256' => $sha];
    }

    /** Original B plus current business facts; grants no current sender authority. */
    public static function deliveryBusinessProof(PDO $p,string $kind,int $id,array $binding):array
    {
        self::kind($kind);
        $original=PacketApprovalRecord::read($p,$kind,$id,$binding);
        $latest=self::row($p,'SELECT id FROM rental_packet_confirmations WHERE record_kind=? AND record_id=? ORDER BY workflow_revision DESC LIMIT 1',[$kind,$id]);
        if($latest['id']!==$binding['binding_id'])throw new RuntimeException('PACKET_APPROVAL_SUPERSEDED',409);
        $snapshot=$original['snapshot'];$context=self::boundContext($p,$kind,$id,$snapshot);
        if(!hash_equals(self::hash($snapshot['context']),self::hash($context))
            ||(int)$snapshot['record']['version']!==(int)$context['record']['version'])throw new RuntimeException('NATIVE_PACKET_STALE',409);
        foreach($snapshot['invoices']as$frozen){
            if(!hash_equals(self::hash($frozen),self::hash(self::invoiceSnapshot($p,(int)$frozen['id']))))throw new RuntimeException('NATIVE_PACKET_INVOICE_CHANGED',409);
        }
        $snapshot['state']='confirmed';$snapshot['b_packet']=$binding;
        $snapshot['original_actor_context']=$original['signer'];
        $snapshot['confirmation']['snapshot_sha256']=$original['raw_sha256'];
        $snapshot['confirmation']['approved_at']=$original['confirmed_at'];
        return $snapshot;
    }

    private static function boundContext(PDO $p,string $kind,int $id,array $snapshot): array
    {
        $separate = $kind === 'contract' && ($snapshot['initial_invoice_policy'] ?? null) === InitialInvoice::POLICY;
        $context = self::context($p,$kind,$id,$separate);
        if (!$separate) return $context;
        $prior = array_map('intval',array_column($snapshot['context']['financial_state'],'id'));
        $later = array_diff(InitialInvoice::separateIds($p,$id),$prior);
        // Keep the original packet's membership. Issuing separately cannot add a new attachment,
        // excuse any change to a reviewed invoice or bypass the delivery checks.
        $context['financial_state'] = array_values(array_filter($context['financial_state'],
            static fn(array $invoice): bool => !in_array((int)$invoice['id'],$later,true)));
        return $context;
    }

    public static function current(PDO $p, string $kind, int $id, array $actor, string $phase): array
    {
        self::kind($kind);
        $user = self::actor($p, $actor, $phase !== 'read_existing');
        $rows = self::rows($p, 'SELECT * FROM rental_packet_confirmations WHERE record_kind=? AND record_id=? ORDER BY workflow_revision DESC LIMIT 1', [$kind, $id]);
        if (!$rows) return ['state' => 'normal_confirmation_required', 'missing' => ['confirmation.normal_accountant_packet']];
        $saved = $rows[0];
        $raw = $saved['snapshot_text'];
        if (!is_string($raw) || !is_string($saved['raw_sha256']) || !hash_equals($saved['raw_sha256'], hash('sha256', $raw))
            || !hash_equals($saved['snapshot_sha256'], $saved['raw_sha256']) || !$saved['originalapproval_signature_text'] || !$saved['packet_signature_text']) {
            throw new RuntimeException('NATIVE_PACKET_RAW_AUTHORITY_INVALID');
        }
        $snapshot = json_decode($raw, true, 128, JSON_THROW_ON_ERROR);
        $context = self::boundContext($p,$kind,$id,$snapshot);
        if (!hash_equals(self::hash($snapshot['context']), self::hash($context)) || (int)$saved['record_version'] !== (int)$context['record']['version']) throw new RuntimeException('NATIVE_PACKET_STALE', 409);
        if ((int)$saved['actor_id'] !== $user['id'] || $saved['role_id'] !== $user['role_id'] || (int)$saved['auth_version'] !== $user['auth_version']) throw new RuntimeException('NATIVE_PACKET_CONFIRMATION_ACTOR_CHANGED', 403);
        $runtime = self::row($p, 'SELECT * FROM rental_runtime_context WHERE id=1');
        if ($runtime['epoch'] !== $saved['workflow_epoch'] || (int)$runtime['revision'] < (int)$saved['workflow_revision']) throw new RuntimeException('NATIVE_PACKET_WORKFLOW_CHANGED');
        $command = self::row($p, 'SELECT * FROM rental_commands WHERE command_key=?', [$saved['command_key']]);
        if ($command['action'] !== ($kind === 'contract' ? 'contract.packet' : 'document.packet')
            || !hash_equals($snapshot['confirmation']['command_fingerprint'], $command['fingerprint'])
            || !hash_equals($snapshot['confirmation']['result_sha256'], hash('sha256', $command['result_json']))) throw new RuntimeException('NATIVE_PACKET_RECEIPT_MISSING');
        if (!hash_equals($saved['originalapproval_signature_text'], $snapshot['legal']['baseline_hash'])
            || !hash_equals($saved['packet_signature_text'], $snapshot['confirmation']['preview_sha256'])) throw new RuntimeException('NATIVE_PACKET_SIGNATURE_BINDING_CHANGED');
        foreach ($snapshot['invoices'] as $frozen) {
            $current = self::invoiceSnapshot($p, $frozen['id']);
            if (!hash_equals(self::hash($frozen), self::hash($current))) throw new RuntimeException('NATIVE_PACKET_INVOICE_CHANGED', 409);
        }
        $snapshot['state'] = 'confirmed';
        $snapshot['confirmation']['snapshot_sha256'] = $saved['raw_sha256'];
        $snapshot['confirmation']['approved_at'] = $saved['confirmed_at'];
        $snapshot['b_packet'] = ['binding_id' => $saved['id'], 'version' => (int)$saved['record_version'], 'sha256' => $saved['raw_sha256']];
        $snapshot['source_original'] = ['snapshot_text' => $raw, 'raw_sha256' => $saved['raw_sha256'],
            'approval_signature_text' => $saved['originalapproval_signature_text'], 'packet_signature_text' => $saved['packet_signature_text']];
        return $snapshot;
    }
}
