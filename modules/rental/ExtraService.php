<?php
declare(strict_types = 1);
namespace Modules\Rental;
use PDO;
use RuntimeException;
use App\Audit;
final class ExtraService {

    private static function next(PDO $p, string $table): int {
        return \App\BusinessIds::next($p,$table,9000000);
    }

    private static function update(PDO $p, string $table, int $id, array $fields): void {
        $set = [
        ];
        foreach ($fields as $k => $v)$set[] = '`'
        . $k
        . '`=?';
        $p->prepare('UPDATE '
        . $table
        . ' SET '
        . implode(',', $set)
        . ',version=version+1 WHERE id=?')->execute([
            ...array_values($fields),
            $id
        ]);
    }

    public static function execute(PDO $p, array $u, string $action, array $in): array {
        $id = (int)($in['id'] ?? 0);
        $message = 'Изменение сохранено.';
        if (str_starts_with($action, 'invoice.'))return FinanceService::execute($p, $u, $action, $in);
        if ($action==='organization.bank.save') return OrganizationBank::save($p,$u,$in);
        if (in_array($action, [
            'party.save',
            'cadastre.save',
            'object.save',
            'organization.save'
        ], true)) {
            Service::roles($action === 'organization.save' ? ['accountant'] : ['manager']);
            $table = match ($action) {
                'party.save' => 'parties', 'cadastre.save' => 'cadastre', 'object.save' => 'rental_objects', default => 'organization'
            };
            $old = $id?Service::get($p, $table, $id):null;
            if ($old)Service::version($old, $in);
            $fields = [
            ];
            if ($table === 'parties') {
                $fields=DirectoryRules::party($in,$old);
                if ($fields['inn']!=='') {
                    // Lock the identity range in the command transaction, including concurrent inserts.
                    $q=$p->query('SELECT id,inn,blocked FROM parties ORDER BY id FOR UPDATE');
                    foreach ($q->fetchAll() as $existing) {
                        if((int)$existing['id']===$id || preg_replace('/\D/','',(string)$existing['inn'])!==$fields['inn'])continue;
                        if($existing['blocked'])throw new RuntimeException('Работа с контрагентом с этим ИНН заблокирована.',422);
                        if(!$old || preg_replace('/\D/','',(string)$old['inn'])!==$fields['inn'])throw new RuntimeException('Контрагент с ИНН '.$fields['inn'].' уже существует. Выберите существующую карточку.',422);
                    }
                }
                if (!$old || array_intersect(['bank','account','bik','correspondent_account'],array_keys($in))) {
                    $in=array_replace($in,DirectoryRules::bank($in));
                }
            }
            elseif($table === 'cadastre') {
                if (trim((string)($in['floor'] ?? ''))==='') throw new RuntimeException('Укажите этаж.',422);
                $area = Service::area($in['area'] ?? '');
                if (bccomp($area, '0', 2) <= 0)throw new RuntimeException('Площадь должна быть положительной.');
                if ($id) {
                    $q = $p->prepare('SELECT COALESCE(SUM(area),0) FROM rental_objects WHERE cadastre_id=?');
                    $q->execute([
                        $id
                    ]);
                    if (bccomp(bcadd($area, '0.001', 4), (string)$q->fetchColumn(), 4)<0 && (!$old || $old['area'] === null || bccomp($area, $old['area'], 4)<0))throw new RuntimeException('Площадь меньше суммы дочерних объектов.');
                }
                $number = trim($in['number'] ?? '');
                if (!$number)throw new RuntimeException('Укажите кадастровый номер.');
                $q = $p->prepare('SELECT id FROM cadastre WHERE number=? AND id<>? LIMIT 1 FOR UPDATE');
                $q->execute([
                    $number,
                    $id
                ]);
                if ($q->fetchColumn() && (!$old || $old['number'] !== $number))throw new RuntimeException('Кадастровый объект с таким номером уже существует.');
                $fields = [
                    'number' => $number,
                    'area' => $area,
                    'floor' => $in['floor'] ?? '',
                    'type' => $in['type'] ?? 'Нежилое помещение',
                    'rentable' => DirectoryRules::boolean($in['rentable'] ?? 1)
                ];
            }
            elseif($table === 'rental_objects') {
                $parent = Service::get($p, 'cadastre', (int)($in['cadastre_id'] ?? 0));
                if (!DirectoryScope::realCadastre($parent) || !DirectoryScope::cadastreAvailable($parent))throw new RuntimeException('Кадастровый объект не разрешён для сдачи.');
                if (trim((string)($in['floor'] ?? ''))==='') throw new RuntimeException('Укажите этаж.',422);
                $area = Service::area($in['area'] ?? '');
                if (bccomp($area, '0', 2) <= 0)throw new RuntimeException('Площадь должна быть положительной.');
                $q = $p->prepare('SELECT COALESCE(SUM(area),0) FROM rental_objects WHERE cadastre_id=? AND id<>?');
                $q->execute([
                    $parent['id'],
                    $id
                ]);
                if ($parent['area'] === null || bccomp(bcadd((string)$q->fetchColumn(), $area, 4), bcadd($parent['area'], '0.001', 4), 4)>0 && (!$old || (int)$old['cadastre_id'] !== (int)$parent['id'] || $old['area'] === null || bccomp($area, $old['area'], 4)>0))throw new RuntimeException('Превышен остаток площади кадастрового объекта.');
                $active = DirectoryRules::boolean($in['active'] ?? 1);
                $fields = [
                    'name' => trim($in['name'] ?? ''),
                    'type' => $in['type'] ?? 'Офис',
                    'area' => $area,
                    'floor' => $in['floor'] ?? '',
                    'cadastre_id' => $parent['id'],
                    'active' => $active
                ];
                if (array_key_exists('free_since', $in)) {
                    $fields['free_since'] = $in['free_since'] === ''?null:Service::date($in['free_since']);
                    if ($fields['free_since'] !== null && $fields['free_since']>date('Y-m-d'))throw new RuntimeException('Дата освобождения не может быть в будущем.');
                }
                if (!$fields['name'])throw new RuntimeException('Укажите наименование объекта.');
                if ($old && (int)$old['active'] !== $active) {
                    $date = Service::date((string)($in['active_on'] ?? ''));
                    if ($date>date('Y-m-d')) throw new RuntimeException('Дата изменения состояния не может быть в будущем.',422);
                    $q=$p->prepare('SELECT MAX(started_on) FROM rental_object_periods WHERE object_id=?');$q->execute([$id]);
                    $last=$q->fetchColumn();
                    if ($last && $date<$last) throw new RuntimeException('Дата изменения состояния раньше последнего изменения.',422);
                    $p->prepare('UPDATE rental_object_periods SET ended_on=? WHERE object_id=? AND ended_on IS NULL')->execute([
                        $date,
                        $id
                    ]);
                    $p->prepare('INSERT INTO rental_object_periods(object_id,active,started_on,reason) VALUES(?,?,?,?)')->execute([
                        $id,
                        $active,
                        $date,
                        $in['reason'] ?? ''
                    ]);
                }
            }
            else {
                $fields=DirectoryRules::organization($in);
            }
            if ($old)self::update($p, $table, $id, $fields);
            else {
                $id = $table === 'organization'?1:self::next($p, $table);
                $fields = [
                    'id' => $id
                ]+$fields+[
                    'source_json' => '{}'
                ];
                $p->prepare('INSERT INTO '
                . $table
                . ' (`'
                . implode('`,`', array_keys($fields))
                . '`) VALUES('
                . implode(',', array_fill(0, count($fields), '?'))
                . ')')->execute(array_values($fields));
            }
            if ($table === 'rental_objects' && !$old)$p->prepare('INSERT INTO rental_object_periods(object_id,active,started_on,reason) VALUES(?,?,CURRENT_DATE,?)')->execute([
                $id,
                $fields['active'],
                $in['reason'] ?? 'Создание объекта'
            ]);
            foreach ($fields as $field => $value)$p->prepare('INSERT INTO rental_field_overrides(entity_type,entity_id,field_name,actor_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE actor_id=VALUES(actor_id),changed_at=CURRENT_TIMESTAMP')->execute([
                $table,
                $id,
                $field,
                $u['id']
            ]);
            if ($table === 'parties' && array_key_exists('account', $in)) {
                $q=$p->prepare('SELECT * FROM party_bank_accounts WHERE party_id=? AND operational=1 ORDER BY id LIMIT 1');
                $q->execute([$id]);
                $oldBank=$q->fetch();
                if ($oldBank && $in['bank']===DirectoryUi::bankName($oldBank)) $in['bank']=$oldBank['bank'];
                $account = trim($in['account']);
                if ($account && !preg_match('/^\d{20}$/D', $account))throw new RuntimeException('Банковский счёт должен содержать 20 цифр.');
                $p->prepare('UPDATE party_bank_accounts SET operational=0 WHERE party_id=?')->execute([
                    $id
                ]);
                if ($account)$p->prepare("INSERT INTO party_bank_accounts(party_id,bank,account,bik,correspondent_account,operational,source_json) VALUES(?,?,?,?,?,1,'{}')")->execute([
                    $id,
                    $in['bank'] ?? '',
                    $account,
                    $in['bik'] ?? '',
                    $in['correspondent_account'] ?? ''
                ]);
            }
            Service::event($p, $u, $action, 'Сохранена карточка '
            . $table
            . ' #'
            . $id);
            Audit::record($action, $table, $id, $old, $fields);
            return [
                'id' => $id,
                'message' => $message
            ];
        }
        if (str_starts_with($action, 'reservation.')) {
            Service::roles([
                'manager'
            ]);
            $old = $id?Service::get($p, 'reservations', $id):null;
            if ($old) {
                Service::version($old, $in);
                if ($old['document_id'] !== null)throw new RuntimeException('Бронь допсоглашения меняется через само ДС.');
            }
            if ($action === 'reservation.cancel') {
                if (!$old || $old['status'] !== 'active')throw new RuntimeException('Бронь неактивна.');
                $p->prepare("UPDATE reservations SET status='cancelled',version=version+1 WHERE id=?")->execute([
                    $id
                ]);
            }
            elseif($action === 'reservation.extend') {
                if (!$old || $old['status'] !== 'active')throw new RuntimeException('Бронь неактивна.');
                $until = Service::date($in['until_date'] ?? '');
                if ($until<date('Y-m-d'))throw new RuntimeException('Укажите будущую дату.');
                $p->prepare('UPDATE reservations SET until_date=?,version=version+1 WHERE id=?')->execute([
                    $until,
                    $id
                ]);
            }
            elseif($action === 'reservation.create') {
                if (trim((string)($in['client_name'] ?? '')) === '' && empty($in['party_id']))throw new RuntimeException('Укажите клиента для брони.',422);
                if (trim((string)($in['comment'] ?? '')) === '')throw new RuntimeException('Укажите описание брони.');
                $oid = (int)($in['object_id'] ?? 0);
                $party = !empty($in['party_id'])?(int)$in['party_id']:null;
                if ($party) {
                    $pa = Service::get($p, 'parties', $party);
                    if ($pa['blocked'])throw new RuntimeException('Контрагент заблокирован.');
                }
                $start = Service::date($in['start_date'] ?? date('Y-m-d'));
                $until = Service::date($in['until_date'] ?? '');
                if ($until<date('Y-m-d'))throw new RuntimeException('Бронь уже истекла.');
                Service::available($p, [
                    $oid
                ], $start, $start, null, null, false, null, true);
                $id = self::next($p, 'reservations');
                $p->prepare("INSERT INTO reservations(id,object_id,party_id,client_name,author_id,start_date,end_date,until_date,status,comment,source_json) VALUES(?,?,?,?,?,?,NULL,?,'active',?,'{}')")->execute([
                    $id,
                    $oid,
                    $party,
                    trim($in['client_name'] ?? ''),
                    $u['id'],
                    $start,
                    $until,
                    $in['comment'] ?? ''
                ]);
            }
            else throw new RuntimeException('Неизвестное действие брони.');
            Service::event($p, $u, $action, 'Изменена бронь #'
            . $id);
            return [
                'id' => $id,
                'message' => $action === 'reservation.cancel'?'Бронь снята.':'Бронь сохранена.'
            ];
        }
        if (str_starts_with($action, 'template.')) return TemplateService::execute($p,$u,$action,$in);
        if (str_starts_with($action, 'document.'))return self::document($p, $u, $action, $in);
        if ($action === 'reconcile.finance' || $action === 'reconcile.legal') {
            $c = Service::get($p, 'contracts', $id);
            Service::version($c, $in);
            if (!in_array($c['status'], [
                'reconciling',
                'ended'
            ], true))throw new RuntimeException('Договор не на финальной сверке.');
            $field = $action === 'reconcile.finance'?'finance_review':'legal_review';
            Service::roles($field === 'finance_review'?[
                'accountant'
            ]:[
                'lawyer'
            ]);
            if (empty($in['confirmed']))throw new RuntimeException('Подтвердите результат сверки.');
            $field === 'finance_review' && $p->prepare('UPDATE invoices SET manual_review=0,control_closed=1 WHERE contract_id=? AND status=\'active\'')->execute([
                $id
            ]);
            $signature = Evidence::signature($p, $id);
            $p->prepare('INSERT INTO rental_reviews(contract_id,kind,baseline_hash,actor_id) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE baseline_hash=VALUES(baseline_hash),actor_id=VALUES(actor_id),confirmed_at=CURRENT_TIMESTAMP')->execute([
                $id,
                $field === 'finance_review'?'finance':'legal',
                $signature,
                $u['id']
            ]);
            $p->prepare('UPDATE contracts SET '
            . $field
            . '=1,version=version+1 WHERE id=?')->execute([
                $id
            ]);
            Service::event($p, $u, $action, 'Проверка финальной сверки: '
            . $field, $id);
            if (Evidence::complete($p, $id))Service::event($p, $u, 'contract.completed', 'Бухгалтер, юрист и акт возврата подтверждены; договор завершён', $id);
            return [
                'message' => $message
            ];
        }
        throw new RuntimeException('Неизвестное действие.');
    }

