<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{if(($argv[2]??'')!=='--verified-received-components')throw new RuntimeException('EXPLICIT_COMPONENT_POLICY_REQUIRED');$reader=new Modules\Integrations\CaptureReader($argv[1]??'');$result=(new Modules\Integrations\CaptureImport(App\DB::pdo(),Modules\Integrations\Config::store(),$reader,Modules\Integrations\CaptureFiles::directory()))->run(true);echo Modules\Integrations\Support::json($result)."\n";}catch(Throwable $e){fwrite(STDERR,'CAPTURE_IMPORT_FAILED; class='.get_class($e).'; no source values disclosed; inspect private bounded diagnostic.' ."\n");exit(1);}
