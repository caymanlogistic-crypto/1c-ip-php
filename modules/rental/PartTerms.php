<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{BillingPartAdmission, BillingParts, BillingPartTerms, View};
use PDO;
use RuntimeException;

/** Separate billable identities; none of these rows are physical object selections. */
final class PartTerms
{
    public static function candidates(PDO $pdo, string $kind, int $id): array
    {
        $anchor = BillingPartAdmission::anchor($pdo);
        if ($anchor === null) return [];
        $result = [];
        $present = [];
        foreach ($id > 0 ? BillingPartTerms::raw($pdo, $kind, $id) : [] as $term) {
            $part = BillingParts::version($pdo, $term['part_id'], (int)$term['part_version'], $anchor);
            $slot = 'part_'.hash('sha256', $term['source_term_key']);
            $result[$slot] = ['part' => $part, 'term' => $term];
            $present[$term['part_id']] = true;
        }
        $query = $pdo->query('SELECT p.* FROM rental_billing_parts p WHERE p.version=(SELECT MAX(v.version) FROM rental_billing_parts v WHERE v.id=p.id) ORDER BY p.id');
        foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $part) {
            if (isset($present[$part['id']])) continue;
            $identity = BillingPartAdmission::identity($part['source_namespace'], $part['source_key']);
            if (!in_array($identity, $anchor['source_identity_sha256s'], true)) continue;
            $frozen = BillingParts::version($pdo, $part['id'], (int)$part['version'], $anchor);
            $key = 'native:'.$part['id'];
            $result['part_'.hash('sha256', $key)] = ['part' => $frozen, 'term' => [
                'source_term_key' => $key, 'part_id' => $part['id'], 'part_version' => (int)$part['version'],
                'admission_sha256' => $part['admission_sha256'], 'start_date' => null,
                'end_date' => null, 'amount' => null, 'purpose' => null,
                'effective_date' => null, 'superseded_on' => null,
            ]];
        }
        if (count($result) > 128) throw new RuntimeException('Слишком много условий частей.', 409);
        return $result;
    }

    /** Null means the legacy client did not submit this separately versioned branch. */
    public static function input(PDO $pdo, string $kind, int $id, array $input, string $start, string $end, string $priceKind = 'contract'): ?array
    {
        if (!array_key_exists('part_terms_present', $input)) return null;
        if (BillingPartAdmission::selected() === null) throw new RuntimeException('Условия частей пока недоступны.', 409);
        if ((string)$input['part_terms_present'] !== '1' || !is_array($input['part_terms'] ?? null)) {
            throw new RuntimeException('Проверьте состав условий частей.', 409);
        }
        return self::normalize(self::candidates($pdo, $kind, $id), $input, $start, $end, $priceKind);
    }

    public static function normalize(array $candidates, array $input, string $start, string $end, string $priceKind = 'contract'): array
    {
        if (!in_array($priceKind, ['contract','document'], true)) throw new RuntimeException('Неизвестный вид условий части.', 409);
        $posted = $input['part_terms'];
        if (count($posted) > 128 || array_diff(array_keys($posted), array_keys($candidates))) {
            throw new RuntimeException('Состав частей изменился. Откройте форму заново.', 409);
        }
        $desired = [];
        foreach ($posted as $slot => $fields) {
            if (!is_array($fields)) throw new RuntimeException('Проверьте условия части.', 409);
            $value = $input['term_amount'][$slot] ?? '';
            if (!is_string($value)) throw new RuntimeException('Проверьте цену части.', 409);
            if (trim($value) === '') continue;
            $candidate = $candidates[$slot];
            $part = $candidate['part'];
            $term = $candidate['term'];
            if ((string)($fields['version'] ?? '') !== (string)$part['current_version']) {
                throw new RuntimeException('Версия части изменилась. Откройте форму заново.', 409);
            }
            $term['part_version'] = $part['current_version'];
            if (isset($part['current_admission_sha256'])) $term['admission_sha256'] = $part['current_admission_sha256'];
            $raw = trim($value);
            if (!preg_match('/^[0-9]{1,16}(?:\.[0-9]{1,12})?$/D', $raw) || bccomp($raw, '0', 12) <= 0) {
                throw new RuntimeException('Цена каждой выбранной части должна быть положительной.');
            }
            // Source contract builder checks raw AND rounded fee; DS checks raw only.
            $amount = bcadd($raw, '0.005', 2);
            if ($priceKind==='contract' && bccomp($amount, '0', 2) <= 0) throw new RuntimeException('Цена части после округления должна быть положительной.');
            $from = Service::date($fields['start_date'] ?? $start);
            $to = Service::date($fields['end_date'] ?? $end);
            if ($from > $to) throw new RuntimeException('Проверьте срок каждой части.');
            $desired[] = array_intersect_key($term, array_flip([
                'source_term_key', 'part_id', 'part_version', 'admission_sha256',
                'purpose', 'effective_date', 'superseded_on',
            ])) + ['start_date' => $from, 'end_date' => $to, 'amount' => $amount,
                '_validated_price' => ['raw_amount' => $raw, 'rounded_amount' => $amount, 'price_kind' => $priceKind]];
        }
        return $desired;
    }

    public static function total(array $terms, bool $validatedDocumentInput = false): string
    {
        $total = '0.00';
        foreach ($terms as $term) {
            if ($term['amount'] === null) throw new RuntimeException('Уточните месячную цену части.');
            if (bccomp($term['amount'], '0', 2) < 0 || (!$validatedDocumentInput && bccomp($term['amount'], '0', 2) === 0)) {
                throw new RuntimeException('Цена каждой части должна быть положительной.');
            }
            $total = bcadd($total, $term['amount'], 2);
        }
        return $total;
    }

    /** Workflow record version alone does not change the signed dated terms. */
    public static function signatureTerms(PDO $pdo, string $kind, int $id): array
    {
        $terms = BillingParts::terms($pdo, $kind, $id);
        foreach ($terms as &$term) unset($term['record_version']);
        unset($term);
        return $terms;
    }

    public static function label(array $part): string
    {
        $text = 'Часть '.$part['name'].' · юридический адрес';
        if ($part['floor'] !== null) $text .= ', этаж '.$part['floor'];
        if ($part['address'] !== null) $text .= ', '.$part['address'];
        return $text;
    }

    public static function roundedZeroProven(?array $state, array $term): bool
    {
        return ($state['state'] ?? null)==='KNOWN'
            && isset($term['source_term_key_native'])
            && in_array($term['source_term_key_native'], $state['validated_zero_term_keys'] ?? [], true);
    }

    public static function storageRows(array $rows): array
    {
        foreach ($rows as &$row) unset($row['_validated_price']);
        unset($row);
        return $rows;
    }

    public static function basis(array $actor, string $command, int $version, object $token, bool $replacePartBranch = false, array $validatedRows = []): array
    {
        $proof = [];
        foreach ($validatedRows as $row) if (isset($row['_validated_price'])) $proof[$row['source_term_key']] = $row['_validated_price'];
        return ['actor_id' => (int)$actor['id'], 'command_key' => $command,
            'source_rule_sha256' => BillingPartAdmission::NATIVE_SOURCE, 'record_version' => $version,
            'history_before_sha256' => BillingPartTerms::before($token)['sha256'],
            ...($replacePartBranch?['replace_part_branch' => true]:[]),
            ...($proof?['validated_raw_terms' => $proof]:[])];
    }

    public static function render(PDO $pdo, string $kind, int $id, string $start, string $end, ?array $draft = null): string
    {
        $candidates = self::candidates($pdo, $kind, $id);
        if (!$candidates) return '';
        $rows = '';
        foreach ($candidates as $slot => $candidate) {
            $term = $candidate['term'];
            $part = $candidate['part']['part'];
            if ($draft !== null) {
                foreach (['start_date','end_date'] as $field) $term[$field]=$draft['part_terms'][$slot][$field] ?? '';
                $term['amount']=$draft['term_amount'][$slot] ?? '';
                $term['part_version']=$draft['part_terms'][$slot]['version'] ?? $term['part_version'];
            }
            $prefix = 'part_terms['.$slot.']';
            $version = '<input type="hidden" name="'.$prefix.'[version]" value="'.View::e((string)$term['part_version']).'">';
            if ((int)$term['part_version'] !== $candidate['part']['current_version']) {
                $version = '<label class="field"><span class="field-label">Версия части</span><select class="field-input field-select" name="'.$prefix.'[version]">'
                    .'<option value="'.View::e((string)$term['part_version']).'">Версия '.View::e((string)$term['part_version']).' · требуется обновление</option>'
                    .'<option value="'.$candidate['part']['current_version'].'">Актуальная версия '.$candidate['part']['current_version'].'</option></select></label>';
            }
            $rows .= '<tr><td>'.View::e('Часть '.$part['number'].' · юридический адрес')
                .$version.'</td><td>'
                .Controller::input($prefix.'[start_date]', 'Начало', $term['start_date'] ?? $start, 'date', false)
                .'</td><td>'.Controller::input($prefix.'[end_date]', 'Окончание', $term['end_date'] ?? $end, 'date', false)
                .'</td><td>'.Controller::input('term_amount['.$slot.']', 'Сумма с НДС', $term['amount'], 'text', false).'</td></tr>';
        }
        return '<input type="hidden" name="part_terms_present" value="1"><span class="registry-ui-modal-help">'
            .'Условия частей. Заполните месячную цену включаемой части; пустая цена исключает её из состава. Помещения и их занятость не изменяются.</span>'
            .'<div class="table-scroll"><table class="table registry-ui-table registry-ui-compact-table"><thead><tr>'
            .'<th>Часть</th><th>Начало</th><th>Окончание</th><th>В месяц с НДС</th></tr></thead><tbody>'.$rows.'</tbody></table></div>';
    }
}
