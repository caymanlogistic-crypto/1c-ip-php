<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\People;
use PDO;

/** Select work that changed hands in the committed rental command. */
final class StaffHandoff
{
    public const PACKET = 'operation.packet-handoff';
    private const REVIEW = ['lawyer' => 'Проверить договорные документы', 'accountant' => 'Проверить документы и расчёты'];
    private const APPROVAL = ['create','edit','submit','legal','return','forward','packet','handed','sign','apply','cancel'];
    private const SPECIAL = [ContractRevision::ACTION, 'contract.reconcile', FinalReview::RETURN_FINANCE, FinalReview::RETURN_LEGAL,
        FinalReview::RESUBMIT, SecurityDeposit::BASIS, PremisesActs::CONFIRM, HandoverQuestion::OPEN,
        OnecRequest::SUBMIT, OnecRequest::RETURN, self::PACKET];

    public static function managed(string $action): bool
    {
        [$entity,$op] = array_pad(explode('.', $action, 2), 2, '');
        return in_array($entity, ['contract','document'], true) && in_array($op, self::APPROVAL, true)
            || in_array($action, self::SPECIAL, true);
    }

    public static function before(PDO $db, string $action, int $id, ?array $row): array
    {
        if ($action === SecurityDeposit::BASIS) return SecurityDeposit::basis($db, $id);
        if (in_array($action, [PremisesActs::CONFIRM, HandoverQuestion::OPEN], true)) return HandoverQuestion::read($db, $id) ?? [];
        if (str_starts_with($action, 'document.') && in_array($action, ['document.sign','document.apply'], true) && ($row['kind'] ?? null) === 'termination') {
            return ['contract_status' => Service::get($db, 'contracts', (int)$row['contract_id'])['status']];
        }
        return [];
    }