    private static function document(PDO $p, array $u, string $action, array $in): array {
        $id = (int)($in['id'] ?? 0);
        $d = $id?Service::get($p, 'rental_documents', $id):null;
        if ($d)Service::version($d, $in);
        $c = Service::get($p, 'contracts', (int)($d['contract_id'] ?? $in['contract_id'] ?? 0));
        if(ContractDraft::deleted($c))throw new RuntimeException('Договор не найден.',404);
        if ($action==='document.send') {
            if (!$d) throw new RuntimeException('Документ не найден.',404);
            Service::roles(['accountant']);
            $intent=Delivery::prepareDocument($p,$u,$d);
            Service::event($p,$u,$action,'Подготовлена отправка ДС и счетов; подтверждение транспорта ещё не получено.',(int)$c['id']);
            return ['id'=>$id,'intent'=>$intent,'message'=>$intent['send_error'] ?? (in_array($intent['status'],['unknown','claimed'],true)
                ?'Результат прежней отправки требует сверки. Повторная отправка запрещена.':'Пакет ДС подготовлен. Отправка ещё не подтверждена.')];
        }
        if ($action === 'document.return-removed') {
            Service::roles([
                'manager'
            ]);
            if (!$d || !$d['applied'] || $d['status'] !== 'signed' || empty($in['both_signed']))throw new RuntimeException('Нужен подписанный акт возврата исключённых помещений.');
            $date = Service::date($in['return_date'] ?? '');
            if ($date<$d['effective_date'] || $date>date('Y-m-d'))throw new RuntimeException('Проверьте дату фактического возврата.');
            $file = Evidence::latest($p, 'document', $id, 'removed-return-act', (int)$d['version']);
            $p->prepare('UPDATE rental_object_holds SET return_confirmed=1,return_date=?,return_file_id=? WHERE document_id=? AND return_confirmed=0')->execute([
                $date,
                $file['id'],
                $id
            ]);
            $p->prepare('UPDATE rental_objects o JOIN rental_object_holds h ON h.object_id=o.id SET o.free_since=?,o.version=o.version+1 WHERE '.\App\ContractOccupancy::physicalPredicate('h').' AND h.document_id=? AND h.return_confirmed=1')->execute([
                $date,
                $id
            ]);
            $p->prepare('UPDATE rental_documents SET version=version+1 WHERE id=?')->execute([
                $id
            ]);
            Service::event($p, $u, $action, 'Загружен акт возврата исключённых помещений по ДС №'
            . $d['number'], (int)$c['id']);
            return [
                'id' => $id,
                'message' => 'Фактический возврат помещений подтверждён.'
            ];
        }
        if ($action === 'document.apply' && $d && $d['applied'])return [
            'id' => $id,
            'message' => 'Условия уже применены; повторного начисления нет.'
        ];
        if (in_array($c['status'], [
            'closed',
            'cancelled'
        ], true))throw new RuntimeException('Работа по договору завершена.');
        if ($d && $d['base_hash'] !== Evidence::documentBase($p, (int)$c['id']))throw new RuntimeException('Исходные условия договора изменились. Создайте ДС по актуальным условиям.');
        if (in_array($action, [
            'document.create',
            'document.edit'
        ], true)) {
            Service::roles([
                'manager'
            ]);
            if ($c['status'] !== 'active')throw new RuntimeException('Допсоглашение создаётся к действующему договору.');
            if ($d && (!in_array($d['status'], [
                'draft',
                'returned'
            ], true) || $d['applied'] || ($d['status'] === 'returned' && $d['return_to'] !== 'manager')))throw new RuntimeException('Редактирование ДС не назначено менеджеру.');
            if (isset($in['base_version']) && (int)$in['base_version'] !== (int)$c['version'])throw new RuntimeException('Исходный договор изменился.');
            $q = $p->prepare("SELECT id FROM rental_documents WHERE contract_id=? AND id<>? AND applied=0 AND status<>'cancelled' LIMIT 1 FOR UPDATE");
            $q->execute([
                $c['id'],
                $id
            ]);
            if ($q->fetchColumn())throw new RuntimeException('По договору уже есть незавершённое ДС.');
            $kind = $in['kind'] ?? $d['kind'] ?? 'renewal';
            if (!in_array($kind, [
                'renewal',
                'termination'
            ], true))throw new RuntimeException('Тип допсоглашения неизвестен.');
            $effective = Service::date($in['effective_date'] ?? $d['effective_date'] ?? '');
            $terms = Addenda::terms($p, $c, $in, $d);
            if ($terms['end']<$effective)throw new RuntimeException('Срок помещений заканчивается раньше вступления ДС в силу.');
            $number = trim($in['number'] ?? $d['number'] ?? '');
            $date = Service::date($in['document_date'] ?? $d['document_date'] ?? '');
            if (!$number)throw new RuntimeException('Укажите номер ДС.');
            $status = ($in['intent'] ?? 'draft') === 'submit'?'legal':'draft';
            if ($d && $d['status'] === 'returned' && $status === 'draft')throw new RuntimeException('Доработанное ДС нужно повторно направить на согласование.');
            $baseDate = !empty($in['base_date'])?Service::date($in['base_date']):($d['base_date'] ?? $c['document_date']);
            $partEdit = null;
            if ($d) {
                $snapshot = $d;
                $partSnapshot = \App\BillingPartHistory::snapshot($p, 'document', $id, (int)$d['version']);
                if ($partSnapshot !== null) $snapshot['part_terms'] = $partSnapshot;
                if (array_key_exists('part_rows', $terms)) {
                    $partEdit = \App\BillingPartTerms::beginEdit($p, 'document', $id, (int)$d['version']);
                }
                $p->prepare('INSERT INTO rental_document_versions(document_id,version,snapshot_json,actor_id) VALUES(?,?,?,?)')->execute([
                    $id,
                    $d['version'],
                    Importer::json($snapshot),
                    $u['id']
                ]);
                $p->prepare('UPDATE rental_documents SET number=?,document_date=?,effective_date=?,start_date=?,end_date=?,amount=?,status=?,return_to=NULL,reason=NULL,version=version+1 WHERE id=?')->execute([
                    $number,
                    $date,
                    $effective,
                    $terms['start'],
                    $terms['end'],
                    $terms['amount'],
                    $status,
                    $id
                ]);
                $p->prepare("DELETE FROM rental_approvals WHERE entity_type='document' AND entity_id=?")->execute([
                    $id
                ]);
            }
            else {
                $id = self::next($p, 'rental_documents');
                if (array_key_exists('part_rows', $terms)) {
                    $partEdit = \App\BillingPartTerms::beginCreation($p, 'document', $id, [
                        'actor_id' => (int)$u['id'], 'command_key' => (string)$in['command_key'],
                        'source_rule_sha256' => \App\BillingPartAdmission::NATIVE_SOURCE,
                        'parent_contract_id' => (int)$c['id'], 'initial_status' => $status,
                    ]);
                }
                $p->prepare("INSERT INTO rental_documents(id,contract_id,number,kind,status,effective_date,document_date,start_date,end_date,amount,base_version,base_hash,source_json) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'{}')")->execute([
                    $id,
                    $c['id'],
                    $number,
                    $kind,
                    $status,
                    $effective,
                    $date,
                    $terms['start'],
                    $terms['end'],
                    $terms['amount'],
                    $c['version'],
                    Evidence::documentBase($p,
                    (int)$c['id'])
                ]);
            }
            if (!$d)$p->prepare("UPDATE rental_documents SET source_json=JSON_SET(source_json,'$.entryMode','current') WHERE id=?")->execute([
                $id
            ]);
            $p->prepare('UPDATE rental_documents SET base_date=? WHERE id=?')->execute([
                $baseDate,
                $id
            ]);
            Addenda::saveTerms($p, $id, $terms['rows']);
            if ($partEdit !== null) {
                \App\BillingPartTerms::save($p, 'document', $id, PartTerms::storageRows($terms['part_rows']),
                    PartTerms::basis($u, (string)$in['command_key'], $d?(int)$d['version']+1:1, $partEdit,
                        $terms['replace_part_branch'] ?? false, $terms['part_rows']), $partEdit);
                \App\BillingPartTerms::finish($p, $partEdit);
            }
            if ($status === 'legal')Addenda::reserve($p, $c, $id);
            Service::event($p, $u, $action, 'Сохранено ДС №'
            . $number
            . ' к договору '
            . $c['number'], (int)$c['id']);
            return [
                'id' => $id,
                'message' => $status === 'legal'?'ДС направлено юристу.':'Черновик ДС сохранён.'
            ];
        }
        if (!$d)throw new RuntimeException('Документ не найден.', 404);
        $status = $d['status'];
        $next = $status;
        $return = null;
        $reason = null;
        switch ($action) {
            case 'document.submit':Service::roles([
                'manager'
            ]);
            if (!in_array($status, [
                'draft',
                'returned'
            ], true) || ($status === 'returned' && $d['return_to'] !== 'manager'))throw new RuntimeException('ДС не готово к согласованию.');
            Addenda::reserve($p, $c, $id);
            $next = 'legal';
            break;
            case 'document.legal':Service::roles([
                'lawyer'
            ]);
            if (!($status === 'legal' || ($status === 'returned' && $d['return_to'] === 'lawyer')))throw new RuntimeException('ДС не у юриста.');
            $q = $p->prepare('SELECT * FROM document_terms WHERE document_id=? ORDER BY object_id');
            $q->execute([
                $id
            ]);
            foreach ($q->fetchAll() as $t)if (Occupancy::physical($t))Service::available($p, [
                (int)$t['object_id']
            ], $t['start_date'], $t['end_date'], (int)$c['id']);
            $file = Evidence::latest($p, 'document', $id, 'lawyer-file', (int)$d['version']);
            $sig = Evidence::documentSignature($p, $d, $file['id']);
            $p->prepare("INSERT INTO rental_approvals(entity_type,entity_id,role,baseline_hash,file_id,actor_id) VALUES('document',?,'lawyer',?,?,?) ON DUPLICATE KEY UPDATE baseline_hash=VALUES(baseline_hash),file_id=VALUES(file_id),actor_id=VALUES(actor_id)")->execute([
                $id,
                $sig,
                $file['id'],
                $u['id']
            ]);
            $next = 'accounting';
            break;
            case 'document.return':Service::roles($status === 'accounting'?[
                'accountant'
            ]:[
                'lawyer'
            ]);
            if (!in_array($status, [
                'legal',
                'accounting'
            ], true))throw new RuntimeException('ДС не на согласовании.');
            $reason = trim($in['reason'] ?? '');
            if (!$reason)throw new RuntimeException('Укажите причину.');
            $next = 'returned';
            $return = $status === 'accounting'?'lawyer':'manager';
            $p->prepare("DELETE FROM rental_approvals WHERE entity_type='document' AND entity_id=?")->execute([
                $id
            ]);
            break;
            case 'document.forward':Service::roles([
                'lawyer'
            ]);
            if ($status !== 'returned' || $d['return_to'] !== 'lawyer')throw new RuntimeException('Замечания не назначены юристу.');
            $reason = trim($in['reason'] ?? $d['reason'] ?? '');
            if (!$reason)throw new RuntimeException('Укажите причину.');
            $next = 'returned';
            $return = 'manager';
            break;
            case 'document.packet':Service::roles([
                'accountant'
            ]);
            if (!in_array($status,['accounting','approved'],true))throw new RuntimeException('ДС не у бухгалтера.');
            foreach ([
                'counterparty_confirmed',
                'financial_confirmed',
                'document_confirmed'
            ] as $key)if (empty($in[$key]))throw new RuntimeException('Нужны три подтверждения.');
            $q = $p->prepare("SELECT * FROM rental_approvals WHERE entity_type='document' AND entity_id=? AND role='lawyer'");
            $q->execute([
                $id
            ]);
            $approval = $q->fetch();
            if (!$approval || $approval['baseline_hash'] !== Evidence::documentSignature($p, $d, $approval['file_id']))throw new RuntimeException('Юрист должен подтвердить актуальный файл ДС.');
            if ($status==='accounting') AddendumBilling::issue($p, $c, $d);
            $next = 'approved';
            break;
            case 'document.handed':Service::roles([
                'manager',
                'accountant'
            ]);
            if ($status !== 'approved' || empty($in['confirmed']))throw new RuntimeException('Подтвердите личную передачу согласованного ДС.');
            $next = 'sent';
            break;
            case 'document.sign':Service::roles([
                'manager'
            ]);
            if ($status !== 'sent' || empty($in['both_signed']))throw new RuntimeException('Нужен файл ДС с подписями обеих сторон.');
            $signedFile = Evidence::latest($p, 'document', $id, 'signed-addendum', (int)$d['version']);
            Evidence::signedDocumentParts($p, $d, $signedFile);
            $next = 'signed';
            if ($d['effective_date'] <= date('Y-m-d')) {
                if ($d['kind'] === 'termination')$p->prepare("UPDATE contracts SET status='reconciling',finish_date=?,finish_reason=?,version=version+1 WHERE id=?")->execute([
                    $d['effective_date'],
                    'Подписанное соглашение о завершении',
                    $c['id']
                ]);
                else Addenda::apply($p, $c, $d, 'document.sign', $u, (string)$in['command_key']);
                $p->prepare('UPDATE rental_documents SET applied=1 WHERE id=?')->execute([
                    $id
                ]);
            }
            break;
            case 'document.apply':Service::roles([
                'accountant'
            ]);
            if ($status !== 'signed' || $d['effective_date']>date('Y-m-d'))throw new RuntimeException('Подписанные условия ещё не вступили в силу.');
            if (\App\BillingPartTerms::state($p, 'document', $id)!==null || \App\BillingParts::terms($p, 'document', $id)) {
                $signedFile = Evidence::latest($p, 'document', $id, 'signed-addendum', (int)$d['version']-1);
                Evidence::signedDocumentParts($p, $d, $signedFile);
            }
            if ($d['kind'] === 'termination')$p->prepare("UPDATE contracts SET status='reconciling',finish_date=?,finish_reason=?,version=version+1 WHERE id=?")->execute([
                $d['effective_date'],
                'Подписанное соглашение о завершении',
                $c['id']
            ]);
            else Addenda::apply($p, $c, $d, 'document.apply', $u, (string)$in['command_key']);
            $p->prepare('UPDATE rental_documents SET applied=1 WHERE id=?')->execute([
                $id
            ]);
            $next = 'signed';
            break;
            case 'document.cancel':Service::roles([
                'manager',
                'director'
            ]);
            if ($d['applied'])throw new RuntimeException('Применённое ДС нельзя аннулировать.');
            $reason = trim($in['reason'] ?? '');
            if (!$reason)throw new RuntimeException('Укажите причину.');
            $next = 'cancelled';
            $p->prepare("UPDATE reservations SET status='cancelled',version=version+1 WHERE document_id=? AND status='active'")->execute([
                $id
            ]);
            break;
            default:throw new RuntimeException('Неизвестное действие с ДС.');
        }
        $p->prepare('UPDATE rental_documents SET status=?,return_to=?,reason=?,version=version+1 WHERE id=?')->execute([
            $next,
            $return,
            $reason,
            $id
        ]);
        Service::event($p, $u, $action, 'ДС №'
        . $d['number']
        . ': '
        . Controller::status($next), (int)$c['id']);
        return [
            'id' => $id,
            'message' => 'Допсоглашение сохранено.'
        ];
    }
}
