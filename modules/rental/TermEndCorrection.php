<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, DB};
use PDO;
use RuntimeException;

final class TermEndCorrection
{
    public const ACTION = 'contract.correct-imported-term-end';
    private const BEFORE = '2026-11-30';
    private const AFTER = '2027-10-31';
    private const WORKBOOK = '328caf9a55923613b1845cce03fd65b0fe1e9f7d795a6c1d2f6193443f192fb2';
    private const SCOPE = [
        1014 => ['51', '2025-03-14', [1017 => ['2.14', '-2', 53], 1051 => ['1.37', '-1', 16]]],
        1015 => ['34', '2024-02-28', [1032 => ['2.92', '-2', 75], 1033 => ['2.93', '-2', 76], 1058 => ['1.44', '-1', 23]]],
        1024 => ['26', '2023-04-10', [1059 => ['1.45', '-1', 24]]],
    ];

    public static function requireOperator(): void
    {
        if (PHP_SAPI !== 'cli' || (Auth::user()['role'] ?? null) !== 'admin') {
            throw new RuntimeException('Исправление доступно только администратору через CLI.', 403);
        }
    }

    private static function rows(PDO $db, string $sql, array $args): array
    {
        $query = $db->prepare($sql);
        $query->execute($args);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Snapshot only the live records affected by the correction. Imported source JSON stays intact. */
    private static function state(PDO $db, string $end, bool $lock): array
    {
        $suffix = $lock ? ' FOR UPDATE' : '';
        $state = [];
        foreach (self::SCOPE as $id => [$number, $documentDate, $places]) {
            $contract = self::rows($db, 'SELECT * FROM contracts WHERE id=?'.$suffix, [$id])[0] ?? [];
            $objects = self::rows($db, 'SELECT o.id,o.name,o.floor,o.active,o.version FROM contract_objects co
                JOIN rental_objects o ON o.id=co.object_id WHERE co.contract_id=? ORDER BY o.id'.$suffix, [$id]);
            $terms = self::rows($db, 'SELECT * FROM contract_terms WHERE contract_id=? ORDER BY object_id,id'.$suffix, [$id]);
            $source = Importer::decodeExact($contract['source_json'] ?? '{}');
            if (($contract['number'] ?? null) !== $number || $contract['document_date'] !== $documentDate
                || $contract['status'] !== 'active' || $contract['end_date'] !== $end || empty($source['imported'])
                || $contract['finish_date'] !== null || $contract['return_confirmed'] !== null || $contract['signed']
                || array_map('intval', array_column($objects, 'id')) !== array_keys($places)
                || array_map('intval', array_column($terms, 'object_id')) !== array_keys($places)) {
                throw new RuntimeException('Состав, статус или редакция договора №'.$number.' требуют сверки.', 409);
            }
            foreach ($objects as $index => $object) {
                [$name, $floor] = $places[(int)$object['id']];
                if ($object['name'] !== $name || $object['floor'] !== $floor || !$object['active']
                    || $terms[$index]['end_date'] !== $end || $terms[$index]['superseded_on'] !== null) {
                    throw new RuntimeException('Условия места '.$name.' изменились. Нужна сверка.', 409);
                }
            }
            if (self::rows($db, 'SELECT id FROM rental_documents WHERE contract_id=?'.$suffix, [$id])
                || self::rows($db, "SELECT id FROM rental_files WHERE entity_type='contract' AND entity_id=?".$suffix, [$id])) {
                throw new RuntimeException('Появились документы договора №'.$number.'. Сверьте основания.', 409);
            }
            $state[$id] = ['contract' => $contract, 'objects' => $objects, 'terms' => $terms];
        }
        $epoch = $db->query('SELECT epoch FROM rental_runtime_context WHERE id=1'.$suffix)->fetchColumn();
        return ['epoch' => (string)$epoch, 'contracts' => $state];
    }

    private static function digest(array $state): string
    {
        return hash('sha256', Importer::canonicalJson($state));
    }

    private static function prior(PDO $db, string $key): array
    {
        if (!preg_match('/^[a-zA-Z0-9-]{16,100}$/D', $key)) throw new RuntimeException('Нужен ключ исходной команды.', 422);
        $row = self::rows($db, 'SELECT result_json FROM rental_commands WHERE action=? AND command_key=?', [self::ACTION, $key])[0] ?? [];
        $prior = Importer::decodeExact($row['result_json'] ?? '{}');
        if (($prior['undo_of'] ?? null) !== '' || empty($prior['after_sha256'])) {
            throw new RuntimeException('Подтверждённая исходная команда исправления не найдена.', 409);
        }
        return $prior;
    }

    public static function plan(string $key, string $undoOf = ''): array
    {
        self::requireOperator();
        $db = DB::pdo();
        $before = $undoOf === '' ? self::BEFORE : self::AFTER;
        $state = self::state($db, $before, false);
        return self::buildPlan($db, $state, $key, $undoOf);
    }

    private static function buildPlan(PDO $db, array $state, string $key, string $undoOf): array
    {
        $before = $undoOf === '' ? self::BEFORE : self::AFTER;
        $target = $undoOf === '' ? self::AFTER : self::BEFORE;
        $sha = self::digest($state);
        if ($undoOf !== '' && !hash_equals(self::prior($db, $undoOf)['after_sha256'], $sha)) {
            throw new RuntimeException('После исправления условия изменились. Обратная команда требует сверки.', 409);
        }
        $changes = [];
        foreach ($state['contracts'] as $id => $row) {
            $terms = [];
            foreach ($row['terms'] as $term) {
                [$name, $floor, $sourceRow] = self::SCOPE[$id][2][$term['object_id']];
                if ($undoOf === '') self::checkExtension($db, $id, (int)$term['object_id'], $name);
                $terms[] = ['id' => (int)$term['id'], 'place' => $name, 'floor' => $floor, 'workbook_row' => $sourceRow,
                    'starts' => $term['start_date'], 'rate' => $term['amount'], 'end_before' => $before, 'end_after' => $target];
            }
            $changes[$id] = ['number' => $row['contract']['number'], 'version' => (int)$row['contract']['version'],
                'end_before' => $before, 'end_after' => $target, 'terms' => $terms];
        }
        return ['command_key' => $key, 'undo_of' => $undoOf, 'state_sha256' => $sha,
            'evidence' => ['workbook_sha256' => self::WORKBOOK, 'sheet' => 'На31.10.2026',
                'basis' => 'Подтверждение владельца от 21.11.2026: исправить сроки шести указанных связей.'],
            'changes' => $changes];
    }

    private static function checkExtension(PDO $db, int $contractId, int $objectId, string $name): void
    {
        $start = '2026-12-01';
        $other = self::rows($db, "SELECT c.number,COALESCE(t.start_date,c.start_date) starts,COALESCE(t.end_date,c.end_date) ends
            FROM contract_objects co JOIN contracts c ON c.id=co.contract_id
            LEFT JOIN contract_terms t ON t.contract_id=c.id AND t.object_id=co.object_id
            WHERE co.object_id=? AND c.id<>? AND c.status NOT IN ('closed','ended','cancelled')
            AND COALESCE(t.start_date,c.start_date)<=? AND COALESCE(t.end_date,c.end_date)>=? FOR UPDATE",
            [$objectId, $contractId, self::AFTER, $start]);
        $documents = self::rows($db, "SELECT d.number,t.start_date starts,t.end_date ends
            FROM document_terms t JOIN rental_documents d ON d.id=t.document_id WHERE t.object_id=?
            AND d.status NOT IN ('closed','ended','cancelled') AND t.start_date<=? AND t.end_date>=? FOR UPDATE",
            [$objectId, self::AFTER, $start]);
        $reservations = self::rows($db, "SELECT id number,start_date starts,end_date ends FROM reservations
            WHERE object_id=? AND status='active' AND start_date<=? AND end_date>=? FOR UPDATE",
            [$objectId, self::AFTER, $start]);
        $holds = self::rows($db, 'SELECT document_id number,effective_date starts,NULL ends FROM rental_object_holds
            WHERE object_id=? AND return_confirmed=0 AND effective_date<=? FOR UPDATE', [$objectId, self::AFTER]);
        foreach (['договор' => $other, 'ДС' => $documents, 'бронь' => $reservations, 'удержание по ДС' => $holds] as $kind => $conflicts) {
            if ($conflict = $conflicts[0] ?? null) {
                $dates = $conflict['starts'].($conflict['ends'] === null ? '' : '–'.$conflict['ends']);
                throw new RuntimeException('Место '.$name.': '.$kind.' №'.$conflict['number'].', '.$dates.'.', 409);
            }
        }
        try {
            Service::available($db, [$objectId], $start, self::AFTER, $contractId);
        } catch (RuntimeException $error) {
            throw new RuntimeException('Место '.$name.': '.$error->getMessage(), $error->getCode(), $error);
        }
    }

    public static function execute(PDO $db, array $actor, array $plan): array
    {
        $undoOf = (string)($plan['undo_of'] ?? '');
        $key = (string)$plan['command_key'];
        $before = $undoOf === '' ? self::BEFORE : self::AFTER;
        $target = $undoOf === '' ? self::AFTER : self::BEFORE;
        $state = self::state($db, $before, true);
        // Rebuild from locked data to reject a stale or altered preview.
        if (Importer::canonicalJson(self::buildPlan($db, $state, $key, $undoOf)) !== Importer::canonicalJson($plan)) {
            throw new RuntimeException('Данные изменились после предпросмотра. Подготовьте новый план.', 409);
        }

        $updateContract = $db->prepare('UPDATE contracts SET end_date=?,version=version+1 WHERE id=?');
        $updateTerm = $db->prepare('UPDATE contract_terms SET end_date=? WHERE id=?');
        foreach ($state['contracts'] as $id => $row) {
            $history = ['evidence' => $plan['evidence'], 'undo_of' => $undoOf, 'change' => $plan['changes'][$id]];
            ContractHistory::remember($db, $row['contract'], $actor, $key, $history);
            $updateContract->execute([$target, $id]);
            foreach ($row['terms'] as $term) $updateTerm->execute([$target, $term['id']]);
            Service::event($db, $actor, self::ACTION, 'Исправлен срок аренды: '.$before.' → '.$target.'.', $id, $history);
        }
        return ['command_key' => $key, 'undo_of' => $undoOf, 'changes' => $plan['changes'],
            'after_sha256' => self::digest(self::state($db, $target, true))];
    }
}
