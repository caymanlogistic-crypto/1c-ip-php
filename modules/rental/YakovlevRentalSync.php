<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit, BusinessIds, OperatingMembership};
use DomainException;
use Modules\Integrations\{Config, PrivateStore, Support, YakovlevChanges, YakovlevImport};
use PDO;
use RuntimeException;

/** Local accounting of already posted documents. No transport, delivery or bank completeness claims. */
final class YakovlevRentalSync
{
    public const REVIEW = 'rental_yakovlev_review';
    private const BOOKED = 'rental_yakovlev_booked';
    private const INVOICES = 'Document_СчетНаОплатуПокупателю';
    private const RECEIPTS = 'Document_ПоступлениеНаРасчетныйСчет';

    public function __construct(private PDO $db, private PrivateStore $store) {}

    public static function settings(PDO $db): ?array
    {
        $file = APP_ROOT.'/.local/yakovlev-rental-accounting.json';
        if (!file_exists($file)) return null;
        if (PHP_SAPI !== 'cli' || is_link($file) || !is_readable($file) || filesize($file) > 4096)
            throw new RuntimeException('YAKOVLEV_ACCOUNTING_ADMISSION_INVALID');
        $scope = Support::decode(file_get_contents($file));
        $database = $db->query('SELECT DATABASE()')->fetchColumn();
        $live = getenv('APP_STAND') === 'ui' && $database === 'ipphp_ui';
        $local = !getenv('APP_STAND') && !Config::liveAllowed() && is_string($database) && preg_match('/\Aipphp_task_[a-z0-9_]{1,20}\z/',$database);
        if ((!$live && !$local) || ($scope['database'] ?? null) !== $database
            || ($scope['scope'] ?? null) !== 'yakovlev-post-archive-accounting'
            || ($scope['organization'] ?? null) !== YakovlevImport::ORGANIZATION
            || !self::guid($scope['currency'] ?? null) || !is_array($scope['accounts'] ?? null) || !$scope['accounts']
            || count(array_filter($scope['accounts'],self::guid(...))) !== count($scope['accounts']))
            throw new RuntimeException('YAKOVLEV_ACCOUNTING_ADMISSION_INVALID');
        return $scope;
    }

