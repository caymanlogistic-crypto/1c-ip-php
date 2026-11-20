<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth, PagePolicy};
use Modules\Integrations\ServerWorkflowMax;
use PDO;
use RuntimeException;

final class HandoverQuestion
{
    public const OPEN = 'contract.handover-question';
    public const RETRY = 'contract.handover-question-retry';
    public const RESOLVE = 'contract.handover-question-resolve';
    public const RECONCILE = 'contract.handover-question-reconcile';
    public const ACTIONS = [self::OPEN, self::RETRY, self::RESOLVE, self::RECONCILE];
    public const EVENT = 'handoverQuestion';
    private const NAMESPACE = 'rental_handover_question';
    private const STATUSES = ['approved', 'sent', 'active', 'reconciling'];

    private static function factKey(PDO $db, int $id): string
    {
        return hash('sha256', (string)$db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn()).':'.$id;
    }

    public static function read(PDO $db, int $id): ?array
    {
        $q = $db->prepare('SELECT fact_json FROM rental_facts WHERE namespace=? AND source_key=? AND operative=1');
        $q->execute([self::NAMESPACE, self::factKey($db, $id)]);
        $json = $q->fetchColumn();
        return $json === false ? null : Importer::decodeExact($json);
    }

    public static function initialInvoice(PDO $db, array $contract): ?array
    {
        if (!in_array($contract['status'], self::STATUSES, true) || empty($contract['start_date'])) return null;
        $q = $db->prepare("SELECT id,number FROM invoices WHERE contract_id=? AND initial_invoice=1 AND kind='Первый период' AND issued<=CURRENT_DATE AND status='active' ORDER BY issued,id LIMIT 1");
        $q->execute([$contract['id']]);
        return $q->fetch() ?: null;
    }

    public static function can(string $action, array $actor, array $contract): bool
    {
        $roles = $action === self::OPEN ? ['admin', 'manager', 'accountant'] : ['admin', 'accountant'];
        if (!in_array($actor['role'], $roles, true) || !Auth::can(Service::commandGrant($action, $actor))) return false;
        if (empty($actor['grants_configured']) || $actor['role'] === 'admin') return true;
        if (empty($actor['workflow_role'])) return false;
        foreach (Access::statusPages($contract['status']) as $page) {
            if (PagePolicy::level('rental.'.$page, $actor) >= 2) return true;
        }
        return false;
    }

