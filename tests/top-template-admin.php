<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Integrations\{TopTemplateAdmin as Admin, TemplateCatalog};
use Modules\Rental\WorkflowNotices;

function checkTopAdmin(bool $pass, string $message): void {
    if (!$pass) throw new RuntimeException($message);
}

$defaults = json_decode(file_get_contents(APP_ROOT.'/modules/integrations/defaults/top-catalog.json'), true, 32, JSON_THROW_ON_ERROR);
$catalog = [];
foreach ($defaults['entries'] as $source) {
    $entry = $source + ['source_key' => $source['id'], 'version' => 1];
    $entry['body_text'] = $source['message'];
    $config = Admin::previewConfig($entry);
    TemplateCatalog::validateText($entry['title'], TemplateCatalog::TOP_VARS, 80, true);
    TemplateCatalog::validateText($entry['body_text'], TemplateCatalog::TOP_VARS, 600);
    $render = TemplateCatalog::preview('top', $entry, $config['values']);
    checkTopAdmin($render['title'] !== '' && $render['body'] !== '', 'Default preview is empty: '.$source['id']);
    $catalog[$source['id']] = $entry;
}

$event = ['event_id' => 1100, 'entity_kind' => 'contract', 'record_number' => '173',
    'actor_name' => 'Анна', 'party_name' => 'ООО «Пример»', 'entity_version' => 3,
    'event_kind' => 'submit', 'reason' => 'Проверить файл', 'text' => 'Передано на проверку'];
$task = ['id' => 173, 'key' => 'review', 'severity' => 'red', 'text' => 'Проверьте документ', 'page' => 'approvals'];
$notice = WorkflowNotices::task($task, $event, $catalog);
checkTopAdmin($notice['severity'] === 'red', 'Text template changed actual task severity');
checkTopAdmin($notice['event_key'] === 'task-received'
    && $notice['title'] === 'Договор №173. ООО «Пример»'
    && $notice['text'] === 'Согласовать и подготовить пакет', 'Recipient notice did not identify the approval');
foreach (['№173', 'Договор №173'] as $number) {
    $prefixed=WorkflowNotices::task($task,array_replace($event,['record_number'=>$number]),$catalog);
    checkTopAdmin($prefixed['title']==='Договор №173. ООО «Пример»','Duplicate contract number prefix');
}
foreach (['','№','Договор №'] as $number) {
    $missing=WorkflowNotices::task($task,array_replace($event,['record_number'=>$number]),$catalog);
    checkTopAdmin($missing['title']==='Договор. ООО «Пример»','Empty contract number displayed');
}
checkTopAdmin($notice['dedupe_text'] === 'Договор №173. Проверьте документ Раздел: approvals.', 'Former fingerprint input changed');

$accountantEvent = array_replace($event, ['event_kind'=>'legalApprove', 'text'=>'Договор №173: legal → accounting']);
$accountantTask = WorkflowNotices::task($task, $accountantEvent, $catalog);
checkTopAdmin($accountantTask['text'] === 'Проверьте и подтвердите комплект.', 'Accountant saw a generic task');
$lawyerSender = WorkflowNotices::sender($accountantEvent, $catalog);
checkTopAdmin($lawyerSender['text'] === 'Согласовано юристом и передано бухгалтеру.', 'Lawyer saw raw transition text');

$return = array_replace($event, ['entity_kind'=>'document', 'record_number'=>'ДС№2', 'contract_number'=>'Договор №173',
    'event_kind'=>'accountantReturn', 'reason'=>'Уточните дату оплаты.']);
$lawyerTask = WorkflowNotices::task($task, $return, $catalog);
checkTopAdmin($lawyerTask['title'] === 'ДС №2 к договору №173. ООО «Пример»'
    && $lawyerTask['text'] === 'Доработайте документ по замечанию бухгалтерии. Уточните дату оплаты.', 'Return reason or addendum reference lost');
$accountantSender = WorkflowNotices::sender($return, $catalog);
checkTopAdmin($accountantSender['text'] === 'Возвращено юристу на доработку. Уточните дату оплаты.', 'Sender return reason lost');

$lateTask = WorkflowNotices::task($task + ['legal_sla'=>['state'=>'KNOWN', 'working_seconds'=>21600]], $event, $catalog);
checkTopAdmin(str_contains($lateTask['text'],'Истекли 6 рабочих часов'), 'Legal due warning lost');
$elsewhere = WorkflowNotices::task(array_replace($task,['page'=>'reconciliation']), $event, $catalog);
checkTopAdmin(!isset($elsewhere['dedupe_text']) && $elsewhere['title']==='Новая задача или уведомление',
    'A notice outside approvals changed');
$invoiceEvent = array_replace($event,['event_kind'=>'firstInvoice']);
checkTopAdmin(!isset(WorkflowNotices::task($task,$invoiceEvent,$catalog)['dedupe_text']), 'Unrelated event changed');
$catalog['document-submitted']['tone'] = 'danger';
$sender = WorkflowNotices::sender($event, $catalog);
checkTopAdmin($sender !== null && $sender['severity'] === 'green', 'Catalog tone recolored sender success');
checkTopAdmin(Admin::usage($catalog['task-received'])['active'], 'Active workflow template not identified');
checkTopAdmin(!Admin::usage($catalog['booking-created'])['active'], 'Unwired booking template presented as active');

$fields = Admin::fields(['title' => '"><script>alert(1)</script>', 'body_text' => '</textarea><script>alert(1)</script>']);
checkTopAdmin(!str_contains($fields, '<script>'), 'Stored template escaped its form field');
$unknownRejected = false;
try { TemplateCatalog::validateText('{unknown}', TemplateCatalog::TOP_VARS, 600); }
catch (InvalidArgumentException) { $unknownRejected = true; }
checkTopAdmin($unknownRejected, 'Unknown placeholder accepted');
echo "PASS top catalog previews; real red task and green sender severity; unwired entry disclosure; escaped fields; unknown placeholder rejected\n";
