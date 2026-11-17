<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{$reader=new \Modules\Integrations\CaptureReader($argv[1]??'');$out=['project'=>\Modules\Integrations\CaptureReader::PROJECT,'eligible'=>$reader->rootManifest['eligible']??null,'restoreReady'=>$reader->rootManifest['restoreReady']??null,'globalPIT'=>$reader->rootManifest['globalPIT']??null,'tables'=>[]];foreach(array_slice($argv,2) as $table){$count=0;foreach($reader->rows($table) as $row)$count++;$out['tables'][$table]=['verified_rows'=>$count]+$reader->tableInfo($table);}echo json_encode($out,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";}catch(Throwable $e){fwrite(STDERR,'CAPTURE_INSPECTION_FAILED; class='.get_class($e).'; no source values disclosed.' ."\n");exit(1);}
