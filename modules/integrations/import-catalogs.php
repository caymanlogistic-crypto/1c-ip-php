<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
use Modules\Integrations\{TemplateCatalog,Config,Support};
try{$path=$argv[1]??'';if(!is_file($path)||is_link($path))throw new RuntimeException('Source required');$r=(new TemplateCatalog(\App\DB::pdo(),Config::store()))->importState(file_get_contents($path));echo Support::json($r)."\n";}catch(Throwable){fwrite(STDERR,"Catalog import not completed; check source attestation/schema. No external action.\n");exit(1);}
