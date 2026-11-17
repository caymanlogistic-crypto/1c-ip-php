<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Integrations\{EmailTemplateAdmin,TemplateCatalog};

function check(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}

$invoice=['source_key'=>'invoice'];
$package=['source_key'=>'clientPackage'];
$invoiceValues=EmailTemplateAdmin::sampleValues($invoice);
check($invoiceValues['managerEmail']==='manager@example.com','Preview must use neutral example email');
check($invoiceValues['documentLabel']==='' && $invoiceValues['actor']==='','Unavailable data must not be invented in preview');
check(!isset(EmailTemplateAdmin::variables($invoice)['documentLabel']),'Invoice editor must not offer package-only values');
check(isset(EmailTemplateAdmin::variables($package)['documentLabel']),'Package editor must offer document label');
$legal=['source_key'=>'legalApproval'];
check(isset(EmailTemplateAdmin::variables($legal)['lawyer'],EmailTemplateAdmin::variables($legal)['accountant']),'Legal template must expose its actual placeholder names');
check(!isset(EmailTemplateAdmin::variables($legal)['invoiceNumbers']),'Legal template must not offer invoice-only values');
check(EmailTemplateAdmin::sampleValues($legal)['lawyer']==='Юрист','Legal preview must show a neutral lawyer name');
check(str_contains(EmailTemplateAdmin::usage($legal)['text'],'не подключена'),'Catalog-only template must not claim active delivery');

$render=TemplateCatalog::preview('email',[
    'subject'=>'Счёт {invoiceNumbers} · договор {number}',
    'body_text'=>"Здравствуйте, {party}!\nМенеджер: {manager}, {managerEmail}",
],$invoiceValues);
check($render['subject']==='Счёт 2026-115, 2026-116 · договор 173','Subject preview must match delivery substitution');
check(str_contains($render['body'],"\nМенеджер: Менеджер по аренде, manager@example.com"),'Preview must retain line breaks');

$fields=EmailTemplateAdmin::fields(['subject'=>'"><script>alert(1)</script>','body_text'=>'</textarea><script>alert(2)</script>']);
$dom=new DOMDocument();@$dom->loadHTML('<?xml encoding="utf-8" ?>'.$fields);
check($dom->getElementsByTagName('script')->length===0,'Template content must never become executable markup');
check($dom->getElementsByTagName('textarea')->length===1,'Template content must remain in its textarea');
check($dom->getElementById('template-subject')?->getAttribute('value')==='"><script>alert(1)</script>','Subject must survive escaping without edits');
check($dom->getElementById('template-body')?->textContent==='</textarea><script>alert(2)</script>','Body must survive escaping without edits');
echo "Email template administration: PASS\n";
