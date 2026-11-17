<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
use Modules\Integrations\{RentalOutbox,Config,Support};
try{$id=$argv[1]??'';$r=(new RentalOutbox(\App\DB::pdo(),Config::store()))->run($id);echo Support::json($r)."\n";}catch(Throwable){fwrite(STDERR,"Outbox not dispatched; inspect durable intent/receipt. No automatic retry.\n");exit(1);}
