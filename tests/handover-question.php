<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Integrations\{Config, CurrentContractMaxProof, Messaging, ServerWorkflowMax};
use Modules\Rental\{HandoverQuestion, MonthlyBilling, Service};

set_exception_handler(static function(Throwable $error): void {
    fwrite(STDERR, $error->getMessage().PHP_EOL);
    exit(1);
});
$db = DB::pdo();
if (PHP_SAPI !== 'cli' || ($argv[1] ?? null) !== '--private-rental08-fixture'
    || !preg_match('/^ipphp_task_rental08(?:_[a-z0-9_]{1,10})?$/D', (string)$db->query('SELECT DATABASE()')->fetchColumn())
    || Config::liveAllowed() || ServerWorkflowMax::settings() !== null) {
    throw new RuntimeException('An isolated synthetic rental08 fixture with external access disabled is required.');
}

function expect(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo "OK $label\n";
}
function deny(callable $call, int $code, string $label, ?string $message = null): void {
    try {
        $call();
    } catch (RuntimeException $error) {
        expect($error->getCode() === $code && ($message === null || str_contains($error->getMessage(), $message)), $label);
        return;
    }
    throw new RuntimeException('Unexpected success: '.$label);
}
function become(int $id): void {
    $_SESSION['user_id'] = $id;
    $_SESSION['auth_version'] = (int)DB::pdo()->query('SELECT auth_version FROM users WHERE id='.$id)->fetchColumn();
}
function inputFor(int $id, array $values = []): array {
    return $values + ['id'=>$id, 'version'=>1, 'command_key'=>bin2hex(random_bytes(16))];
}