    public static function record(PDO $db, array $actor, string $action, array $input, array $result, array $context, array $after, ?array $transition, string $commandKey): ?array
    {
        $id = (int)$after['id'];
        $before = $context['before'];
        $oldFact = $context['staff_before'];
        $roles = [];
        $assigned = null;
        $linked = null;
        $task = '';

        if (in_array($action,[OnecRequest::SUBMIT,OnecRequest::RETURN],true)) {
            $request=OnecRequest::read($db,$id);
            $submit=$action===OnecRequest::SUBMIT;
            $roles=[$submit?'accountant':'manager'];
            $assigned=$request[$submit?'reviewer_id':'manager_id'];
            $task=$submit?'Проверить заявку в разделе «Работа с 1С»':'Исправить заявку в разделе «Работа с 1С»';
        } elseif ($action === self::PACKET && $before['status']==='approved' && $after['status']==='sent') {
            $roles = ['manager'];
            $task = 'Получить подписанные документы';
        } elseif ($action === ContractRevision::ACTION && $after['status']==='legal' && (int)$after['version']>(int)$before['version']) {
            $roles = ['lawyer'];
            $task = 'Проверить новую редакцию договора';
        } elseif (in_array($action, FinalReview::ACTIONS, true)) {
            if ((int)$before['version'] === (int)$after['version']) return null;
            if ($action === FinalReview::RESUBMIT) {
                $review = (string)$input['review_kind'];
                $assigned = (int)(FinalReview::marker($db, $id, $review)['reviewer_id'] ?? 0);
                $roles = [FinalReview::reviewer($review)];
                $task = 'Повторно проверить финальную сверку';
            } else {
                $roles = ['manager'];
                $task = $action === FinalReview::RETURN_FINANCE
                    ? 'Исправить замечания бухгалтера по финальной сверке' : 'Исправить замечания юриста по финальной сверке';
            }
        } elseif ($action === SecurityDeposit::BASIS) {
            $basis = SecurityDeposit::basis($db, $id);
            $fields = array_flip(['mode','target','amount','document','reference']);
            if (Importer::canonicalJson(array_intersect_key($oldFact, $fields)) === Importer::canonicalJson(array_intersect_key($basis, $fields))) return null;
            $roles = ['accountant'];
            $task = 'Рассмотреть подтверждённое основание по обеспечению';
            $linked = empty($basis['target']) ? null : (int)$basis['target'];
        } elseif (in_array($action, [PremisesActs::CONFIRM, HandoverQuestion::OPEN], true)) {
            $question = HandoverQuestion::read($db, $id);
            if (empty($result['question_notification']) || !$question || ($question['key'] ?? null) === ($oldFact['key'] ?? null)
                || ($question['notification_command'] ?? null) !== $commandKey) return null;
            $roles = ['accountant'];
            $task = 'Решить вопрос по счетам после сдвига даты передачи';
        } elseif ($action === 'contract.reconcile' && $before['status'] === 'active' && $after['status'] === 'reconciling'
            || ($oldFact['contract_status'] ?? null) === 'active' && !empty($after['applied'])
                && Service::get($db, 'contracts', (int)$after['contract_id'])['status'] === 'reconciling') {
            $roles = ['accountant','lawyer'];
            $task = 'Провести финальную сверку';
        } elseif ($action === 'document.apply' && empty($before['applied']) && !empty($after['applied'])) {
            $q=$db->prepare('SELECT 1 FROM rental_object_holds h WHERE h.document_id=? AND h.return_confirmed=0 AND '
                .\App\ContractOccupancy::physicalPredicate('h').' LIMIT 1');
            $q->execute([$id]);
            if ($q->fetchColumn()) {
                $roles = ['manager'];
                $task = 'Оформить фактический возврат исключённых помещений';
            }
        } elseif ($transition && in_array($transition['event_kind'], ['submit','resubmit','legalApprove','lawyerReturn','accountantReturn','handed'], true)) {
            $role = RegistryState::owner($after);
            if (!$role || $before && RegistryState::owner($before) === $role) return null;
            $roles = [$role];
            $task = match ($transition['event_kind']) {
                'resubmit' => 'Повторно проверить исправленные документы',
                'lawyerReturn','accountantReturn' => 'Рассмотреть возврат на доработку',
                'handed' => 'Получить подписанные документы',
                default => self::REVIEW[$role],
            };
        }

        $targets = self::targets($db, $actor, $roles, $assigned);
        if (!$targets) return null;
        $payload = ['task'=>$task, 'targets'=>$targets];
        if ($linked) $payload['linked_contract'] = ['id'=>$linked,'number'=>Service::get($db, 'contracts', $linked)['number']];
        return $payload;
    }

    private static function targets(PDO $db, array $actor, array $roles, ?int $assigned): array
    {
        $targets = [];
        $q = $db->prepare('SELECT u.id,u.name FROM users u JOIN role_profiles p ON p.role_id=u.role WHERE p.kind=? AND u.active=1 ORDER BY u.id');
        foreach ($roles as $role) {
            if ($assigned !== null && $assigned === (int)$actor['id'] || $assigned === null && $actor['role'] === $role) continue;
            $q->execute([$role]);
            $people = $q->fetchAll(PDO::FETCH_ASSOC);
            $person = $assigned === null && count($people) === 1 ? $people[0] : null;
            if ($assigned !== null) {
                foreach ($people as $candidate) if ((int)$candidate['id'] === $assigned) $person = $candidate;
            }
            // Keep the exact assignment even if the employee can no longer act.
            $targets[] = ['role'=>$role,'id'=>$assigned ?? ($person ? (int)$person['id'] : null),
                'label'=>$person ? self::name((string)$person['name'], $role) : People::roleLabel($role)];
        }
        return $targets;
    }

    public static function name(string $name, string $role): string
    {
        $name = trim($name);
        return preg_match("/\A[\p{L}][\p{L}\p{M} .’'-]{0,79}\z/u", $name) ? $name : People::roleLabel($role);
    }
}
