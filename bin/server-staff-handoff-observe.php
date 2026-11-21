<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\DB;
use Modules\Integrations\{Config,ServerObjectMax,StaffHandoffObservation,Support};

try {
    $uuid='/\A[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}\z/';
    if (PHP_SAPI!=='cli' || PHP_OS_FAMILY!=='Linux' || getenv('APP_STAND')!=='ui'
        || count($argv)!==4 || $argv[1]!=='--observe-two-unknown'
        || !preg_match($uuid,$argv[2]) || !preg_match($uuid,$argv[3])) {
        throw new RuntimeException('EXPLICIT_HISTORY_OBSERVATION_REQUIRED');
    }
    $db=DB::pdo();
    if ($db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_ui') throw new RuntimeException('WRONG_DATABASE');
    $scope=ServerObjectMax::settings();
    if (!$scope) throw new RuntimeException('APPROVED_TEST_PROFILE_REQUIRED');
    $checkProfile=static function() use ($db,$scope): array {
        $config=Config::get($db,'max');
        $secret=Config::secrets('max');
        Config::assertSecretRevision($secret,$scope['revision']);
        $vault=APP_ROOT.'/private/integrations/max.enc';
        $groups=$db->query("SELECT id,name,chat_id FROM messaging_groups WHERE provider='max' AND enabled=1")->fetchAll(PDO::FETCH_ASSOC);
        if (ServerObjectMax::settings()!==$scope || count($groups)!==1 || $groups[0]['id']!==$scope['group_id']
            || $groups[0]['name']!==$scope['group_name'] || $groups[0]['chat_id']!==$scope['chat_id']
            || !$config['enabled'] || (int)$config['revision']!==$scope['revision']
            || ($secret['_owner_bound_revision'] ?? null)!==$scope['revision']
            || ($secret['approved_chat_ids'] ?? null)!==[$scope['chat_id']] || empty($secret['secret'])
            || is_link($vault) || !hash_equals($scope['vault_sha256'],hash_file('sha256',$vault))) {
            throw new RuntimeException('WORKFLOW_MAX_PROFILE_CHANGED');
        }
        return $secret;
    };
    if ((int)$db->query("SELECT GET_LOCK('ipphp-provider:max',0)")->fetchColumn()!==1) throw new RuntimeException('WORKFLOW_MAX_BUSY');
    try {
        $ca=(new ServerObjectMax($db))->caFile();
        $get=static function(string $path) use ($checkProfile,$ca): array {
            $secret=$checkProfile();
            $request=curl_init('https://platform-api2.max.ru'.$path);
            $bytes='';
            curl_setopt_array($request,[
                CURLOPT_HTTPGET=>true,CURLOPT_HTTPHEADER=>['Authorization: '.$secret['secret']],
                CURLOPT_FOLLOWLOCATION=>false,CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS,CURLOPT_PROXY=>'',CURLOPT_SSL_VERIFYPEER=>true,CURLOPT_SSL_VERIFYHOST=>2,
                CURLOPT_CAINFO=>$ca,CURLOPT_CONNECTTIMEOUT=>10,CURLOPT_TIMEOUT=>18,
                CURLOPT_WRITEFUNCTION=>static function($request,string $chunk) use (&$bytes): int {
                    if (strlen($bytes)+strlen($chunk)>1048576) return 0;
                    $bytes.=$chunk;
                    return strlen($chunk);
                },
            ]);
            $ok=curl_exec($request);
            $http=(int)curl_getinfo($request,CURLINFO_RESPONSE_CODE);
            $errno=curl_errno($request);
            curl_close($request);
            if ($ok!==true || $errno || $http!==200) {
                throw new RuntimeException('MAX_HISTORY_GET_HTTP_'.$http.'_CURL_'.$errno);
            }
            $checkProfile();
            return ['http'=>$http,'data'=>json_decode($bytes,true,32,JSON_THROW_ON_ERROR)];
        };
        $observer=new StaffHandoffObservation($db,Config::store());
        $sha=$observer->observe($scope,$argv[2],$argv[3],$get);
        echo Support::json(['observation_sha256'=>$sha,'old_outcomes'=>'unknown','messages_found'=>0]),"\n";
    } finally {
        $db->query("SELECT RELEASE_LOCK('ipphp-provider:max')");
    }
} catch (Throwable $e) {
    $code=$e->getMessage();
    fwrite(STDERR,(preg_match('/\A[A-Z0-9_]{3,80}\z/',$code)?$code:'MAX_HISTORY_OBSERVATION_FAILED')."\n");
    exit(1);
}
