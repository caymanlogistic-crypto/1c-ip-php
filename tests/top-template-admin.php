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
checkTopAdmin($notice['event_key'] === 'task-received' && str_contains($notice['text'], 'Проверьте документ'), 'Actual task template not rendered');
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
