<?php
declare(strict_types=1);
namespace IpyaAccess;
require_once __DIR__.'/../../../worker.php';
umask(0077);
ini_set('display_errors','0');
ini_set('log_errors','0');
$client=null;
try {
    if(getenv('IPPHP_ALLOW_EXTERNAL')!=='owner-authorized-project-access')failure('ACCESS_DENIED');
    $line=fgets(STDIN,262145);
    if(!is_string($line)||strlen($line)>262144||!str_ends_with($line,"\n"))failure('ACCESS_DENIED');
    $input=json_decode($line,true,64,JSON_THROW_ON_ERROR);
    if(($input['provider']??null)!=='onec'||($input['operation']??null)!=='bank_records')failure('ACCESS_DENIED');
    $period=$input['archive_period']??null;
    $skip=$input['cursor']??0;
    $budget=$input['remaining_ms']??null;
    if(!is_array($period)||array_keys($period)!==['from','to']||!is_string($period['from'])||!is_string($period['to'])
        ||!is_int($skip)||$skip<0||$skip>100000||$skip%100!==0||!is_int($budget)||$budget<1||$budget>900000)failure('ACCESS_DENIED');
    $GLOBALS['ONLINE_DEADLINE']=hrtime(true)/1e9+$budget/1000;
    [$username,$password]=credentials($input);
    $client=\Modules\Integrations\OnecReaderFactory::create(['username'=>$username,'password'=>$password,
        '_reference'=>$input['profile_reference']??'','_owner_bound_revision'=>$input['profile_revision']??null],60,'bank_read',null);
    $page=$client->archiveBank($period['from'],$period['to'],$skip);
    $result=['type'=>'result','ok'=>true,'data'=>$page,'session'=>[],'diagnostic'=>onecDiagnostic($client)];
} catch(\Throwable $error) {
    $result=['type'=>'result','ok'=>false,'error'=>errorCode($error),'diagnostic'=>onecDiagnostic($client,$error)];
}
echo json_encode($result,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
