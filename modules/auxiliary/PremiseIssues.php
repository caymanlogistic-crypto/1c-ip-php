<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\{Audit,Auth,DB,PagePolicy};
use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** Source S premise causes/resolutions. Financial condition/history causes are separate. */
final class PremiseIssues
{
    private const CATEGORY = 'linked-premise';

    private static function read(): void
    {
        Auth::requirePermission('rental.read');
        $user = Auth::user();
        if (!empty($user['grants_configured']) && PagePolicy::level('auxiliary.premises', $user) < 1) throw new RuntimeException('Недостаточно прав для просмотра помещений.', 403);
    }

    private static function rows(PDO $pdo, string $sql, array $arguments = []): array
    {
        $query = $pdo->prepare($sql); $query->execute($arguments);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function evidence(PDO $pdo, array $units): array
    {
        $result = [];
        foreach (self::rows($pdo, 'SELECT * FROM aux_owner_evidence ORDER BY sequence_number,id') as $row) {
            $row['payload'] = Importer::decodeExact($row['payload']);
            $result[$row['id']] = $row;
        }
        return $result;
    }

    private static function inventory(array $objects, array $units, array $evidence): ?array
    {
        if (!$evidence) return null;
        $latest = end($evidence);
        $target = $latest['payload']['current_declaration'] ?? null;
        if (!is_array($target) || !isset($target['nonparking_objects'], $target['parking'])) throw new RuntimeException('Не установлено доказательное заявление о составе объектов.');
        $byId = array_column($objects, null, 'id');
        $counts = []; $cards = [];
        foreach ($objects as $row) {
            if ($row['kind'] !== 'Техническое помещение') $counts[$row['kind']] = ($counts[$row['kind']] ?? 0) + 1;
            foreach (Importer::decodeExact($row['source_ids']) as $id) $cards[(string)$id] = true;
        }
        $current = $canonical = $groups = []; $sold = []; $nominal = []; $historical = []; $parking = 0; $dated = 0; $unmapped = 0;
        foreach ($units as $unit) {
            $row = $byId[$unit['premise_id']] ?? throw new RuntimeException('Не найдена исходная позиция сопоставления.');
            $root = (int)($unit['canonical_premise_id'] ?? $unit['premise_id']);
            if ($row['kind'] === 'Помещение') $canonical[$root] = true;
            if ($unit['role'] === 'PARKING' && $unit['ownership'] === 'CURRENT') $parking++;
            if ($unit['ownership'] === 'SOLD') { $sold[] = $row['stable_id']; if ($unit['sale_date'] !== null) $dated++; }
            if ($unit['role'] === 'NOMINAL_SERVICE') $nominal[] = $row['stable_id'];
            if ($unit['role'] === 'RENTAL_PART') {
                if ($unit['ownership'] === 'HISTORICAL_PART') $historical[] = $row['stable_id'];
                else {
                    $current[$root] = true;
                    if ($unit['legal_unit_key'] !== '') $groups[$unit['legal_unit_key']] = true;
                    else $unmapped++;
                }
            }
        }
        $classified = !empty($latest['payload']['classification_policy']) && count($current) === (int)$target['nonparking_objects'];
        return [
            'evidence' => $latest['evidence_key'], 'received_on' => $latest['received_on'],
            'declared_nonparking' => (int)$target['nonparking_objects'], 'declared_parking' => (int)$target['parking'],
            'historical_positions' => array_sum($counts), 'historical_rooms' => $counts['Помещение'] ?? 0,
            'historical_parking' => $counts['Машино-место'] ?? 0, 'services' => $counts['Юридический адрес'] ?? 0,
            'technical_positions' => count(array_filter($objects, fn($row) => $row['kind'] === 'Техническое помещение')),
            'source_cards' => count($cards), 'identified_current_parking' => $parking, 'sold_ids' => $sold,
            'dated_sales' => $dated, 'nonparking_groups_confirmed' => count($groups), 'nonparking_parts_unmapped' => $unmapped,
            'canonical_nonparking_units' => count($canonical), 'candidate_current_nonparking_units' => count($current),
            'historical_part_ids' => $historical, 'raw_nonparking_difference' => ($counts['Помещение'] ?? 0) - (int)$target['nonparking_objects'],
            'numerical_nonparking_difference' => count($current) - (int)$target['nonparking_objects'],
            'nominal_service_ids' => $nominal, 'legal_address_positions' => count($nominal) + ($counts['Юридический адрес'] ?? 0),
            'ownership_boundaries_verified' => false, 'nonparking_fully_reconciled' => $classified,
            'parking_set_reconciled' => $parking === (int)$target['parking'], 'second_sold_parking_identified' => false,
            'full_match' => $classified && $parking === (int)$target['parking'],
        ];
    }

    /** No stored counter is authority: causes are recalculated from current typed rows. */
    public static function state(?array $objects = null, ?array $unitRows = null): array
    {
        self::read();
        $pdo = DB::pdo();
        $objects ??= self::rows($pdo, 'SELECT * FROM aux_confirmed_premises ORDER BY stable_id');
        $unitRows ??= self::rows($pdo, 'SELECT * FROM aux_owner_units ORDER BY id');
        $units = array_column($unitRows, null, 'premise_id');
        $evidence = self::evidence($pdo, $units);
        $resolved = [];
        foreach (self::rows($pdo, 'SELECT * FROM aux_issues WHERE category=?', [self::CATEGORY]) as $row) {
            $proof = Importer::decodeExact($row['evidence']);
            if (isset($proof['issue_key'])) $resolved[$proof['issue_key']] = ['row' => $row, 'proof' => $proof];
        }
        $issues = []; $entity = [];
        $add = function(string $key, string $label, mixed $payload, array $entities) use(&$issues, &$entity, $resolved): void {
            $fingerprint = hash('sha256', Importer::canonicalJson(['native_format' => 'linked-premise-v1', 'source_payload' => $payload]));
            $resolution = $resolved[$key] ?? null;
            if (!str_starts_with($key, 'inventory:') && $resolution && $resolution['row']['state'] === 'confirmed'
                && ($resolution['proof']['fingerprint'] ?? null) === $fingerprint
                && trim($resolution['proof']['evidence'] ?? '') !== '' && trim($resolution['row']['note'] ?? '') !== '') return;
            $issues[$key] = ['key' => $key, 'label' => $label, 'fingerprint' => $fingerprint, 'entities' => $entities, 'payload' => $payload];
            foreach ($entities as $id) $entity[$id][$key] = true;
        };
        foreach ($objects as $row) {
            $id = $row['stable_id']; $text = mb_strtolower($row['questions']); $recognized = false;
            $owner = $units[$row['id']] ?? null;
            $ownerEvidence = $owner ? ($evidence[$owner['evidence_id']] ?? null) : null;
            $facts = $ownerEvidence['payload']['facts'] ?? [];
            $fact = function(string $key) use($facts): ?array { foreach ($facts as $row) if (($row['key'] ?? null) === $key) return $row; return null; };
            $confirmedCadastre = null;
            foreach ($facts as $value) if (($value['canonical'] ?? null) === $id && in_array($value['key'], ['cad110','cad113'], true)) { $confirmedCadastre = $value['value'] ?? null; break; }
            if ($owner && $owner['ownership'] === 'SOLD') {
                $recognized = true;
                if ($owner['sale_date'] === null) $add('premise:'.$id.':sale-date', 'Продано; дата продажи не установлена. Исторические периоды не обрезаны.', [$ownerEvidence['evidence_key'], null], [$id]);
            }
            if ($row['area'] === null) { $add('premise:'.$id.':area', 'Не установлена площадь: '.$row['area_variants'], [null, $row['area_variants']], [$id]); $recognized = true; }
            elseif (str_contains($text, 'площад')) $recognized = true;
            if (str_contains($text, 'кадастр') || ($row['cadastral_number'] === '' && in_array($row['kind'], ['Помещение','Машино-место'], true))) {
                $recognized = true;
                $paired = in_array($id, ['OBJ-110','OBJ-113'], true) && $row['cadastral_number'] === '77:07:0013001:4984';
                if (($paired || $row['cadastral_number'] === '' || ($confirmedCadastre !== null && $row['cadastral_number'] !== $confirmedCadastre))
                    && !($confirmedCadastre !== null && $row['cadastral_number'] === $confirmedCadastre)) $add('premise:'.$id.':cadastral', 'Кадастровый номер требует уточнения', [$row['cadastral_number']], [$id]);
            }
            if (str_contains($text, 'обознач')) {
                $recognized = true;
                $matches = [];
                foreach ($objects as $other) if ($other['stable_id'] !== $id && $other['floor'] === $row['floor'] && $other['designation'] === $row['designation']) $matches[] = $other['stable_id'];
                sort($matches, SORT_STRING);
                $distinct = $fact('distinct-4'); $part = $fact('dltk-40-part');
                $resolvedNumber = ($distinct && in_array($id, $distinct['members'], true)) || ($part && in_array($id, [$part['historical_part'], $part['current_unit']], true));
                if ($matches && !$resolvedNumber) $add('premise:'.$id.':designation', 'Совпадает обозначение разных объектов', [$row['designation'],$row['floor'],$matches], [$id]);
            }
            if (str_contains($text, 'являются') || str_contains($text, 'родител')) {
                $recognized = true; $identity = $fact('identity-6-406');
                $known = $identity && in_array($id, [...$identity['members'], ...$identity['distinct']], true);
                if ((!$owner || $owner['role'] !== 'SERVICE') && !$known) $add('premise:'.$id.':identity', 'Нужно документальное подтверждение: '.$row['questions'], [$row['designation'],$row['questions']], [$id]);
            }
            if ($row['has_questions'] && !$recognized) $add('premise:'.$id.':source', $row['questions'], [$row['questions']], [$id]);
        }
        $inventory = self::inventory($objects, $units, $evidence);
        if ($inventory) {
            if (!$inventory['nonparking_fully_reconciled']) {
                $ids = [];
                foreach ($objects as $row) if ($row['kind'] === 'Помещение' && (($units[$row['id']]['ownership'] ?? null) !== 'HISTORICAL_PART')) $ids[] = $row['stable_id'];
                $label = $inventory['historical_rooms'].' исходных частей → '.$inventory['canonical_nonparking_units'].' после доказанной канонизации; '.$inventory['candidate_current_nonparking_units'].' после выделения исторических частей; сопоставление с '.$inventory['declared_nonparking'].' нынешними непарковочными объектами не завершено. Остаток '.$inventory['numerical_nonparking_difference'].' не доказывает дубликаты.';
                $add('inventory:office-correspondence', $label, $inventory, $ids);
            }
            $add('inventory:second-sold-parking', 'Второе проданное место −4 не идентифицировано; в наборе найден только OBJ-094, второй раз не вычитаем.', [$inventory['evidence'],$inventory['sold_ids']], []);
        }
        // A canonical row inherits the unresolved causes of its proved source members.
        $byId = array_column($objects, null, 'id');
        foreach ($units as $unit) if ($unit['canonical_premise_id'] !== null && (int)$unit['canonical_premise_id'] !== (int)$unit['premise_id']) {
            $from = $byId[$unit['premise_id']]['stable_id']; $to = $byId[$unit['canonical_premise_id']]['stable_id'];
            $shared = ($entity[$from] ?? []) + ($entity[$to] ?? []); $entity[$from] = $shared; $entity[$to] = $shared;
        }
        ksort($issues, SORT_STRING);
        return ['issues' => $issues, 'entity' => $entity, 'count' => count($issues), 'inventory' => $inventory];
    }

    public static function correct(array $input): void
    {
        self::read(); Auth::requirePermission('premises.write');
        if (!ConfirmedPremises::editable()) throw new RuntimeException('Недостаточно прав для исправления источника.', 403);
        foreach (['issue_key','fingerprint','entity','action','field','value','decision','evidence'] as $key) {
            if (isset($input[$key]) && (!is_string($input[$key]) || str_contains($input[$key], "\0") || !mb_check_encoding($input[$key], 'UTF-8'))) throw new RuntimeException('Недопустимый формат поля исправления.', 422);
        }
        $basis = trim((string)($input['evidence'] ?? '')); $decision = trim((string)($input['decision'] ?? ''));
        if (mb_strlen($basis) < 10 || mb_strlen($basis) > 2000 || str_contains($basis, "\0")) throw new RuntimeException('Требуется проверяемое основание от 10 до 2000 символов.', 422);
        DB::transaction(function(PDO $pdo) use($input, $basis, $decision): void {
            $pdo->query('SELECT id FROM aux_write_guard WHERE id=1 FOR UPDATE')->fetch();
            // Lock the current typed premises before recalculating the source fingerprint.
            $pdo->query('SELECT id FROM aux_confirmed_premises ORDER BY id FOR UPDATE')->fetchAll();
            Auth::requirePermission('premises.write');
            if (!ConfirmedPremises::editable()) throw new RuntimeException('Право исправления отозвано.', 403);
            $key = (string)($input['issue_key'] ?? '');
            $issue = self::state()['issues'][$key] ?? throw new RuntimeException('Вопрос не найден или уже подтверждён.', 404);
            if (str_starts_with($key, 'inventory:')) throw new RuntimeException('Состав требует нового доказательного соответствия OWNER.', 403);
            if (!hash_equals($issue['fingerprint'], (string)($input['fingerprint'] ?? ''))) throw new RuntimeException('Исходные значения изменились. Откройте вопрос заново.', 409);
            $entity = (string)($input['entity'] ?? '');
            if (!in_array($entity, $issue['entities'], true) || ($input['action'] ?? 'source') !== 'source') throw new RuntimeException('Недопустимое действие для вопроса помещения.', 403);
            $field = (string)($input['field'] ?? '');
            $before = null; $after = null;
            if ($field !== '') {
                if (!in_array($field, ['area','designation','cadastral_number'], true)) throw new RuntimeException('Поле не относится к вопросу помещения.', 403);
                $row = ConfirmedPremises::record($entity);
                $update = array_intersect_key($row, array_flip(ConfirmedPremises::EDITABLE));
                $update[$field] = trim((string)($input['value'] ?? ''));
                $update += ['expected' => ConfirmedPremises::fingerprint($row), 'evidence' => $basis];
                $before = [$field => $row[$field]];
                ConfirmedPremises::save($entity, $update);
                $after = [$field => ConfirmedPremises::record($entity)[$field]];
            } elseif ($decision === '') throw new RuntimeException('Требуется документированное решение.', 422);
            $identity = hash('sha256', 'linked-premise-v1|'.$key);
            $old = self::rows($pdo, 'SELECT * FROM aux_issues WHERE identity_key=? FOR UPDATE', [$identity])[0] ?? null;
            $proof = ['format' => 'linked-premise-v1', 'issue_key' => $key, 'entity' => $entity, 'fingerprint' => $issue['fingerprint'], 'evidence' => $basis, 'source_payload' => $issue['payload'], 'field' => $field, 'before' => $before, 'after' => $after];
            $actor = Auth::user();
            $proof['actor'] = ['id' => (int)$actor['id'], 'role_id' => $actor['role_id'], 'name' => $actor['name']];
            $state = $field === '' ? 'confirmed' : 'corrected';
            if ($old) $pdo->prepare('UPDATE aux_issues SET title=?,detail=?,state=?,note=?,evidence=?,revision=revision+1 WHERE id=?')->execute([$issue['label'],$key,$state,$decision,Importer::json($proof),$old['id']]);
            else $pdo->prepare('INSERT INTO aux_issues(identity_key,category,title,detail,state,note,evidence) VALUES(?,?,?,?,?,?,?)')->execute([$identity,self::CATEGORY,$issue['label'],$key,$state,$decision,Importer::json($proof)]);
            Audit::record('premise.issue.'.($field === '' ? 'confirm' : 'correct'), 'premise-issue', $key, $old, ['state' => $state,'decision' => $decision,'proof' => $proof]);
        });
    }
}
