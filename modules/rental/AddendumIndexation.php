<?php
declare(strict_types=1);

namespace Modules\Rental;

use DateTimeImmutable;
use PDO;
use RuntimeException;

final class AddendumIndexation
{
    public const DEFAULT_PERCENT = '10';

    public static function selection(array $input, ?array $document = null): array
    {
        $saved = $document ? (Importer::decodeExact($document['source_json'])['addendum_choice'] ?? []) : [];
        $scope = $document ? ($saved['scope'] ?? '') : ($input['object_scope'] ?? '');
        if (!in_array($scope,['','same','change'],true)) {
            throw new RuntimeException('Выберите, меняется ли состав помещений.',422);
        }
        $enabled = $document ? isset($saved['percent']) : ($input['indexation'] ?? '') === '1';
        if ($enabled && $scope !== 'same') {
            throw new RuntimeException('Индексация доступна при прежнем составе помещений.',422);
        }
        return ['scope'=>$scope,'percent'=>$enabled ? self::percent($input['index_percent'] ?? $saved['percent'] ?? '') : null];
    }

    public static function percent(mixed $value): string
    {
        $percent = is_string($value) ? str_replace(',','.',trim($value)) : '';
        if (!preg_match('/^\d{1,3}(?:\.\d{1,4})?$/D',$percent) || bccomp($percent,'0',4)<=0) {
            throw new RuntimeException('Укажите положительный процент индексации с точностью до четырёх знаков.',422);
        }
        return rtrim(rtrim(bcadd($percent,'0',4),'0'),'.');
    }

    public static function quote(PDO $db, array $contract, string $effective, string $percent): array
    {
        $effective = Service::date($effective);
        if (!$contract['start_date'] || !$contract['end_date'] || $effective < $contract['start_date']) {
            throw new RuntimeException('Проверьте дату вступления ДС в силу и сроки базового договора.',422);
        }
        // A renewal uses the preceding term at its end. An in-period change uses the rate just before the change.
        $day = (new DateTimeImmutable($effective))->modify('-1 day')->format('Y-m-d');
        $basis = min($contract['end_date'],max($contract['start_date'],$day));
        $q = $db->prepare('SELECT co.object_id,o.name,t.amount FROM contract_objects co
            JOIN rental_objects o ON o.id=co.object_id
            LEFT JOIN contract_terms t ON t.id=(
                SELECT t2.id FROM contract_terms t2 WHERE t2.contract_id=co.contract_id
                    AND t2.object_id=co.object_id AND t2.start_date<=? AND t2.end_date>=?
                    AND (t2.effective_date IS NULL OR t2.effective_date<=?)
                    AND (t2.superseded_on IS NULL OR t2.superseded_on>=?)
                ORDER BY t2.effective_date DESC,t2.id DESC LIMIT 1
            ) WHERE co.contract_id=? ORDER BY co.object_id');
        $q->execute([$basis,$basis,$basis,$basis,$contract['id']]);
        $rates = [];
        $factor = bcadd('1',bcdiv($percent,'100',8),8);
        foreach ($q as $row) {
            if ($row['amount'] === null || bccomp($row['amount'],'0',2)<=0) {
                throw new RuntimeException('Уточните исходную месячную ставку помещения «'.$row['name'].'» на '.Documents::dateText($basis).'.',422);
            }
            $rates[(int)$row['object_id']] = [
                'base' => Service::money($row['amount']),
                'amount' => Service::money(Billing::round(bcmul($row['amount'],$factor,12))),
            ];
        }
        if (!$rates) throw new RuntimeException('У базового договора нет помещений для индексации.',422);
        return ['basis'=>$basis,'rates'=>$rates];
    }

    public static function validate(PDO $db, array $contract, array $input, array $selection, string $effective): void
    {
        if ($selection['scope'] !== 'same') return;
        $ids = array_map('intval',array_keys(Occupancy::relations($db,(int)$contract['id'])));
        $submitted = array_map('intval',(array)($input['objects'] ?? []));
        sort($ids,SORT_NUMERIC);
        sort($submitted,SORT_NUMERIC);
        if ($ids !== $submitted || array_key_exists('part_terms_present',$input)) {
            throw new RuntimeException('Для прежнего состава сохраните помещения базового договора.',422);
        }
        if ($selection['percent'] === null) return;
        $quote = self::quote($db,$contract,$effective,$selection['percent']);
        foreach ($quote['rates'] as $id=>$rate) {
            $submittedAmount = $input['term_amount'][$id] ?? null;
            if (!is_string($submittedAmount) || Service::money($submittedAmount) !== $rate['amount']) {
                throw new RuntimeException('Расчёт индексации изменился. Пересчитайте ставки по текущей дате и проценту.',422);
            }
        }
    }

    public static function saveSelection(PDO $db, int $id, array $selection): void
    {
        if ($selection['scope'] === '') return;
        $db->prepare("UPDATE rental_documents SET source_json=JSON_SET(source_json,'$.addendum_choice',CAST(? AS JSON)) WHERE id=?")
            ->execute([Importer::json($selection),$id]);
    }
}
