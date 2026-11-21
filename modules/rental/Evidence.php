<?php
declare(strict_types = 1);
namespace Modules\Rental;
use PDO;
use RuntimeException;
final class Evidence {
    private static function exceptionEvidence(PDO $db,int $id): array {
        $query=$db->prepare('SELECT source_json FROM contracts WHERE id=?');
        $query->execute([$id]);
        $exceptions=OccupiedBilling::exceptions(['source_json'=>$query->fetchColumn() ?: '{}']);
        return $exceptions?['occupied_billing_exceptions'=>$exceptions]:[];
    }

    public static function documentBase(PDO $p, int $id):string {
        $q = $p->prepare('SELECT id,number,party_id,document_date,start_date,end_date,amount,status,version FROM contracts WHERE id=?');
        $q->execute([
            $id
        ]);
        $c = $q->fetch();
        $q = $p->prepare('SELECT object_id,start_date,end_date,amount,effective_date FROM contract_terms WHERE contract_id=? ORDER BY id');
        $q->execute([
            $id
        ]);
        $terms = $q->fetchAll();
        $parts = \App\BillingParts::terms($p, 'contract', $id);
        return hash('sha256', Importer::json([
            $c,
            $terms,
            ... (\App\InvoiceScope::enabled()?[Occupancy::relations($p,$id)]:[]),
            ...self::exceptionEvidence($p,$id),
            ...($parts?['part_terms' => $parts]:[]),
            ...( ($state = \App\BillingPartTerms::state($p, 'contract', $id))!==null?['part_state' => $state]:[]),
        ]));
    }
    public static function documentSignature(PDO $p, array $d, string $fileId):string {
        $q = $p->prepare('SELECT object_id,start_date,end_date,amount'.Occupancy::proofColumns('document_terms').' FROM document_terms WHERE document_id=? ORDER BY id');
        $q->execute([
            $d['id']
        ]);
        $terms = $q->fetchAll();
        $q = $p->prepare('SELECT * FROM rental_files WHERE id=?');
        $q->execute([
            $fileId
        ]);
        $f = $q->fetch()?:throw new RuntimeException('Файл ДС не найден.');
        self::integrity($f);
        return hash('sha256', Importer::json([
            array_intersect_key($d,
            array_flip([
                'id',
                'contract_id',
                'number',
                'kind',
                'effective_date',
                'document_date',
                'start_date',
                'end_date',
                'amount',
                'base_hash'
            ])),
            $terms,
            $f['id'],
            $f['sha256'],
            ...(($pack=WordPack::evidence($p,$fileId))?['word_pack'=>$pack]:[]),
            TemplateAssets::signature(TemplateAssets::active($p,'addendum')),
            ...( ($parts = PartTerms::signatureTerms($p, 'document', (int)$d['id']))?['part_terms' => $parts]:[]),
            ...( ($state = \App\BillingPartTerms::state($p, 'document', (int)$d['id']))!==null?['part_state' => $state]:[]),
        ]));
    }
    public static function integrity(array $file):void {
        $path = APP_ROOT
        . '/.local/documents/'
        . basename($file['path']);
        if (!is_file($path) || hash_file('sha256', $path) !== $file['sha256'])throw new RuntimeException('Проверка целостности документа не пройдена.');
    }
    public static function signedDocumentParts(PDO $pdo, array $document, array $file): void {
        $parts = \App\BillingParts::terms($pdo, 'document', (int)$document['id']);
        $state = \App\BillingPartTerms::state($pdo, 'document', (int)$document['id']);
        if (!$parts && $state===null) return;
        if (($state['state'] ?? null)!=='KNOWN') throw new RuntimeException('Не подтверждён состав частей подписанного ДС.', 409);
        foreach ($parts as $part) \App\BillingParts::requireReady($part);
        if (!is_string($file['baseline_hash'] ?? null)
            || !hash_equals($file['baseline_hash'], self::documentSignature($pdo, $document, $file['id']))) {
            throw new RuntimeException('Подписанный файл ДС не соответствует актуальным условиям частей.', 409);
        }
    }
    public static function signature(PDO $p, int $id):string {
        $q = $p->prepare('SELECT id,number,party_id,start_date,end_date,amount,finish_date,finish_reason FROM contracts WHERE id=?');
        $q->execute([
            $id
        ]);
        $c = $q->fetch();
        $q = $p->prepare('SELECT object_id'.Occupancy::proofColumns('contract_objects').' FROM contract_objects WHERE contract_id=? ORDER BY object_id');
        $q->execute([
            $id
        ]);
        $objects = $q->fetchAll();
        $q = $p->prepare('SELECT id,amount,paid,status,version FROM invoices WHERE contract_id=? ORDER BY id');
        $q->execute([
            $id
        ]);
        $invoices=$q->fetchAll();
        return hash('sha256', Importer::json([
            $c,
            $objects,
            $invoices,
            ...self::exceptionEvidence($p,$id),
            ...( ($parts = PartTerms::signatureTerms($p, 'contract', $id))?['part_terms' => $parts]:[]),
            ...( ($state = \App\BillingPartTerms::state($p, 'contract', $id))!==null?['part_state' => $state]:[]),
        ]));
    }
    public static function approvalSignature(PDO $p, int $id, string $fileId):string {
        $q = $p->prepare('SELECT id,number,party_id,start_date,end_date,amount,template_type FROM contracts WHERE id=?');
        $q->execute([
            $id
        ]);
        $c = $q->fetch();
        $q = $p->prepare('SELECT object_id'.Occupancy::proofColumns('contract_objects').' FROM contract_objects WHERE contract_id=? ORDER BY object_id');
        $q->execute([
            $id
        ]);
        $objects = $q->fetchAll();
        $q = $p->prepare('SELECT * FROM rental_files WHERE id=?');
        $q->execute([
            $fileId
        ]);
        $file = $q->fetch()?:throw new RuntimeException('Файл не найден.');
        self::integrity($file);
        return hash('sha256', Importer::json([
            $c,
            $objects,
            ...self::exceptionEvidence($p,$id),
            TemplateAssets::signature(TemplateAssets::active($p,TemplateAssets::contractKind($p,$c))),
            ...(($pack=WordPack::evidence($p,$fileId))?['word_pack'=>$pack]:[]),
            [
                'id' => $file['id'],
                'sha256' => $file['sha256'],
                'version' => $file['version']
            ],
            ...( ($parts = PartTerms::signatureTerms($p, 'contract', $id))?['part_terms' => $parts]:[]),
            ...( ($state = \App\BillingPartTerms::state($p, 'contract', $id))!==null?['part_state' => $state]:[]),
        ]));
    }
    public static function latest(PDO $db, string $type, int $id, string $kind, ?int $version = null): array
    {
        $query = $db->prepare(
            'SELECT * FROM rental_files WHERE entity_type=? AND entity_id=? AND kind=?'
            .($version !== null ? ' AND version=?' : '').' ORDER BY upload_order DESC'
        );
        $parameters = [$type, $id, $kind];
        if ($version !== null) {
            $parameters[] = $version;
        }
        $query->execute($parameters);
        $files = $query->fetchAll(PDO::FETCH_ASSOC);
        if (!$files) {
            if ($kind === ReturnAct::KIND) throw new RuntimeException('Сначала загрузите подписанный акт возврата.', 404);
            throw new RuntimeException('Нужен проверенный актуальный файл: '.$kind);
        }
        if (count($files) > 1 && array_filter($files, fn(array $file) => $file['upload_order'] === null)) {
            if ($kind === ReturnAct::KIND) throw new RuntimeException('Не подтверждён порядок сохранённых актов возврата.', 409);
            throw new RuntimeException('Не подтверждён логический порядок исторических файлов.');
        }
        self::integrity($files[0]);
        return $files[0];
    }
    public static function activation(PDO $p, int $id):bool {
        $c = Service::get($p, 'contracts', $id);
        if (!in_array($c['status'], [
            'sent'
        ], true))return false;
        $q = $p->prepare("SELECT kind FROM invoices WHERE contract_id=? AND initial_invoice=1 AND status='active' AND amount IS NOT NULL AND paid IS NOT NULL GROUP BY kind HAVING SUM(GREATEST(amount-paid,0))=0");
        $q->execute([
            $id
        ]);
        $kinds = $q->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('Первый период',$kinds,true)) return false;
        if (!in_array(SecurityDeposit::KIND,$kinds,true)) {
            $security=SecurityDeposit::obligation($p,$id);
            if (!$security || !$security['initial_invoice'] || $security['paid']===null
                || bccomp(SecurityDeposit::payable($p,$security,$security['paid']),'0',2)!==0) return false;
        }
        CustomerBookings::checkActivation($p,$c);
        $p->prepare("UPDATE contracts SET status='active',version=version+1 WHERE id=?")->execute([
            $id
        ]);
        CustomerBookings::cancelOthers($p,$id);
        Service::event($p,\App\Auth::user(),'contract.activated','Договор '.$c['number'].' действует: оплачены обеспечение и первый период',$id);
        return true;
    }
    private static function closure(PDO $p, int $id, ?array $newReturnAct = null):array {
        FileOrder::lock($p);
        $c = Service::get($p, 'contracts', $id);
        if (!in_array($c['status'], [
            'reconciling',
            'ended'
        ], true) || !$c['finish_date'] || $c['finish_date']>date('Y-m-d'))throw new RuntimeException('Дата завершения ещё не наступила.');
        $noReturn=OccupiedBilling::noPhysicalReturn($p,$c);
        if (!$noReturn && !$c['return_confirmed'])throw new RuntimeException('Менеджер должен подтвердить подписанный акт возврата.');
        FinalSettlement::assertReview($p,$c,'finance');
        FinalSettlement::assertReview($p,$c,'legal');
        $sig = self::signature($p, $id);
        $query = $p->prepare('SELECT * FROM rental_reviews WHERE contract_id=? ORDER BY kind FOR UPDATE');
        $query->execute([$id]);
        $reviews = [];
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $review) {
            $reviews[$review['kind']] = $review;
        }
        foreach (['finance', 'legal'] as $kind) {
            if (!FinalReview::current($p,$c,$kind,$reviews[$kind] ?? null,$sig)) {
                throw new RuntimeException('Нужна актуальная проверка: '.$kind);
            }
        }
        if (!$noReturn) {
            $file = $newReturnAct ?? ReturnAct::confirmed($p, $c);
            if ($file === null) throw new RuntimeException('Менеджер должен подтвердить подписанный акт возврата.', 409);
            $physical=ReturnAct::physicalProof($p,$c,$file);
            if ($file['baseline_hash'] !== $sig && $physical===null) {
                throw new RuntimeException('Акт возврата относится к прежним данным сверки.');
            }
        }
        $c['_closure_proof'] = [
            'close_id' => bin2hex(random_bytes(16)),
            'approved_baseline' => $sig,
            'record_version' => (int)$c['version'] + 1,
            'finance' => ['row' => $reviews['finance'], 'sha256' => hash('sha256', Importer::canonicalJson($reviews['finance']))],
            'legal' => ['row' => $reviews['legal'], 'sha256' => hash('sha256', Importer::canonicalJson($reviews['legal']))],
            ...($noReturn?['return_not_applicable'=>true]:['act'=>array_intersect_key($file,
                array_flip(['id', 'version', 'sha256', 'upload_order', 'baseline_hash']))
                +($physical===null?[]:['physical_sha256'=>$physical])]),
        ];
        return $c;
    }
    private static function close(PDO $p, array $c):void {
        FileOrder::lock($p);
        $raw = json_decode($c['source_json'], true);
        $p->prepare("UPDATE contracts SET status='closed',end_date=?,version=version+1 WHERE id=?")->execute([
            !empty($raw['imported'])?$c['end_date']:$c['finish_date'],
            $c['id']
        ]);
        $scope=FinalSettlement::scoped($p,(int)$c['id']) ? " AND COALESCE(kind,'')<>'Обеспечительный платёж'" : '';
        $p->prepare("UPDATE invoices SET control_closed=1 WHERE contract_id=? AND status='active'".$scope)->execute([
            $c['id']
        ]);
        $actor = \App\Auth::user() ?? throw new RuntimeException('Требуется вход.', 401);
        $proof = $c['_closure_proof'];
        $proof['actor'] = array_intersect_key($actor, array_flip(['id', 'role_id', 'role', 'name', 'auth_version']));
        $proof['closed_baseline'] = self::signature($p, (int)$c['id']);
        \App\Audit::record('contract.completed', 'contract', $c['id'], null, ['closure_proof' => $proof]);
    }
    public static function finish(PDO $p, int $id):void {
        self::close($p, self::closure($p, $id));
    }
    public static function complete(PDO $p, int $id, ?array $newReturnAct = null):bool {
        try {
            $c = self::closure($p, $id, $newReturnAct);
        }
        catch (RuntimeException $e) {
            if ($e instanceof \PDOException)throw $e;
            return false;
        }
        self::close($p, $c);
        return true;
    }
}
