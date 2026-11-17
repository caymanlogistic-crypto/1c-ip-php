<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require dirname(__DIR__,2).'/app/bootstrap.php';
try{if(($argv[3]??'')!=='--pod=server'||($argv[4]??'')!=='--messaging=local')throw new RuntimeException('Explicit whole-source selection required');$r=\Modules\Integrations\ConfigActivation::activate(\App\DB::pdo(),$argv[1]??'',$argv[2]??'');echo \Modules\Integrations\Support::json($r)."\n";}catch(Throwable $e){$allowed=['PENDING_SOURCE_REJECTED','PENDING_PROJECT_SHAPE_REJECTED','SOURCE_RECEIPT_REQUIRED','SOURCE_VERSION_MISMATCH','SOURCE_INVENTORY_MISMATCH','UNCLASSIFIED_POD_FIELD','UNCLASSIFIED_MESSAGE_FIELD','CONFIG_SOURCE_RECONCILIATION_REQUIRED','CONFIG_HAS_LOCAL_EDITS','GROUPS_HAVE_LOCAL_EDITS'];$code=in_array($e->getMessage(),$allowed,true)?$e->getMessage():'CONFIG_ACTIVATION_REJECTED';fwrite(STDERR,$code."; no credential values disclosed.\n");exit(1);}
