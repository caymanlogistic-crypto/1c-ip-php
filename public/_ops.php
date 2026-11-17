<?php
declare(strict_types=1);
if(!in_array(getenv('APP_STAND'),['ui','integrations'],true)){http_response_code(404);exit;}
require dirname(__DIR__).'/bin/server-diagnostics.php';
header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
if(!App\Auth::user()){http_response_code(401);echo '{"status":"authentication_required"}';exit;}
try{echo json_encode(serverDiagnostics(),JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);}
catch(Throwable){http_response_code(503);echo '{"status":"not_ready"}';}

