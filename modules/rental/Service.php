<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{DB, Auth, Audit};
use PDO;
use RuntimeException;
final class Service {

    public static function money(mixed $value): string {
        $s = trim((string)$value);
        if (!preg_match('/^\d{1,16}(?:\.\d{1,2})?$/D', $s))throw new RuntimeException('Укажите неотрицательную сумму с точностью до копеек.');
        return bcadd($s, '0', 2);
    }

    public static function area(mixed $value): string {
        $s = trim((string)$value);
        if (!preg_match('/^\d{1,14}(?:\.\d{1,4})?$/D', $s) || bccomp($s, '0', 4) <= 0)throw new RuntimeException('Площадь должна быть положительной, до четырёх знаков после запятой.');
        return bcadd($s, '0', 4);
    }

    public static function date(string $v): string {
        $d = \DateTimeImmutable::createFromFormat('!Y-m-d', $v);
        if (!$d || $d->format('Y-m-d') !== $v)throw new RuntimeException('Укажите корректную дату.');
        return $v;
    }

    public static function roles(array $allowed): array {
        $u = Auth::user();
        if (!$u || !in_array($u['role'], array_merge([
            'admin'
        ], $allowed), true))throw new RuntimeException('Действие недоступно для этой роли.', 403);
        return $u;
    }

    public static function get(PDO $p, string $table, int $id): array {
        $q = $p->prepare('SELECT * FROM '
        . $table
        . ' WHERE id=? FOR UPDATE');
        $q->execute([
            $id
        ]);
        return $q->fetch(PDO::FETCH_ASSOC)?:throw new RuntimeException('Запись не найдена.', 404);
    }

    public static function version(array $row, array $in): void {
        if ((int)($in['version'] ?? 0) !== (int)$row['version'])throw new RuntimeException('Запись изменена другим сотрудником. Обновите карточку.', 409);
    }

    public static function event(PDO $p, array $u, string $action, string $text, ?int $contract = null, ?array $metadata=null): void {
        $values=[
            $contract,
            $u['id'],
            $u['role'],
            $u['name'],
            $u['role_id'] ?? $u['role'],
            $action,
            $text
        ];
        if($metadata===null)$p->prepare('INSERT INTO rental_events(contract_id,actor_id,actor_role,actor_name,actor_role_id,action,description) VALUES (?,?,?,?,?,?,?)')->execute($values);
        else $p->prepare('INSERT INTO rental_events(contract_id,actor_id,actor_role,actor_name,actor_role_id,action,description,source_json) VALUES (?,?,?,?,?,?,?,?)')->execute([...$values,Importer::json($metadata)]);
    }

    private const BLOCK_PROJECT_STATUSES=['draft','legal','accounting','returned','approved','sent'];