$fixture = $db->query("SELECT id,number FROM contracts WHERE id BETWEEN 801 AND 805 ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
expect(count($fixture) === 5 && $fixture[803] === 'ТЕСТ-08-803', 'synthetic contract IDs verified');
$snapshot = static fn()=> $db->query('SELECT * FROM invoices WHERE contract_id BETWEEN 801 AND 805 ORDER BY id')->fetchAll();
$invoices = $snapshot();
$contract = static fn(int $id) => $db->query('SELECT * FROM contracts WHERE id='.$id)->fetch();
$group = '08108108-0810-4810-8810-081081081081';
Config::saveSecrets('max', ['_owner_bound_revision'=>6, 'secret'=>'synthetic-no-network']);
expect(HandoverQuestion::affectedMonths(['planned'=>'2026-11-05','actual'=>'2026-12-01'])===['2026-11','2026-11']
    && HandoverQuestion::affectedMonths(['planned'=>'2026-12-02','actual'=>'2026-11-05'])===['2026-11','2026-12'], 'cross-month timing includes exactly the months with affected days');
$nextMonth = MonthlyBilling::plan($db,$contract(802),'2026-12');
expect(MonthlyBilling::hasTargets($nextMonth), 'same contract has billable rent in the following month');

$simulate = static function(int $eventId, string $outcome) use ($db, $group): array {
    $plan = (new CurrentContractMaxProof($db))->plan($eventId, $group, 6, 1);
    expect($plan['group_binding']['name'] === 'ТЕСТОВАЯ'
        && str_contains($plan['catalog']['body'], 'Как учесть сдвиг даты')
        && str_contains($plan['catalog']['body'], '/rental/contract?id='.$plan['event']['contract_id']), 'catalog text, destination and contract link');
    $calls = 0;
    $fake = static function(string $provider, string $operation) use (&$calls, $outcome): array {
        if ($provider !== 'max' || $operation !== 'send') throw new RuntimeException('Unexpected simulation request');
        ++$calls;
        return ['status'=>$outcome, 'code'=>'LOCAL_SIMULATION_ONLY'];
    };
    $messaging = new Messaging($db, Config::store(), $fake);
    usleep(1100000);
    $send = static fn()=> $messaging->dispatch('max', $plan['event']['actor_id'], $plan['request_key'], 'send', [$group], $plan['catalog']['body']);
    $first = $send();
    $again = $send();
    expect($calls === 1 && $first[0]['id'] === $again[0]['id'] && $first[0]['status'] === $outcome, 'one local transport invocation per committed event');
    return $first[0];
};

become(801);
$input = inputFor(802, ['handover_date'=>'2026-11-10']);
deny(fn()=>Service::command(HandoverQuestion::OPEN, inputFor(802, ['handover_date'=>'2026-11-05'])), 422, 'unchanged date rejected');
deny(fn()=>Service::command(HandoverQuestion::OPEN, inputFor(802, ['handover_date'=>(new DateTimeImmutable('tomorrow'))->format('Y-m-d')])), 422, 'future handover rejected');
deny(fn()=>Service::command(HandoverQuestion::OPEN, array_replace($input, ['version'=>0])), 409, 'stale contract form rejected');
$opened = Service::command(HandoverQuestion::OPEN, $input, $eventId);
expect(is_int($eventId), 'open command committed with a typed event');
$firstEvent = $eventId;
expect(Service::command(HandoverQuestion::OPEN, $input, $eventId) === $opened && $eventId === null, 'lost-response retry returns original receipt without new event');
Service::command(HandoverQuestion::OPEN, inputFor(802, ['handover_date'=>'2026-11-10']), $eventId);
expect($eventId === null, 'duplicate case with different command key emits no new notification');
deny(fn()=>MonthlyBilling::plan($db,$contract(802),'2026-11'),409,'affected first period is paused', 'ожидает решения бухгалтера');
expect(MonthlyBilling::plan($db,$contract(802),'2026-12') === $nextMonth, 'following month of the same contract has the identical rent plan');
expect(MonthlyBilling::hasTargets(MonthlyBilling::plan($db,$contract(805),'2026-12')), 'another contract can calculate rent');

$attempt = $simulate($firstEvent, 'failed');
$case = HandoverQuestion::read($db,802);
$retry = inputFor(802, ['question_key'=>$case['key'], 'attempt_id'=>$attempt['id']]);
$answer = inputFor(802, ['question_key'=>$case['key'], 'resolution'=>'Первый счёт оставлен в истории. Проверено: расчёт за следующий месяц можно продолжить.', 'billing_ready'=>'1']);
deny(fn()=>Service::command(HandoverQuestion::RESOLVE, $answer),403,'manager cannot record accounting decision');
become(802);
expect(HandoverQuestion::delivery($db,802,$case)['status']==='failed', 'recorded provider failure is visible');
$manual = [
    'contract_id'=>802, 'number'=>'ТЕСТ-НОВЫЙ-СЧЁТ', 'title'=>'Аренда',
    'purpose'=>'Аренда', 'amount'=>'26000', 'issued'=>'2026-11-20',
];
deny(fn()=>Service::command('invoice.issue', inputFor(0, $manual+['period'=>'2026-11'])),409,'manual rent invoice for affected month is paused before write','ожидает решения бухгалтера');
deny(fn()=>Service::command('invoice.issue', inputFor(0, $manual+['period'=>'2026-12'])),409,'manual rent invoice for unaffected month reaches the usual write checks','отсутствует ожидаемый идентификатор');
Service::command(HandoverQuestion::RETRY, $retry, $eventId);
expect(is_int($eventId), 'accountant explicitly retries failed notification');
$simulate($eventId, 'accepted');
expect(HandoverQuestion::delivery($db,802,HandoverQuestion::read($db,802))['status']==='accepted', 'accepted internal notification is visible');
deny(fn()=>Service::command(HandoverQuestion::RESOLVE,array_replace($answer,['billing_ready'=>''])),422,'accountant must confirm billing can resume');
Service::command(HandoverQuestion::RESOLVE, $answer, $eventId);
expect($eventId===null && !empty(HandoverQuestion::read($db,802)['resolution']), 'accounting decision journaled without a new MAX message');
expect(MonthlyBilling::plan($db,$contract(802),'2026-11')['period'] === '2026-11', 'affected first period can calculate after decision');

Service::command(HandoverQuestion::OPEN, inputFor(803, ['handover_date'=>'2026-11-09']), $eventId);
$attempt = $simulate($eventId, 'uncertain');
$case = HandoverQuestion::read($db,803);
$uncertain = inputFor(803, ['question_key'=>$case['key'], 'attempt_id'=>$attempt['id']]);
deny(fn()=>Service::command(HandoverQuestion::RETRY, $uncertain),409,'unknown outcome cannot be retried');
deny(fn()=>Service::command(HandoverQuestion::RECONCILE, $uncertain+['delivery_outcome'=>'accepted']),422,'reconciliation requires evidence');
Service::command(HandoverQuestion::RECONCILE, $uncertain+[
    'delivery_outcome'=>'failed', 'delivery_evidence'=>'Имитатор MAX подтвердил, что сообщение не принято.',
], $eventId);
expect($eventId===null && HandoverQuestion::delivery($db,803,HandoverQuestion::read($db,803))['status']==='reconciled_failed', 'confirmed non-delivery is journaled without sending');
Service::command(HandoverQuestion::RETRY, inputFor(803, ['question_key'=>$case['key'], 'attempt_id'=>$attempt['id']]), $eventId);
expect(is_int($eventId), 'explicit retry allowed after confirmed non-delivery');
$simulate($eventId, 'accepted');
expect(HandoverQuestion::delivery($db,803,HandoverQuestion::read($db,803))['status']==='accepted', 'one accepted message after reconciliation and retry');
expect($db->query("SELECT status FROM messaging_attempts WHERE id=".$db->quote($attempt['id']))->fetchColumn()==='uncertain', 'original uncertain transport receipt is preserved');
expect($snapshot()===$invoices, 'invoice periods, amounts, payments and row versions are unchanged');
