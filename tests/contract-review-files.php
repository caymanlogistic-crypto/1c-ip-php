<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Rental\{ContractReviewFiles, Importer};

$project=str_repeat('a',32);
$protocol=str_repeat('b',32);
$attachment=str_repeat('c',32);
$record=static fn(array $files): array=>['source_json'=>Importer::json([
    'review_materials'=>['version'=>1,'submitted'=>true,'files'=>$files],
])];

if (ContractReviewFiles::selectedIds($record([
    'third-party-draft'=>$project,'disagreement-protocol'=>$protocol,
]))!==[$project,$protocol]) {
    throw new RuntimeException('Legacy attachment selection must keep the original file identifiers.');
}
if (ContractReviewFiles::selectedIds($record([
    ['id'=>$project,'kind'=>'third-party-draft','description'=>'Сторонний документ'],
    ['id'=>$attachment,'kind'=>'review-attachment','description'=>'Уточнение к проекту'],
]))!==[$project,$attachment]) {
    throw new RuntimeException('New and older materials must coexist in the same revision.');
}
if (ContractReviewFiles::fromRequest([],[])!==null) {
    throw new RuntimeException('Attachments must stay optional.');
}
try {
    ContractReviewFiles::fromRequest([], [ContractReviewFiles::DESCRIPTIONS=>[2=>'Без выбранного файла']]);
} catch (RuntimeException $error) {
    if ($error->getCode()===422) {
        echo "PASS: legacy and new selections, optional attachments, missing upload rejected.\n";
        return;
    }
    throw $error;
}
throw new RuntimeException('A description without an uploaded file was accepted.');