    public static function execute(PDO $db, array $actor, string $action, array $contract, array $input): array
    {
        if (!self::can($action, $actor, $contract)) {
            throw new RuntimeException('Действие недоступно для этой роли.', 403);
        }
        $id = (int)$contract['id'];
        $case = self::read($db, $id);
        $commandKey = (string)$input['command_key'];
        $notify = false;
        if ($action === self::OPEN) {
            $invoice = self::initialInvoice($db, $contract);
            if ($invoice === null) throw new RuntimeException('Для вопроса нужен выставленный счёт за первый период аренды.', 409);
            $actual = Service::date((string)($input['handover_date'] ?? ''));
            if ($actual === $contract['start_date'] || $actual > date('Y-m-d')) {
                throw new RuntimeException('Укажите фактическую дату передачи, отличную от даты начала по договору и не позже сегодняшнего дня.', 422);
            }
            if ($case && $case['actual'] === $actual && $case['planned'] === $contract['start_date'] && $case['invoice_id'] === (int)$invoice['id']) {
                return ['id'=>$id, 'message'=>empty($case['resolution']) ? 'Этот вопрос уже ждёт решения.' : 'По этой дате решение уже сохранено.'];
            }
            if ($case && empty($case['resolution'])) {
                throw new RuntimeException('Сначала зафиксируйте решение по открытому вопросу.', 409);
            }
            $case = [
                'key'=>$commandKey, 'planned'=>$contract['start_date'], 'actual'=>$actual,
                'invoice_id'=>(int)$invoice['id'], 'invoice_number'=>$invoice['number'],
                'notification_command'=>$commandKey,
            ];
            $description = sprintf(
                'Объект передан %s вместо %s по договору. Счёт за первый период № %s уже выставлен. Как учесть сдвиг даты в начислениях?',
                self::displayDate($case['actual']), self::displayDate($case['planned']), $case['invoice_number']
            );
            $message = 'Вопрос передан на рассмотрение бухгалтеру. Начисление за затронутый период ждёт решения.';
            $case['question'] = $description;
            $notify = true;
        } else {
            if (!$case || ($input['question_key'] ?? null) !== $case['key']
                || (!empty($case['resolution']) && $action !== self::RECONCILE)) {
                throw new RuntimeException('Вопрос изменился. Обновите карточку договора.', 409);
            }
            $delivery = self::delivery($db, $id, $case);
            if ($action === self::RETRY) {
                if (!self::canRetry($delivery) || ($input['attempt_id'] ?? null) !== $delivery['attempt_id']) {
                    throw new RuntimeException('Повтор доступен после подтверждённого сбоя. При неизвестном результате сначала сверяют доставку в MAX.', 409);
                }
                $case['notification_command'] = $commandKey;
                $description = $case['question'];
                $message = 'Запрошен повтор уведомления MAX после подтверждённого сбоя.';
                $notify = true;
            } elseif ($action === self::RECONCILE) {
                if ($delivery['status'] !== 'uncertain' || ($input['attempt_id'] ?? null) !== $delivery['attempt_id']) {
                    throw new RuntimeException('Статус попытки MAX изменился. Обновите карточку договора.', 409);
                }
                $outcome = $input['delivery_outcome'] ?? null;
                $evidence = trim((string)($input['delivery_evidence'] ?? ''));
                if (!in_array($outcome, ['accepted', 'failed'], true) || $evidence === '' || mb_strlen($evidence) > 2000) {
                    throw new RuntimeException('Укажите подтверждённый результат и основание сверки (до 2000 знаков).', 422);
                }
                $case['delivery_reconciliation'] = ['attempt_id'=>$delivery['attempt_id'], 'outcome'=>$outcome, 'evidence'=>$evidence];
                $description = ($outcome === 'accepted' ? 'Доставка MAX подтверждена. ' : 'Подтверждено, что MAX не принял уведомление. ').$evidence;
                $message = 'Результат сверки MAX сохранён.';
            } else {
                $answer = trim((string)($input['resolution'] ?? ''));
                if ($answer === '' || mb_strlen($answer) > 2000 || empty($input['billing_ready'])) {
                    throw new RuntimeException('Запишите решение бухгалтера (до 2000 знаков) и подтвердите, что начисление можно продолжить.', 422);
                }
                $case['resolution'] = ['text'=>$answer, 'actor'=>$actor['name'], 'date'=>date(DATE_ATOM)];
                $description = 'Решение по дате передачи: '.$answer;
                $message = 'Решение сохранено. Начисление за затронутый период можно продолжить.';
            }
        }
        $q = $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1) ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)');
        $q->execute([self::NAMESPACE, self::factKey($db, $id), Importer::json($case)]);
        Service::event($db, $actor, $action, 'Договор № '.$contract['number'].'. '.$description, $id);
        return ['id'=>$id, 'message'=>$message, 'question_notification'=>$notify];
    }

    public static function delivery(PDO $db, int $id, array $case): array
    {
        $q = $db->prepare("SELECT id,source_json,created_at FROM rental_events WHERE contract_id=? AND action IN (?,?) AND JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.command_key'))=? ORDER BY id DESC LIMIT 1");
        $q->execute([$id, self::OPEN, self::RETRY, $case['notification_command']]);
        $row = $q->fetch();
        $event = $row ? WorkflowEvents::decode($row) : null;
        if (!$event) return ['status'=>'unavailable', 'attempt_id'=>null];
        $q = $db->prepare("SELECT a.id,a.status,g.name FROM messaging_attempts a LEFT JOIN messaging_groups g ON g.provider=a.provider AND g.chat_id=a.target WHERE a.provider='max' AND a.operation='send' AND a.actor_id=? AND a.request_key=?");
        $q->execute([$event['actor_id'], ServerWorkflowMax::requestKey($event)]);
        $attempt = $q->fetch();
        $review = $case['delivery_reconciliation'] ?? null;
        $status = $attempt['status'] ?? 'unavailable';
        if ($status === 'uncertain' && $review && $review['attempt_id'] === $attempt['id']) {
            $status = 'reconciled_'.$review['outcome'];
        }
        return [
            'status'=>$status,
            'attempt_id'=>$attempt['id'] ?? null,
            'group'=>$attempt['name'] ?? null,
        ];
    }

    public static function canRetry(array $delivery): bool
    {
        return in_array($delivery['status'], ['failed', 'reconciled_failed'], true);
    }

    public static function affectedMonths(array $case): array
    {
        $first = min($case['planned'], $case['actual']);
        $last = new \DateTimeImmutable(max($case['planned'], $case['actual']));
        return [substr($first, 0, 7), $last->modify('-1 day')->format('Y-m')];
    }

    public static function assertBillingReady(PDO $db, int $id, string $period): void
    {
        $case = self::read($db, $id);
        if (!$case || !empty($case['resolution'])) return;
        [$first, $last] = self::affectedMonths($case);
        if ($period >= $first && $period <= $last) {
            throw new RuntimeException('Начисление аренды за этот период ожидает решения бухгалтера по дате передачи объекта.', 409);
        }
    }

    public static function displayDate(string $date): string
    {
        return (new \DateTimeImmutable($date))->format('d.m.Y');
    }
}