    /** Both successful archive loads must belong to the same scheduled window. */
    public function afterLoads(string $invoices, string $bank, string $from, string $until): array
    {
        $scope = self::settings($this->db);
        if (!$scope) return ['state'=>'disabled'];
        if (!self::guid($invoices) || !self::guid($bank) || $invoices === $bank || $this->db->inTransaction())
            throw new RuntimeException('YAKOVLEV_ARCHIVE_PAIR_REQUIRED');
        $q = $this->db->prepare("SELECT id,request_key,coverage_json FROM integration_runs
            WHERE id IN (?,?) AND provider=? AND actor_id=0 AND operation='yakovlev-load' AND status='success'");
        $q->execute([$invoices,$bank,YakovlevImport::PROVIDER]);
        $runs = array_column($q->fetchAll(PDO::FETCH_ASSOC),null,'id');
        $cycle = null;
        $docs = [];
        foreach ([$invoices=>'invoices',$bank=>'bank'] as $id=>$tab) {
            $run = $runs[$id] ?? [];
            $coverage = Support::decode($run['coverage_json'] ?? '{}');
            $window = $coverage['scope'] ?? [];
            $suffix = ':'.$tab.':'.$from;
            $key = $run['request_key'] ?? '';
            $current = substr($key,0,-strlen($suffix));
            if (($window['trigger'] ?? '') !== 'hourly' || ($window['kind'] ?? '') !== 'yakovlev-'.$tab
                || ($window['from'] ?? '') !== $from || ($window['to'] ?? '') !== $until
                || !str_ends_with($key,$suffix) || !self::guid($current) || ($cycle !== null && $cycle !== $current))
                throw new RuntimeException('YAKOVLEV_ARCHIVE_PAIR_REQUIRED');
            $cycle = $current;
            $batch = Support::decode($this->store->read($coverage['original_path'] ?? ''));
            if (($batch['provider'] ?? null) !== YakovlevImport::PROVIDER || Support::canonical($batch['scope'] ?? null) !== Support::canonical($window)
                || !isset($batch['documents']) || count($batch['documents']) !== (int)($coverage['records_imported'] ?? -1))
                throw new RuntimeException('YAKOVLEV_ARCHIVE_BATCH_CHANGED');
            foreach ($batch['documents'] as $record) {
                if (in_array($record['kind'] ?? '',['invoices','bank_in'],true)) $docs[] = $record;
            }
        }
        $result = ['state'=>'complete','created'=>0,'existing'=>0,'review'=>0,'ignored'=>0];
        foreach ($docs as $record) $result[$this->apply($scope,$record)]++;
        return $result;
    }

    private function apply(array $scope,array $record): string
    {
        $ref = YakovlevImport::normalize($record);
        $this->db->beginTransaction();
        try {
            $this->db->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            $row = $this->one('SELECT * FROM external_documents WHERE provider=? AND source_id=? FOR UPDATE',
                [YakovlevImport::PROVIDER,$ref['source_id']]);
            if (!$row) throw new RuntimeException('YAKOVLEV_ARCHIVE_DOCUMENT_MISSING');
            $archived = Support::decode($this->store->read($row['raw_path'],$row['raw_sha256']));
            $fingerprint = self::fingerprint($archived);
            if (!hash_equals($fingerprint,self::fingerprint($record))) throw new RuntimeException('YAKOVLEV_ARCHIVE_DOCUMENT_MOVED');

            $key = hash('sha256',$ref['source_id']);
            $booked = $this->fact(self::BOOKED,$key);
            $question = $this->fact(self::REVIEW,$key);
            $data = $archived['fields']['record'];
            $contracts = $this->contracts($data,true);
            $contract = count($contracts)===1 ? $contracts[0] : (int)($booked['contract_id'] ?? $question['contract_id'] ?? 0);
            $relevant = $contracts || $booked || $question || $this->known($data['Ref_Key'],$ref['account_id']);
            if (!$relevant) {
                [$relevant,$inferredContract] = $this->rentalEvidence($ref['account_id'],$data);
                if ($inferredContract) $contract = $inferredContract;
            }
            if (!$relevant) {
                $this->db->commit();
                return 'ignored';
            }
            try {
                $observed = $booked['sha'] ?? $question['first_sha'] ?? null;
                if ($observed && !hash_equals($observed,$fingerprint))
                    throw new DomainException('SOURCE_CHANGED');
                if ($ref['status'] !== 'Проведён' || ($data['Организация_Key'] ?? null) !== $scope['organization'])
                    throw new DomainException('SOURCE_NOT_POSTED');
                if ($booked) {
                    $this->assertBooked($scope,$data,$ref,$contract,$booked);
                    $effect = 'existing';
                } else {
                    $evidence = $ref['account_id']==='invoices'
                        ? $this->invoice($scope,$data,$ref,(string)$row['id'],$contract)
                        : $this->payment($scope,$data,$ref,(string)$row['id'],$contract);
                    $effect = $evidence['effect'];
                    $this->save(self::BOOKED,$key,['sha'=>$fingerprint,'contract_id'=>$contract]+$evidence);
                    if ($effect==='created') Audit::record('rental.yakovlev-accounting',$ref['account_id'],(string)$row['id'],null,$evidence);
                }
                if ($question && ($question['state'] ?? '')==='open') {
                    $this->save(self::REVIEW,$key,array_replace($question,['state'=>'resolved','resolution'=>'Единая связь подтверждена']));
                }
            } catch (DomainException $error) {
                $effect='review';
                $next=['state'=>'open','reason'=>$error->getMessage(),'contract_id'=>$contract,'contracts'=>$contracts,
                    'document_id'=>$row['id'],'kind'=>$ref['account_id'],'number'=>$ref['number'],
                    'date'=>$ref['document_date'],'amount'=>$ref['amount'],
                    'parking'=>YakovlevInvoiceCoverage::parkingReferences($data),
                    'first_sha'=>$question['first_sha'] ?? $fingerprint,'current_sha'=>$fingerprint];
                if (Support::canonical($question)!==Support::canonical($next)) {
                    $this->save(self::REVIEW,$key,$next);
                    Audit::record('rental.yakovlev-review','external-document',(string)$row['id'],$question,$next);
                }
            }
            $this->db->commit();
            return $effect;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function assertBooked(array $scope,array $data,array $ref,int $contractId,array $booked): void
    {
        $contract = $this->contract($contractId,$data,$scope);
        if ($ref['account_id']==='invoices') {
            $matches = $this->invoicesFor($data['Ref_Key']);
            if (count($matches)!==1 || (int)$matches[0]['id']!==(int)$booked['invoice_id'])
                throw new DomainException('EXISTING_INVOICE_CONFLICT');
            $this->assertInvoice($matches[0],$contractId,$ref);
            return;
        }
        $ids = $this->paymentIds($data['Ref_Key']);
        if ($ids!==($booked['payment_ids'] ?? [(int)$booked['payment_id']]))
            throw new DomainException('EXISTING_PAYMENT_CONFLICT');
        $this->assertExistingPayment($ids,$contract,$ref,$this->paymentLines($data,$contractId,self::cents($ref['amount'])));
    }

    private function invoice(array $scope,array $data,array $ref,string $document,int $contractId): array
    {
        $existing = $this->invoicesFor($data['Ref_Key']);
        $contract = $this->contract($contractId,$data,$scope);
        if (count($existing)>1) throw new DomainException('INVOICE_ID_AMBIGUOUS');
        if ($existing) {
            $invoice = $existing[0];
            $this->assertInvoice($invoice,$contractId,$ref);
            return ['effect'=>'existing','invoice_id'=>(int)$invoice['id']];
        }
        if ($contract['status']!=='active') throw new DomainException('CONTRACT_NOT_ACTIVE');
        $proof = YakovlevInvoiceCoverage::prove($this->db,$contract,$data,self::cents($ref['amount']));
        if ($this->one('SELECT id FROM invoices WHERE number=? LIMIT 1',[$ref['number']]))
            throw new DomainException('INVOICE_WITHOUT_SOURCE_LINK');
        YakovlevInvoiceCoverage::assertVacant($this->db,$contractId,$proof);
        self::assertOperatingWriter();
        $id = BusinessIds::next($this->db,'invoices',9000000,false);
        $snapshot = ['Ref_Key'=>$data['Ref_Key'],'currency'=>$scope['currency'],'yakovlev_archive'=>['document_id'=>$document],
            'printDetails'=>['number'=>$ref['number'],'issued'=>$ref['document_date'],'lines'=>$proof['lines']]];
        $this->db->prepare("INSERT INTO invoices(id,number,contract_id,kind,period,amount,paid,issued,status,source_json)
            VALUES(?,?,?,'Аренда',?,?,0,?,'active',?)")->execute([$id,$ref['number'],$contractId,$proof['period'],
                self::cents($ref['amount']),$ref['document_date'],Support::json($snapshot)]);
        $objects=$this->db->prepare('INSERT INTO invoice_objects(invoice_id,object_id) VALUES(?,?)');
        $cover=$this->db->prepare('INSERT INTO invoice_coverage(invoice_id,object_id,start_date,end_date) VALUES(?,?,?,?)');
        foreach (array_unique(array_column($proof['segments'],'object')) as $object) $objects->execute([$id,$object]);
        foreach ($proof['segments'] as $segment)
            $cover->execute([$id,$segment['object'],$segment['from'],$segment['until']]);
        return ['effect'=>'created','invoice_id'=>$id];
    }

    private function payment(array $scope,array $data,array $ref,string $document,int $contractId): array
    {
        $contract = $this->contract($contractId,$data,$scope);
        $accounts = array_values(array_unique(array_filter([$data['СчетОрганизации_Key'] ?? null,
            $data['БанковскийСчетОрганизации_Key'] ?? null],self::guid(...))));
        if (count($accounts)!==1 || !in_array($accounts[0],$scope['accounts'],true) || ($data['ВидОперации'] ?? null)!=='ОплатаПокупателя')
            throw new DomainException('PAYEE_OR_OPERATION_UNCONFIRMED');
        $total=self::cents($ref['amount']);
        $planned = $this->paymentLines($data,$contractId,$total);
        $known = $this->paymentIds($data['Ref_Key']);
        if ($known) {
            $this->assertExistingPayment($known,$contract,$ref,$planned);
            return ['effect'=>'existing','payment_ids'=>$known];
        }
        if ($contract['status']!=='active') throw new DomainException('CONTRACT_NOT_ACTIVE');
        if ($this->one('SELECT id FROM payments WHERE party_id=? AND payment_date=? AND amount=? LIMIT 1',
            [$contract['party_id'],$ref['document_date'],$total])) throw new DomainException('POSSIBLE_MANUAL_PAYMENT');
        self::assertOperatingWriter();
        foreach ($planned as $entry) {
            $i=$entry['invoice'];
            NativeOperatingWriter::requireObligation($this->db,$i);
            $accounted=$this->one('SELECT COALESCE(SUM(amount),0) total FROM payment_allocations WHERE invoice_id=?',[$i['id']])['total'];
            $remaining=InvoiceFacts::remaining($i);
            if ($i['status']!=='active' || !empty($i['control_closed']) || !empty($i['manual_review'])
                || $i['paid']===null || bccomp($i['paid'],$accounted,2)!==0 || $remaining===null
                || bccomp($entry['amount'],$remaining,2)>0 || (string)$i['kind']!=='Аренда')
                throw new DomainException('INVOICE_BALANCE_OR_KIND_CONFLICT');
        }
        $id=BusinessIds::next($this->db,'payments',9000000,false);
        $snapshot=['bank_source'=>YakovlevImport::PROVIDER,'bank_external_id'=>$data['Ref_Key'],
            'yakovlev_archive'=>['document_id'=>$document,'number'=>$ref['number']]];
        $purpose = trim((string)($data['НазначениеПлатежа'] ?? ''));
        if ($purpose!=='') $snapshot['bankPurpose']=$purpose;
        $this->db->prepare("INSERT INTO payments(id,party_id,amount,payment_date,source,source_json) VALUES(?,?,?,?,?,?)")
            ->execute([$id,$contract['party_id'],$total,$ref['document_date'],'1С: банковская выписка, '.$ref['number'],Support::json($snapshot)]);
        NativeOperatingWriter::requirePayment($this->db,$id);
        $put=$this->db->prepare('INSERT INTO payment_allocations(payment_id,invoice_id,amount) VALUES(?,?,?)');
        $update=$this->db->prepare('UPDATE invoices SET paid=?,version=version+1 WHERE id=? AND version=?');
        foreach ($planned as $entry) {
            $i=$entry['invoice'];
            FinanceService::remember($this->db,$i,null);
            $put->execute([$id,$i['id'],$entry['amount']]);
            $update->execute([bcadd($i['paid'],$entry['amount'],2),$i['id'],$i['version']]);
            if ($update->rowCount()!==1) throw new RuntimeException('YAKOVLEV_ACCOUNTING_INVOICE_MOVED');
        }
        return ['effect'=>'created','payment_id'=>$id,'invoice_ids'=>array_keys($planned)];
    }

    private function paymentLines(array $data,int $contract,string $total): array
    {
        $lines = $data['РасшифровкаПлатежа'] ?? [];
        if (!$lines) throw new DomainException('PAYMENT_LINES_MISSING');
        $planned=[]; $seen=[]; $sum='0.00';
        foreach ($lines as $line) {
            $number=(string)($line['LineNumber'] ?? '');
            $key=$line['СчетНаОплату_Key'] ?? null;
            if (!ctype_digit($number) || isset($seen[$number]) || !self::guid($key)
                || $this->contracts($line)!==[$contract] || !array_key_exists('СуммаВозврата',$line)
                || Support::money($line['СуммаВозврата'])!=='0.00000000')
                throw new DomainException('PAYMENT_LINE_AMBIGUOUS');
            $seen[$number]=true;
            $paid=self::cents($line['СуммаПлатежа'] ?? null);
            if ($paid!==self::cents($line['СуммаВзаиморасчетов'] ?? null)) throw new DomainException('PAYMENT_LINE_AMBIGUOUS');
            $resolved=$this->invoicesFor($key);
            if (count($resolved)!==1) throw new DomainException('PAYMENT_INVOICE_MISSING');
            $i=$resolved[0];
            $arch=$this->one("SELECT * FROM external_documents WHERE provider=? AND source_id=? FOR UPDATE",
                [YakovlevImport::PROVIDER,'invoices:'.$key]);
            if (!$arch || $arch['status']!=='Проведён') throw new DomainException('PAYMENT_INVOICE_MISSING');
            $original=Support::decode($this->store->read($arch['raw_path'],$arch['raw_sha256']))['fields']['record'];
            if ($this->contracts($original)!==[$contract] || self::partyRef($original)!==self::partyRef($data)
                || ($original['Организация_Key'] ?? null)!==($data['Организация_Key'] ?? null)
                || ($original['ВалютаДокумента_Key'] ?? null)!==($data['ВалютаДокумента_Key'] ?? null))
                throw new DomainException('PAYMENT_LINE_AMBIGUOUS');
            $this->assertInvoice($i,$contract,$arch);
            $sum=bcadd($sum,$paid,2);
            $planned[$i['id']]=['invoice'=>$i,'amount'=>bcadd($planned[$i['id']]['amount'] ?? '0',$paid,2)];
        }
        if ($sum!==$total) throw new DomainException('PAYMENT_TOTAL_CONFLICT');
        ksort($planned,SORT_NUMERIC);
        return $planned;
    }

    private function assertExistingPayment(array $known,array $contract,array $ref,array $planned): void
    {
        $sum='0.00'; $alloc=[];
        foreach ($known as $id) {
            $p=$this->one('SELECT * FROM payments WHERE id=? FOR UPDATE',[$id]);
            if (!$p || $p['payment_date']!==$ref['document_date'] || (int)$p['party_id']!==(int)$contract['party_id'])
                throw new DomainException('EXISTING_PAYMENT_CONFLICT');
            $sum=bcadd($sum,$p['amount'],2);
            foreach ($this->rows('SELECT invoice_id,amount FROM payment_allocations WHERE payment_id=?',[$id]) as $a)
                $alloc[$a['invoice_id']]=bcadd($alloc[$a['invoice_id']] ?? '0',$a['amount'],2);
        }
        foreach ($planned as $entry) {
            $invoice = $entry['invoice'];
            $total = $this->one('SELECT COALESCE(SUM(amount),0) total FROM payment_allocations WHERE invoice_id=?',[$invoice['id']])['total'];
            if ($invoice['paid']===null || bccomp($invoice['paid'],$total,2)!==0)
                throw new DomainException('EXISTING_PAYMENT_CONFLICT');
        }
        ksort($alloc,SORT_NUMERIC);
        if ($sum!==self::cents($ref['amount']) || $alloc!==array_map(static fn($v)=>$v['amount'],$planned))
            throw new DomainException('EXISTING_PAYMENT_CONFLICT');
    }

    private function contract(int $id,array $data,array $scope): array
    {
        if ($id<=0 || $this->contracts($data)!==[$id]) throw new DomainException('CONTRACT_AMBIGUOUS');
        $c=$this->one('SELECT c.*,p.source_json party_source,p.blocked FROM contracts c JOIN parties p ON p.id=c.party_id WHERE c.id=?',[$id]);
        $party=$c?Support::decode($c['party_source']):[];
        $mappedParty=$c?(Support::decode($c['source_json'])['sourceLegalMapping']['party1CRef_Key'] ?? null):null;
        if (!$c || $c['blocked'] || !self::guid(self::partyRef($data))
            || ($mappedParty && self::partyRef($data)!==$mappedParty)
            || self::partyRef($data)!==($party['source_party_guid'] ?? $party['Ref_Key'] ?? null)
            || ($data['ВалютаДокумента_Key'] ?? $data['Валюта_Key'] ?? null)!==$scope['currency'])
            throw new DomainException('PAYER_OR_CURRENCY_UNCONFIRMED');
        return $c;
    }

    private function contracts(array $record,bool $includeLines=false): array
    {
        $refs=[$record['ДоговорКонтрагента_Key'] ?? null];
        if ($includeLines) foreach ($record['РасшифровкаПлатежа'] ?? [] as $line) $refs[]=$line['ДоговорКонтрагента_Key'] ?? null;
        $ids=[];
        foreach (array_unique(array_filter($refs,self::guid(...))) as $ref) {
            foreach ($this->rows("SELECT DISTINCT entity_id FROM rental_source_bindings WHERE entity_type='contract'
                AND collection='Catalog_ДоговорыКонтрагентов' AND relation_kind IN ('rental_contract','security_contract') AND source_id=?",[$ref]) as $r)
                $ids[(int)$r['entity_id']]=true;
        }
        return array_keys($ids);
    }

    private function invoicesFor(string $ref): array
    {
        return $this->rows("SELECT i.* FROM invoices i WHERE JSON_UNQUOTE(JSON_EXTRACT(i.source_json,'$.Ref_Key'))=?
            OR EXISTS (SELECT 1 FROM rental_source_bindings b WHERE b.entity_type='invoice' AND b.entity_id=i.id
                AND b.collection=? AND b.relation_kind='invoice_control' AND b.source_id=?) ORDER BY i.id FOR UPDATE",
            [$ref,self::INVOICES,$ref]);
    }

    private function paymentIds(string $ref): array
    {
        return array_map('intval',array_column($this->rows("SELECT DISTINCT p.id FROM payments p
            WHERE JSON_UNQUOTE(JSON_EXTRACT(p.source_json,'$.bank_external_id'))=?
            OR JSON_UNQUOTE(JSON_EXTRACT(p.source_json,'$.Ref_Key'))=?
            OR EXISTS (SELECT 1 FROM rental_source_bindings b WHERE b.entity_type='payment' AND b.entity_id=p.id
                AND b.collection=? AND b.relation_kind='allocation_no_cash_replay' AND b.source_id LIKE CONCAT(?,':%'))
            OR EXISTS (SELECT 1 FROM rental_facts f WHERE f.namespace='rental_bank_payment_identity' AND f.operative=1
                AND JSON_UNQUOTE(JSON_EXTRACT(f.fact_json,'$.payment_id'))=CAST(p.id AS CHAR)
                AND f.source_key=SHA2(CONCAT(JSON_UNQUOTE(JSON_EXTRACT(p.source_json,'$.bank_source')),CHAR(0),?),256))
            ORDER BY p.id",[$ref,$ref,self::RECEIPTS,$ref,$ref]),'id'));
    }

    private function rentalEvidence(string $kind,array $data): array
    {
        if ($kind==='invoices') return [YakovlevInvoiceCoverage::hasRentLine($data),0];
        foreach ($data['РасшифровкаПлатежа'] ?? [] as $line) {
            $ref = $line['СчетНаОплату_Key'] ?? null;
            if (!self::guid($ref)) continue;
            $invoices = $this->invoicesFor($ref);
            if ($invoices) return [true,count($invoices)===1 ? (int)$invoices[0]['contract_id'] : 0];
            $archive = $this->one('SELECT raw_path,raw_sha256 FROM external_documents WHERE provider=? AND source_id=?',
                [YakovlevImport::PROVIDER,'invoices:'.$ref]);
            if (!$archive) continue;
            $invoice = Support::decode($this->store->read($archive['raw_path'],$archive['raw_sha256']))['fields']['record'];
            if (($invoice['Организация_Key'] ?? null)===($data['Организация_Key'] ?? null) && YakovlevInvoiceCoverage::hasRentLine($invoice)) {
                $contracts = $this->contracts($invoice);
                return [true,count($contracts)===1 ? $contracts[0] : 0];
            }
        }
        return [false,0];
    }

    private function known(string $ref,string $kind): bool
    {
        return $kind==='invoices' ? (bool)$this->invoicesFor($ref) : (bool)$this->paymentIds($ref);
    }

    private function assertInvoice(array $invoice,int $contract,array $ref): void
    {
        if ((int)$invoice['contract_id']!==$contract || $invoice['number']!==$ref['number']
            || $invoice['issued']!==$ref['document_date'] || $invoice['amount']!==self::cents($ref['amount'])
            || $invoice['status']!=='active') throw new DomainException('EXISTING_INVOICE_CONFLICT');
    }

    private static function assertOperatingWriter(): void
    {
        // The current source-C admission has no automated Yakovlev writer. Never impersonate its manual actor.
        if (OperatingMembership::selected()!==null) throw new DomainException('OPERATING_ADMISSION_REQUIRED');
    }

    private static function fingerprint(array $record): string
    {
        return hash('sha256',Support::canonical(($record['kind'] ?? null)==='invoices'
            ? YakovlevChanges::document($record) : $record));
    }

    public static function cents(mixed $value,bool $zero=false): string
    {
        try { $amount=Support::money($value); } catch (\InvalidArgumentException) { $amount=null; }
        if ($amount===null || bccomp($amount,bcadd($amount,'0',2),8)!==0 || bccomp($amount,'0',2)<($zero?0:1))
            throw new DomainException('UNCONFIRMED_AMOUNT');
        return bcadd($amount,'0',2);
    }

    private static function partyRef(array $row): ?string
    {
        return $row['Контрагент_Key'] ?? $row['Контрагент'] ?? null;
    }

    private static function guid(mixed $value): bool
    {
        return is_string($value) && $value!=='00000000-0000-0000-0000-000000000000'
            && preg_match('/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/D',$value)===1;
    }

    private function fact(string $namespace,string $key): array
    {
        $row=$this->one('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? FOR UPDATE',[$namespace,$key]);
        return $row?Support::decode($row['fact_json']):[];
    }

    private function save(string $namespace,string $key,array $fact): void
    {
        $this->db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)
            ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)')->execute([$namespace,$key,Support::json($fact)]);
    }

    private function rows(string $sql,array $params): array
    {
        $q=$this->db->prepare($sql);
        $q->execute($params);
        return $q->fetchAll(PDO::FETCH_ASSOC);
    }

    private function one(string $sql,array $params): ?array
    {
        return $this->rows($sql,$params)[0] ?? null;
    }
}