    private static function blockedPartyForNew(PDO $p,array $party):bool {
        if(!empty($party['blocked']))return true;
        $inn=preg_replace('/\D/','',(string)($party['inn']??''));
        if($inn==='')return false; // Empty identities never merge unrelated counterparties.
        $q=$p->prepare('SELECT id,inn FROM parties WHERE blocked=1 ORDER BY id FOR UPDATE');$q->execute();
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $blocked)
            if(preg_replace('/\D/','',(string)($blocked['inn']??''))===$inn)return true;
        return false;
    }

    private static function cancelBlockedProjects(PDO $p,array $actor,int $partyId,string $reason):array {
        $q=$p->prepare("SELECT * FROM contracts WHERE party_id=? AND status IN ('draft','legal','accounting','returned','approved','sent') AND ".ContractDraft::visibleSql('contracts')." ORDER BY id FOR UPDATE");
        $q->execute([$partyId]);$projects=[];
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $contract){
            if((int)$contract['party_id']!==$partyId||!in_array($contract['status'],self::BLOCK_PROJECT_STATUSES,true)||!RegistryState::managed($contract))continue;
            $raw=json_decode($contract['source_json'],true)?:[];
            $before=['status'=>$contract['status'],'blockedCancellation'=>!empty($raw['blockedCancellation']),'reason'=>$contract['reason']??'','version'=>(int)$contract['version']];
            $after=['status'=>'cancelled','blockedCancellation'=>true,'reason'=>$reason,'version'=>(int)$contract['version']+1];
            $update=$p->prepare("UPDATE contracts SET status='cancelled',return_to=NULL,reason=?,version=version+1,source_json=JSON_SET(source_json,'$.blockedCancellation',CAST('true' AS JSON)) WHERE id=? AND party_id=? AND version=? AND status=?");
            $update->execute([$reason,$contract['id'],$partyId,$contract['version'],$contract['status']]);
            if($update->rowCount()!==1)throw new RuntimeException('Проект изменён. Обновите карточку.',409);
            Audit::record('contract.cancel','contract',$contract['id'],$contract,$after+['trigger'=>'party.block','party_id'=>$partyId]);
            $projects[]=['id'=>(int)$contract['id'],'number'=>(string)$contract['number'],'before'=>$before,'after'=>$after];
        }
        return $projects;
    }

    /** Same coarse grant for command execution and its later typed workflow notification. */
    public static function commandGrant(string $action,array $actor): string {
        if (in_array($action,[UpdRegister::SAVE,SavedReceipt408::ACTION,OccupiedBilling::SAVE,InvoiceContractAssignment::SAVE],true)) return 'finance.write';
        if ($action === StaffHandoff::PACKET) return match($actor['role']) {
            'admin'=>'*','director'=>'rental.approve.executive',
            'lawyer'=>'rental.approve.legal','accountant'=>'finance.write',default=>'rental.write',
        };
        if (in_array($action,InitialInvoice::ACTIONS,true)) return InitialInvoice::grant($action);
        if (in_array($action,RentalServices::ACTIONS,true)) {
            return in_array($action,[RentalServices::CONFIRM,RentalServices::BOOK],true)?'finance.write':'rental.write';
        }
        return match ($action) {
            'contract.legal', 'contract.forward', 'template.edit', 'document.legal', 'reconcile.legal' => 'rental.approve.legal', 'contract.packet', 'contract.send', 'contract.close', 'document.packet', 'document.apply', 'payment.allocate', 'payment.create', 'invoice.issue', InvoiceBatch::SAVE, InvoiceCycle::SAVE, 'organization.save', 'organization.bank.save', 'reconcile.finance' => 'finance.write', 'template.approve', 'party.unblock' => 'rental.approve.executive', default => match ($actor['role']) {
                'lawyer' => 'rental.approve.legal', 'accountant' => 'finance.write', 'director' => 'rental.approve.executive', default => 'rental.write'
            }
        };
    }

    public static function command(string $action, array $in, ?int &$freshCommittedEventId=null): array {
        $freshCommittedEventId=null;
        if ($action === TermEndCorrection::ACTION) TermEndCorrection::requireOperator();
        if ($action === ArchivedRentCorrection::ACTION) ArchivedRentCorrection::requireOperator();
        if ($action === SavedReceipt408::ACTION) SavedReceipt408::requireOperator();
        if (in_array($action,ExpiryNotice::ACTIONS,true)) ExpiryNotice::access((int)($in['id'] ?? 0),2);
        if ($action === UpdRegister::SAVE) RegularInvoice::requireAccess(2);
        if ($action === DebtFollowUp::SAVE) self::roles(['manager']);
        if (in_array($action,[LeasePaymentTerms::SAVE,DebtFollowUp::SAVE,...RentalServices::ACTIONS,...InitialInvoice::ACTIONS],true)) Access::contract((int)($in['id']??0),2);
        else \App\PagePolicy::requireCommand($action);
        Auth::requirePermission('rental.read');
        $actor = Auth::user();
        $grant = self::commandGrant($action,$actor);
        Auth::requirePermission($grant);
        if (in_array($action,['contract.packet','document.packet','contract.send','document.send'],true)) self::roles(['accountant']);
        if ($action === 'contract.return-act') {
            $returnDate = self::date((string)($in['return_date'] ?? ''));
            if ($returnDate>date('Y-m-d'))throw new RuntimeException('Дата фактического возврата не может быть в будущем.', 422);
        }
        $u = Auth::user() ?? throw new RuntimeException('Требуется вход.');
        $key = (string)($in['command_key'] ?? '');
        if (!preg_match('/^[a-zA-Z0-9-]{16,100}$/D', $key))throw new RuntimeException('Отсутствует идентификатор команды.');
        $epoch=(string)DB::pdo()->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn();
        $fingerprintParts=[
            $u['id'],
            $action,
            $in
        ];
        // Preserve legacy retries until the first explicit baseline restore creates a new era.
        if (DB::pdo()->query("SELECT 1 FROM rental_facts WHERE namespace='zero_point_runtime' AND source_key='active'")->fetchColumn()) $fingerprintParts[]=$epoch;
        $fp = hash('sha256', Importer::json($fingerprintParts));
        $pendingEventId=null;
        $result=DB::transaction(function(PDO $p)use($u, $key, $fp, $action, $in, $epoch, &$pendingEventId) {
            $p->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            if ((string)$p->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn()!==$epoch)
                throw new RuntimeException('Данные восстановлены. Откройте форму заново.',409);
            $q = $p->prepare('SELECT * FROM rental_commands WHERE command_key=? FOR UPDATE');
            $q->execute([
                $key
            ]);
            if ($r = $q->fetch(PDO::FETCH_ASSOC)) {
                if ($r['fingerprint'] !== $fp)throw new RuntimeException('Идентификатор команды уже использован.');
                return json_decode($r['result_json'], true);
            }
            $eventContext = WorkflowEvents::before($p, $action, $in);
            $objectContext = ObjectActivity::before($p,$action,$in);
            $validatedPacket = NativePacket::validateBeforeB($p, $u, $action, $in);
            $r = self::execute($p, $u, $action, $in);
            $p->prepare('INSERT INTO rental_commands(command_key,action,fingerprint,result_json) VALUES(?,?,?,?)')->execute([
                $key,
                $action,
                $fp,
                Importer::json($r)
            ]);
            NativePacket::captureAfterB($p, $u, $action, $in, $r, $key, $fp, $validatedPacket);
            $pendingEventId=WorkflowEvents::record($p, $u, $action, $in, $r, $key, $eventContext);
            if ($objectContext!==null) $pendingEventId=ObjectActivity::record($p,$u,$in,$r,$key,$objectContext);
            return $r;
        });
        // Only the transaction owner can publish a completed commit. Nested fixtures/callers
        // retain their outer transaction and must never trigger network effects here.
        if(!DB::pdo()->inTransaction())$freshCommittedEventId=$pendingEventId;
        return $result;
    }

    public static function available(PDO $p, array $ids, string $start, string $end, ?int $except = null, ?int $partyId = null, bool $consume = false, ?int $bookingId = null, bool $sharedCustomerBookings = false, ?int $predecessor=null): void {
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $id) {
            $o = self::get($p, 'rental_objects', (int)$id);
            if (!$o['active'])throw new RuntimeException('Объект неактивен.');
            $parent = $o['cadastre_id']?self::get($p, 'cadastre', (int)$o['cadastre_id']):null;
            if (!$parent || !DirectoryScope::signals($o, $parent))throw new RuntimeException('Объект недоступен для аренды.');
            $q = $p->prepare("SELECT c.id FROM contract_objects co JOIN contracts c ON c.id=co.contract_id WHERE ".\App\ContractOccupancy::contractPredicate('co')." AND co.object_id=? AND c.id<>? AND c.status='reconciling' LIMIT 1 FOR UPDATE");
            $q->execute([
                $id,
                $except ?? 0
            ]);
            if ($q->fetchColumn())throw new RuntimeException('Финальная сверка: возврат помещения ещё не завершён.');
            $q = $p->prepare("SELECT h.contract_id FROM rental_object_holds h JOIN contracts c ON c.id=h.contract_id WHERE ".\App\ContractOccupancy::physicalPredicate('h')." AND h.object_id=? AND h.contract_id<>? AND h.return_confirmed=0 AND c.status<>'closed' LIMIT 1 FOR UPDATE");
            $q->execute([
                $id,
                $except ?? 0
            ]);
            if ($q->fetchColumn())throw new RuntimeException('Ожидается акт возврата исключённого помещения.');
            $q = $p->prepare("SELECT c.id FROM contract_objects co JOIN contracts c ON c.id=co.contract_id WHERE ".\App\ContractOccupancy::contractPredicate('co')." AND co.object_id=? AND c.status='active' AND ".Occupancy::boundary('start_date')."<=? AND ".Occupancy::boundary('end_date').">=? AND c.id<>? LIMIT 1 FOR UPDATE");
            $q->execute([
                $id,
                $end,
                $start,
                $except ?? 0
            ]);
            if ($q->fetchColumn())throw new RuntimeException('Объект занят договором на выбранный период.');
            $q=$p->prepare('SELECT c.id FROM contract_objects co JOIN contracts c ON c.id=co.contract_id
                WHERE '.\App\ContractOccupancy::contractPredicate('co').' AND co.object_id=? AND c.id<>? AND c.id<>? AND '
                .Occupancy::expiredWithoutReturn(Occupancy::boundary('end_date'),'LEAST(CURRENT_DATE,?)','?').' LIMIT 1 FOR UPDATE');
            $q->execute([$id,$except ?? 0,$predecessor ?? 0,$end,$start]);
            if ($q->fetchColumn()) throw new RuntimeException('Срок прежней аренды истёк. Сначала подтвердите возврат или связанное продолжение.',409);
            $q = $p->prepare("SELECT id,party_id,document_id FROM reservations r WHERE ".Occupancy::reservationPredicate('r')." AND object_id=? AND status='active' AND (contract_id IS NULL OR contract_id<>?) AND ((document_id IS NULL AND until_date>=CURRENT_DATE) OR (document_id IS NOT NULL AND start_date<=? AND end_date>=?)) FOR UPDATE");
            $q->execute([
                $id,
                $except ?? 0,
                $end,
                $start
            ]);
            foreach ($q->fetchAll() as $booking) {
                if ($sharedCustomerBookings && $booking['document_id']===null && (!$consume || $bookingId!==(int)$booking['id']))continue;
                if ($booking['document_id'] !== null || $partyId === null || ((int)$booking['party_id'] !== $partyId && !($bookingId === (int)$booking['id'] && $booking['party_id'] === null)))throw new RuntimeException('Объект уже в резервировании.');
                if ($consume)$p->prepare("UPDATE reservations SET status='consumed',version=version+1 WHERE id=?")->execute([
                    $booking['id']
                ]);
            }
        }
    }

    private static function saveTerms(PDO $p, int $id, array $ids, array $in, string $start, string $end, string $amount, ?array $previous = null): void {
        $priorTerms = [];
        if ($previous !== null) {
            $query = $p->prepare('SELECT * FROM contract_terms WHERE contract_id=? ORDER BY id');
            $query->execute([$id]);
            foreach ($query->fetchAll() as $term) {
                $priorTerms[(int)$term['object_id']] = $term;
            }
        }
        $sum = '0.00';
        $terms = [
        ];
        foreach ($ids as $oid) {
            $value = $in['term_amount'][$oid] ?? (count($ids) === 1?$amount:null);
            if ($value === null) {
                $q = $p->prepare('SELECT amount FROM contract_terms WHERE contract_id=? AND object_id=? ORDER BY id DESC LIMIT 1');
                $q->execute([
                    $id,
                    $oid
                ]);
                $value = $q->fetchColumn();
            }
            if ($value === false || $value === null)throw new RuntimeException('Укажите цену каждого помещения.');
            $value = self::money($value);
            if (bccomp($value, '0', 2) <= 0)throw new RuntimeException('Цена помещения должна быть положительной.');
            // The common date controls change only the matching boundary.
            // Existing individual terms are server-owned, including unknown dates.
            $prior = $priorTerms[(int)$oid] ?? null;
            $ts = $prior !== null && $start === $previous['start_date']
                ? $prior['start_date'] : $start;
            $te = $prior !== null && $end === $previous['end_date']
                ? $prior['end_date'] : $end;
            if ($ts !== null && $te !== null && $ts>$te)throw new RuntimeException('Некорректный срок помещения.');
            $sum = bcadd($sum, $value, 2);
            $terms[] = [
                $id,
                $oid,
                $ts,
                $te,
                $value
            ];
        }
        if (bccomp($sum, $amount, 2) !== 0)throw new RuntimeException('Сумма цен помещений не равна общей арендной плате.');
        $p->prepare('DELETE FROM contract_terms WHERE contract_id=?')->execute([
            $id
        ]);
        foreach ($terms as $term)$p->prepare("INSERT INTO contract_terms(contract_id,object_id,start_date,end_date,amount,source_json) VALUES(?,?,?,?,?,'{}')")->execute($term);
    }

    private static function execute(PDO $p, array $u, string $action, array $in, ?string $eventAction = null): array {
        if (isset(SecurityDeposit::TITLES[$action])) return SecurityDeposit::execute($p,$u,$action,$in);
        if (in_array($action,FinalReview::ACTIONS,true)) return FinalReview::execute($p,$u,$action,$in);
        if (in_array($action,FinalSettlement::ACTIONS,true)) return FinalSettlement::execute($p,$u,$action,$in);
        if ($action===ReturnAct::RECORD_ONE) return ReturnAct::recordOne($p,$in);
        if (in_array($action,ExpiryNotice::ACTIONS,true)) return ExpiryNotice::execute($p,$u,$action,$in);
        if ($action === UpdRegister::SAVE) return UpdRegister::save($p,$u,$in);
        if ($action === InvoiceContractAssignment::SAVE) return InvoiceContractAssignment::save($p,$u,$in);
        if ($action === DebtFollowUp::SAVE) return DebtFollowUp::write($p,$u,$in);
        if (in_array($action,RentalServices::ACTIONS,true)) return RentalServices::execute($p,$action,$in);
        if(in_array($action,['workspace.reset','workspace.undo','workspace.remove-test-object'],true))return WorkspaceReset::execute($p,$u,$action,$in);
        if ($action===TermEndCorrection::ACTION) return TermEndCorrection::execute($p,$u,$in);
        if ($action===ArchivedRentCorrection::ACTION) return ArchivedRentCorrection::execute($p,$u,$in);
        if ($action===SavedReceipt408::ACTION) return SavedReceipt408::execute($p,$u,$in);
        if ($action===OccupiedBilling::SAVE) return OccupiedBilling::saveActive($p,$u,$in);
        if ($action==='zero-point.correct') return ZeroPointCorrection::execute($p,$in);
        if ($action==='zero-point.continuity') return ZeroPointContinuity::execute($p,$in);
        if ($action==='zero-point.clarify') return ZeroPointClarification::execute($p,$in);
        if ($action==='zero-point.parties') return ZeroPointPartyEnrichment::execute($p,$in);
        $id = (int)($in['id'] ?? 0);
        foreach(['document.'=>'rental_documents','booking.'=>'reservations','reservation.'=>'reservations','object.'=>'rental_objects'] as $prefix=>$table) {
            if($id>0 && str_starts_with($action,$prefix) && WorkspaceReset::hidden(self::get($p,$table,$id)))throw new RuntimeException('Запись убрана из текущей работы.',404);
        }
        if (in_array($action,[PremisesInventory::SAVE,PremisesInventory::MOVE],true)) {
            return PremisesInventory::execute($p,$action,$in);
        }
        if ($action === 'contract.create' || $action === 'contract.edit') {
            self::roles([
                'manager'
            ]);
            $old = $action === 'contract.edit'?self::get($p, 'contracts', $id):null;
            $continuation=($old && $old['status']==='active') ? [] : ExpiryContinuation::context($p,$old,$in,'contract');
            if ($continuation && !$old && $continuation['link'])
                return ['id'=>$continuation['link']['id'],'message'=>'Связанный договор уже подготовлен.'];
            ExpiryContinuation::validate($continuation,$in,'contract');
            if ($old) {
                if(ContractDraft::deleted($old))throw new RuntimeException('Договор не найден.',404);
                self::version($old, $in);
                if (!in_array($old['status'], [
                    'draft',
                    'returned',
                    'active'
                ], true))throw new RuntimeException('Условия на согласовании менять нельзя.');
                if ($old['status']==='returned' && $old['return_to']==='lawyer')throw new RuntimeException('Исправление назначено юристу. Дождитесь передачи замечаний менеджеру.',409);
                if ($old['status'] === 'active' && array_diff(array_keys($in), [
                    'id',
                    'version',
                    'number',
                    'command_key',
                    'action'
                ]))throw new RuntimeException('Условия действующего договора меняются дополнительным соглашением.');
            }
            $number = trim((string)($in['number'] ?? ''));
            if ($old) {
                $source = json_decode($old['source_json'], true);
                if (!empty($source['imported']))throw new RuntimeException('Исходный договор доступен только для чтения; изменения оформляются ДС.');
                if ($number !== $old['number'] && $old['status']!=='draft')throw new RuntimeException('Номер существующего договора не изменяется.');
                if ($number==='')throw new RuntimeException('Укажите номер договора.',422);
            }
            elseif($number === '') {
                $numbers = $p->query('SELECT number FROM contracts')->fetchAll(PDO::FETCH_COLUMN);
                $max = \App\BusinessIds::numberFloor($p);
                foreach ($numbers as $n)if (preg_match('/^([0-9]+)/', $n, $m))$max = max($max, (int)$m[1]);
                $number = (string)($max+1);
            }
            $p->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            if (!$old || $number !== $old['number']) {
                $q = $p->prepare('SELECT id FROM contracts WHERE number=? AND id<>? AND '.ContractDraft::visibleSql('contracts').' LIMIT 1 FOR UPDATE');
                $q->execute([
                    $number,
                    $id
                ]);
                if ($q->fetchColumn())throw new RuntimeException('Номер уже используется другим договором. Исторические совпадения сохранены, новые не допускаются.');
            }
            if ($old)ContractHistory::remember($p, $old, $u, (string)$in['command_key']);
            if ($old && $old['status'] === 'active') {
                $p->prepare('UPDATE contracts SET number=?,version=version+1 WHERE id=?')->execute([
                    $number,
                    $id
                ]);
            }
            elseif (($in['intent'] ?? 'draft') !== 'submit') {
                $id = ContractDraft::save($p, $in, $old, $number);
            }
            else {
                $party = (int)($in['party_id'] ?? $old['party_id'] ?? 0);
                $pa = self::get($p, 'parties', $party);
                if (self::blockedPartyForNew($p,$pa))throw new RuntimeException('Работа с контрагентом с этим ИНН заблокирована.',422);
                $start = self::date($in['start_date'] ?? $old['start_date'] ?? '');
                $end = self::date($in['end_date'] ?? $old['end_date'] ?? '');
                if ($start>$end)throw new RuntimeException('Окончание раньше начала.');
                $amount = self::money($in['amount'] ?? $old['amount'] ?? '');
                $partRows = PartTerms::input($p, 'contract', $old?$id:0, $in, $start, $end);
                $partTotal = PartTerms::total($partRows ?? ($old?\App\BillingParts::terms($p, 'contract', $id):[]));
                $ids = array_values(array_unique(array_map('intval', (array)($in['objects'] ?? [
                ]))));
                if (!$ids && $old && !array_key_exists('part_terms_present', $in)) {
                    $q = $p->prepare('SELECT object_id FROM contract_objects WHERE contract_id=?');
                    $q->execute([
                        $id
                    ]);
                    $ids = $q->fetchAll(PDO::FETCH_COLUMN);
                }
                if (!$ids && bccomp($partTotal, '0', 2) <= 0)throw new RuntimeException('Выберите объекты.');
                $types=[];
                foreach ($ids as $oid)$types[]=self::get($p,'rental_objects',(int)$oid)['type'];
                $parkingTypes=TemplateAssets::PARKING_TYPES;
                if (array_intersect($types,$parkingTypes) && array_diff($types,$parkingTypes)
                    && (!$old || TemplateAssets::objectKind($p,(int)$old['id'])!=='mixed')) {
                    throw new RuntimeException('Оформите помещения и машино-места отдельными договорами.',422);
                }
                $partEdit = $partRows !== null && $old
                    ? \App\BillingPartTerms::beginEdit($p, 'contract', $id, (int)$old['version']) : null;
                $priorRelations=$old?Occupancy::relations($p,$id):[];
                $booking = null;
                if (!empty($in['booking_id'])) {
                    if ($old && (int)(ContractDraft::input($old)['booking_id'] ?? 0)!==(int)$in['booking_id'])throw new RuntimeException('Резервирование используется только при создании договора.');
                    $booking = self::get($p, 'reservations', (int)$in['booking_id']);
                    self::version($booking, [
                        'version' => $in['booking_version'] ?? 0
                    ]);
                    if ($booking['status'] !== 'active' || $booking['contract_id'] !== null || $booking['document_id'] !== null || $booking['until_date']<date('Y-m-d'))throw new RuntimeException('Резервирование уже изменено или истекло.', 409);
                    if ($booking['party_id']!==null && (int)$booking['party_id']!==$party)
                        throw new RuntimeException('Резервирование относится к другому клиенту.',422);
                }
                $exceptions=OccupiedBilling::prepare($p,$in,$old,array_map('intval',$ids),$start,$end,$u);
                $physicalIds=array_values(array_filter($ids,static function($oid) use($priorRelations,$exceptions): bool {
                    $relation=$priorRelations[(int)$oid] ?? [];
                    unset($relation['billing_exception']);
                    return !isset($exceptions[(int)$oid]) && Occupancy::physical($relation);
                }));
                if ($booking && isset($exceptions[(int)$booking['object_id']])) {
                    throw new RuntimeException('Расчётная строка не использует физическую бронь.',409);
                }
                self::available($p, $physicalIds, $start, $end, $old?$id:null, $party, false, null, true,$continuation['contract_id'] ?? null);
                if ($old)$p->prepare('UPDATE contracts SET number=?,party_id=?,start_date=?,end_date=?,amount=?,version=version+1 WHERE id=?')->execute([
                    $number,
                    $party,
                    $start,
                    $end,
                    $amount,
                    $id
                ]);
                else {
                    $id = \App\BusinessIds::next($p,'contracts',900000,false);
                    if ($partRows !== null) $partEdit = \App\BillingPartTerms::beginCreation($p, 'contract', $id, [
                        'actor_id' => (int)$u['id'], 'command_key' => (string)$in['command_key'],
                        'source_rule_sha256' => \App\BillingPartAdmission::NATIVE_SOURCE,
                        'parent_contract_id' => null, 'initial_status' => 'draft',
                    ]);
                    $p->prepare("INSERT INTO contracts(id,number,party_id,document_date,start_date,end_date,amount,status,source_json) VALUES(?,?,?,CURRENT_DATE,?,?,?,'draft','{}')")->execute([
                        $id,
                        $number,
                        $party,
                        $start,
                        $end,
                        $amount
                    ]);
                }
                $p->prepare('DELETE FROM contract_objects WHERE contract_id=?')->execute([
                    $id
                ]);
                foreach ($ids as $oid) Occupancy::insert($p,'contract_objects',[
                    'contract_id'=>$id,'object_id'=>$oid,
                ],$priorRelations[(int)$oid] ?? null);
                self::saveTerms($p, $id, $ids, $in, $start, $end, bcsub($amount, $partTotal, 2), $old);
                if ($exceptions !== OccupiedBilling::exceptions($old ?? [])) {
                    OccupiedBilling::store($p,$id,$exceptions);
                    self::event($p,$u,OccupiedBilling::SAVE,'Сохранены условия расчётных исключений проекта.',$id,
                        ['before'=>OccupiedBilling::exceptions($old ?? []),'after'=>$exceptions]);
                }
                if ($partEdit !== null) {
                    \App\BillingPartTerms::save($p, 'contract', $id, PartTerms::storageRows($partRows),
                        PartTerms::basis($u, (string)$in['command_key'], $old?(int)$old['version']+1:1, $partEdit, true, $partRows), $partEdit);
                    \App\BillingPartTerms::finish($p, $partEdit);
                }
                $date = !empty($in['document_date'])?self::date($in['document_date']):($old['document_date'] ?? null);
                $template = count(array_unique($types))>1?'mixed':(in_array($types[0] ?? '',$parkingTypes,true)?'parking':'office');
                $p->prepare('UPDATE contracts SET document_date=?,template_type=? WHERE id=?')->execute([
                    $date,
                    $template,
                    $id
                ]);
                if ($booking)$p->prepare("UPDATE reservations SET contract_id=?,party_id=?,status='consumed',version=version+1 WHERE id=?")->execute([
                    $id,
                    $party,
                    $booking['id']
                ]);
                $p->prepare("UPDATE contracts SET source_json=JSON_REMOVE(source_json,'$.draft_input') WHERE id=?")->execute([$id]);
                if (($in['intent'] ?? '') === 'submit')$p->prepare("UPDATE contracts SET status='legal',return_to=NULL,reason=NULL WHERE id=?")->execute([
                    $id
                ]);
            }
            ExpiryContinuation::bind($p,$continuation,$id,'contract');
            self::event($p, $u, $eventAction ?? $action, 'Сохранён договор №'
            . $number, $id);
            return [
                'id' => $id,
                'message' => 'Договор сохранён.'
            ];
        }
        if (str_starts_with($action, 'contract.')) {
            $c = self::get($p, 'contracts', $id);
            if(ContractDraft::deleted($c))throw new RuntimeException('Договор не найден.',404);
            self::version($c, $in);
            if (in_array($action,InitialInvoice::ACTIONS,true)) return InitialInvoice::execute($p,$u,$action,$c,$in);
            if ($action===LeasePaymentTerms::SAVE) return LeasePaymentTerms::execute($p,$u,$c,$in);
            if ($action===ContractRevision::ACTION) return ContractRevision::submit($p,$u,$c,$in);
            if($action==='contract.delete'){self::roles(['manager']);return ContractDraft::delete($p,$c,$u,(string)$in['command_key']);}
            if (in_array($action, HandoverQuestion::ACTIONS, true)) {
                return HandoverQuestion::execute($p, $u, $action, $c, $in);
            }
            if (in_array($action,[PremisesActs::COUNT,PremisesActs::CONFIRM],true)) {
                return PremisesActs::execute($p,$action,$c,$in);
            }
            $status = $c['status'];
            $next = $status;
            $return = null;
            $reason = null;
            $returnAct = null;
            $legalDecision = null;
            if ($action === 'contract.send') {
                $intent = Delivery::prepare($p, $u, $c);
                self::event($p, $u, $action, 'Подготовлена отправка пакета; подтверждение транспорта ещё не получено.', $id);
                return [
                    'id' => $id,
                    'intent' => $intent,
                    'message' => $intent['send_error'] ?? (in_array($intent['status'],['unknown','claimed'],true)
                        ?'Результат прежней отправки требует сверки. Повторная отправка запрещена.':'Пакет подготовлен. Отправка ещё не подтверждена.')
                ];
            }
            switch ($action) {
                case 'contract.submit':self::roles([
                    'manager'
                ]);
                if (!in_array($status, [
                    'draft',
                    'returned'
                ], true) || ($status === 'returned' && $c['return_to'] === 'lawyer'))throw new RuntimeException('Договор не готов к отправке юристу.');
                if (($draft = ContractDraft::input($c)) !== null) {
                    return self::execute($p, $u, 'contract.edit', array_merge($draft, ['id'=>$id,'version'=>$c['version'],'number'=>$c['number'],'command_key'=>$in['command_key'],'intent'=>'submit']), 'contract.submit');
                }
                $q = $p->prepare('SELECT object_id FROM contract_objects WHERE '.\App\ContractOccupancy::contractPredicate('contract_objects').' AND contract_id=?');
                $q->execute([
                    $id
                ]);
                ExpiryContinuation::beforeActivation($p,$c);
                self::available($p, $q->fetchAll(PDO::FETCH_COLUMN), $c['start_date'], $c['end_date'], $id, null, false, null, true,
                    ExpiryContinuation::origin($c)['contract_id'] ?? null);
                $next = 'legal';
                break;
                case 'contract.legal':self::roles([
                    'lawyer'
                ]);
                if (!LegalReview::assigned($c))throw new RuntimeException('Договор не у юриста.');
                PartyLegalReview::assertCurrent($p,(int)$c['party_id']);
                if (empty($in['counterparty_confirmed']) || empty($in['financial_confirmed']))throw new RuntimeException('Подтвердите контрагента и финансовые условия.');
                $legalFile = Evidence::latest($p, 'contract', $id, 'lawyer-file', (int)$c['version']);
                if (empty($in['document_confirmed']))throw new RuntimeException('Подтвердите проверку актуального файла договора.');
                if (($in['legal_file_id'] ?? '') !== $legalFile['id'])throw new RuntimeException('Файл договора изменился. Откройте проверку повторно и проверьте актуальный файл.',409);
                $fileId = $legalFile['id'];
                $signature = Evidence::approvalSignature($p, $id, $fileId);
                $p->prepare("INSERT INTO rental_approvals(entity_type,entity_id,role,baseline_hash,file_id,actor_id) VALUES('contract',?,'lawyer',?,?,?) ON DUPLICATE KEY UPDATE baseline_hash=VALUES(baseline_hash),file_id=VALUES(file_id),actor_id=VALUES(actor_id),confirmed_at=CURRENT_TIMESTAMP")->execute([
                    $id,
                    $signature,
                    $fileId,
                    $u['id']
                ]);
                $legalDecision=['version'=>(int)$c['version'],'file_id'=>$fileId,'file_sha256'=>$legalFile['sha256'],'baseline_hash'=>$signature];
                $next = 'accounting';
                break;
                case 'contract.return':self::roles($status === 'accounting'?[
                    'accountant'
                ]:[
                    'lawyer'
                ]);
                if (!in_array($status, [
                    'legal',
                    'accounting'
                ], true))throw new RuntimeException('Нет этапа для возврата.');
                $reason = trim($in['reason'] ?? '');
                if (!$reason)throw new RuntimeException('Укажите причину.');
                $return = $status === 'accounting'?'lawyer':'manager';
                $next = 'returned';
                break;
                case 'contract.forward':self::roles([
                    'lawyer'
                ]);
                if ($status !== 'returned' || $c['return_to'] !== 'lawyer')throw new RuntimeException('Исправление не назначено юристу.');
                $reason = trim($in['reason'] ?? '');
                if (!$reason)throw new RuntimeException('Укажите причину.');
                $return = 'manager';
                $next = 'returned';
                break;
                case 'contract.packet':self::roles([
                    'accountant'
                ]);
                if (!in_array($status,['accounting','approved'],true))throw new RuntimeException('Договор не у бухгалтера.');
                PartyLegalReview::assertCurrent($p,(int)$c['party_id']);
                if (empty($in['packet_confirmed']))
                    throw new RuntimeException('Откройте и проверьте актуальный пакет договора.',409);
                foreach ([
                    'counterparty_confirmed',
                    'financial_confirmed',
                    'document_confirmed'
                ] as $k)if (empty($in[$k]))throw new RuntimeException('Нужны три подтверждения бухгалтера.');
                $q = $p->prepare("SELECT * FROM rental_approvals WHERE entity_type='contract' AND entity_id=? AND role='lawyer'");
                $q->execute([
                    $id
                ]);
                $legal = $q->fetch();
                if (!$legal || $legal['baseline_hash'] !== Evidence::approvalSignature($p, $id, $legal['file_id']))throw new RuntimeException('Юрист должен подтвердить актуальный файл и условия.');
                $next = 'approved';
                break;
                case 'contract.sign':self::roles([
                    'manager'
                ]);
                if (!in_array($status, [
                    'sent',
                    'active'
                ], true))throw new RuntimeException('Подпись сейчас не ожидается.');
                if (empty($in['both_signed']))throw new RuntimeException('Подтвердите подписи обеих сторон.');
                $file = Evidence::latest($p, 'contract', $id, 'signed-contract', (int)$c['version']);
                if (!empty($in['actual_signature_date'])) LeasePaymentTerms::contractSigned($p,$u,$c,$file,(string)$in['actual_signature_date']);
                if($status!=='active')CustomerBookings::checkActivation($p,$c);
                $p->prepare('UPDATE contracts SET signed=1 WHERE id=?')->execute([
                    $id
                ]);
                $next = 'active';
                break;
                case 'contract.handed':self::roles([
                    'manager',
                    'accountant'
                ]);
                if ($status !== 'approved')throw new RuntimeException('Пакет не подготовлен.');
                if (empty($in['confirmed']))throw new RuntimeException('Подтвердите передачу пакета.');
                $next = 'sent';
                break;
                case 'contract.cancel':self::roles([
                    'manager',
                    'director'
                ]);
                if (in_array($status, [
                    'active',
                    'ended',
                    'closed'
                ], true))throw new RuntimeException('Действующий договор завершается через сверку.');
                if($status==='draft')throw new RuntimeException('Черновик нужно удалить, а не аннулировать.',409);
                $reason = trim($in['reason'] ?? '');
                if (!$reason)throw new RuntimeException('Укажите причину.');
                $next = 'cancelled';
                break;
                case 'contract.reconcile':self::roles([
                    'manager'
                ]);
                if ($status !== 'active')throw new RuntimeException('Договор не действует.');
                $reason = trim($in['reason'] ?? '');
                if (!$reason)throw new RuntimeException('Укажите причину завершения.');
                $finish = self::date($in['finish_date'] ?? $c['end_date'] ?? '');
                $q = $p->prepare("SELECT id FROM rental_documents WHERE contract_id=? AND applied=0 AND status<>'cancelled' LIMIT 1 FOR UPDATE");
                $q->execute([
                    $id
                ]);
                if ($q->fetchColumn())throw new RuntimeException('Сначала завершите незавершённое ДС.');
                $p->prepare('UPDATE contracts SET finish_date=?,finish_reason=?,finance_review=0,legal_review=0 WHERE id=?')->execute([
                    $finish,
                    $reason,
                    $id
                ]);
                $p->prepare('DELETE FROM rental_reviews WHERE contract_id=?')->execute([
                    $id
                ]);
                FinalSettlement::start($p,$id,$in);
                $next = 'reconciling';
                break;
                case 'contract.close':self::roles([
                    'accountant'
                ]);
                Evidence::finish($p, $id);
                $next = 'closed';
                break;
                case 'contract.return-act':self::roles([
                    'manager'
                ]);
                Access::contract($id, 2);
                if ($status !== 'reconciling')throw new RuntimeException('Договор не на сверке.', 409);
                if (OccupiedBilling::noPhysicalReturn($p,$c))throw new RuntimeException('Физический возврат не применяется.',409);
                if (empty($in['both_signed']))throw new RuntimeException('Подтвердите подписи обеих сторон.', 422);
                $actState = ReturnAct::state($p, $c);
                if ($actState['confirmed'])throw new RuntimeException('Акт возврата уже подтверждён.', 409);
                $file = $actState['file'] ?? throw new RuntimeException($actState['message'], 409);
                if (($in['file_id'] ?? null) !== $file['id']) {
                    throw new RuntimeException('Выбранный акт не относится к этому договору или был заменён. Откройте актуальную форму.', 409);
                }
                $returnDate = self::date($in['return_date'] ?? '');
                $signedOn=!empty($in['signed_on'])?FinalSettlement::signedDate($in):null;
                if ($signedOn===null && FinalSettlement::read($p,$id))
                    throw new RuntimeException('Укажите дату подписания двустороннего акта.',422);
                PremisesActs::recordReturn($p,$c,$file,$returnDate,$in);
                $returnAct = ['file_id'=>$file['id'], 'version'=>(int)$file['version'], 'sha256'=>$file['sha256'],
                    'baseline_hash'=>$file['baseline_hash'], 'return_date'=>$returnDate,'signed_on'=>$signedOn];
                ReturnAct::record($p,$c,$file,'both',$signedOn,'',$returnDate);
                $p->prepare('UPDATE contracts SET return_confirmed=? WHERE id=?')->execute([
                    $returnDate,
                    $id
                ]);
                $p->prepare('UPDATE rental_objects o JOIN contract_objects co ON co.object_id=o.id SET o.free_since=?,o.version=o.version+1 WHERE '.\App\ContractOccupancy::contractPredicate('co').' AND co.contract_id=?')->execute([
                    $returnDate,
                    $id
                ]);
                $p->prepare('UPDATE rental_object_holds SET return_confirmed=1,return_date=?,return_file_id=? WHERE contract_id=? AND return_confirmed=0')->execute([
                    $returnDate,
                    $file['id'],
                    $id
                ]);
                $next = Evidence::complete($p, $id, $file)?'closed':'reconciling';
                break;
                default:throw new RuntimeException('Неизвестная команда договора.');
            }
            $p->prepare('UPDATE contracts SET status=?,return_to=?,reason=?,version=version+? WHERE id=?')->execute([
                $next,
                $return,
                $reason,
                $next === 'closed' ? 0 : 1,
                $id
            ]);
            if ($next==='active' && $status!=='active')CustomerBookings::cancelOthers($p,$id);
            self::event($p, $u, $action, 'Договор №'
            . $c['number']
            . ': '
            . $status
            . ' → '
            . $next
            . ($reason?' · '
            . $reason:''), $id);
            Audit::record($action, 'contract', $id, $c, [
                'status' => $next
            ] + ($returnAct === null ? [] : ['return_act'=>$returnAct])
              + ($legalDecision === null ? [] : ['legal_decision'=>$legalDecision]));
            return [
                'id' => $id,
                'status' => $next,
                'message' => $returnAct === null ? 'Изменение сохранено.' : 'Акт возврата подтверждён.'
            ];
        }
        if ($action === 'payment.allocate') {
            self::roles([
                'accountant'
            ]);
            $payment = self::get($p, 'payments', (int)$in['payment_id']);
            SavedReceipt408::rejectManualChange($payment);
            NativeOperatingWriter::requirePayment($p,(int)$payment['id']);
            $invoice = self::get($p, 'invoices', $id);
            self::version($invoice, $in);
            NativeOperatingWriter::requireObligation($p,$invoice);
            $a = self::money($in['amount']);
            if (bccomp($a, '0', 2) <= 0)throw new RuntimeException('Сумма должна быть положительной.');
            $contract = self::get($p, 'contracts', (int)$invoice['contract_id']);
            if ($payment['party_id'] !== null && (int)$payment['party_id'] !== (int)$contract['party_id'])throw new RuntimeException('Платёж другого контрагента.');
            $q = $p->prepare('SELECT COALESCE(SUM(amount),0) FROM payment_allocations WHERE payment_id=?');
            $q->execute([
                $payment['id']
            ]);
            $available = bcsub($payment['amount'], (string)$q->fetchColumn(), 2);
            if ($invoice['status']==='revoked' || bccomp($a, $available, 2)>0 || $invoice['amount'] === null || $invoice['paid'] === null
                || bccomp($a,SecurityDeposit::payable($p,$invoice,$invoice['paid']),2)>0)throw new RuntimeException('Сумма превышает доступный остаток.');
            $p->prepare('INSERT INTO payment_allocations(payment_id,invoice_id,amount) VALUES(?,?,?) ON DUPLICATE KEY UPDATE amount=amount+VALUES(amount)')->execute([
                $payment['id'],
                $id,
                $a
            ]);
            FinanceService::remember($p, $invoice, (int)$u['id']);
            $p->prepare('UPDATE invoices SET paid=paid+?,version=version+1 WHERE id=?')->execute([
                $a,
                $id
            ]);
            Evidence::activation($p, (int)$contract['id']);
            self::event($p, $u, $action, 'Распределено '
            . $a
            . ' ₽ на счёт '
            . $invoice['number'], (int)$contract['id']);
            return [
                'message' => 'Оплата распределена.'
            ];
        }
        if ($action === 'payment.create') {
            self::roles([
                'accountant'
            ]);
            $party = self::get($p, 'parties', (int)$in['party_id']);
            $a = self::money($in['amount']);
            $date = self::date($in['payment_date']);
            $id = \App\BusinessIds::next($p,'payments',9000000,false);
            $creation=NativeOperatingWriter::begin($p,'payment',$id,'native-c-manual','service.payment.create',
                $date,(string)($in['command_key'] ?? 'payment:'.$id),(int)$u['id']);
            $p->prepare("INSERT INTO payments(id,party_id,amount,payment_date,source,source_json) VALUES(?,?,?,?,'Ручной приход','{}')")->execute([
                $id,
                $party['id'],
                $a,
                $date
            ]);
            NativeOperatingWriter::finish($p,'payment',$id,'native-c-manual',$creation);
            NativeOperatingWriter::requirePayment($p,$id);
            self::event($p, $u, $action, 'Зарегистрирован приход '
            . $a
            . ' ₽');
            return [
                'id' => $id,
                'message' => 'Приход зарегистрирован, распределение выполняется отдельно.'
            ];
        }
        if (in_array($action,[PartyLegalReview::SUBMIT,PartyLegalReview::ACCEPT,PartyLegalReview::RETURN],true))
            return PartyLegalReview::decision($p,$u,$action,$in);
        if ($action === 'party.block' || $action === 'party.unblock') {
            self::roles($action === 'party.unblock'?[
                'director'
            ]:[
                'manager',
                'lawyer',
                'accountant',
                'director'
            ]);
            $x = self::get($p, 'parties', $id);
            self::version($x, $in);
            $reason = trim($in['reason'] ?? '');
            if (!$reason)throw new RuntimeException('Укажите причину.');
            $blocked=$action==='party.block';
            if((bool)$x['blocked']===$blocked)return ['message'=>'Состояние уже установлено.'];
            $projects=$blocked?self::cancelBlockedProjects($p,$u,$id,$reason):[];
            $update=$p->prepare('UPDATE parties SET blocked=?,block_reason=?,version=version+1 WHERE id=? AND version=?');
            $update->execute([
                (int)$blocked,
                $reason,
                $id,
                $x['version']
            ]);
            if($update->rowCount()!==1)throw new RuntimeException('Контрагент изменён. Обновите карточку.',409);
            $after=array_replace($x,['blocked'=>$blocked,'block_reason'=>$reason,'version'=>(int)$x['version']+1]);
            self::event($p, $u, $action, $x['name']
            . ': '
            . $reason,null,['schema'=>1,'kind'=>'party-block-projects','command_key'=>$in['command_key'],
                'source_commit'=>'b99cd29d5ee76a96c546aae51fe3f16a6d67b349','extra'=>['before'=>$x,'after'=>$after,'projects'=>$projects]]);
            Audit::record($action,'party',$id,$x,$after+['cancelled_projects'=>$projects]);
            return [
                'message' => 'Статус контрагента сохранён.'
            ];
        }
        return ExtraService::execute($p, $u, $action, $in);
    }
}
