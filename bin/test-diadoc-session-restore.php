<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';
require_once APP_ROOT.'/modules/integrations/native/php/Portal.php';

use Modules\Integrations\DiadocSessionRestore;

final class DiadocRestoreFixture
{
    public array $calls = [];

    public function __construct(private array $responses) {}

    public function cookie(string $name): string
    {
        return $name==='AntiForgery'?'fixture-csrf':'';
    }

    public function request(string $method,string $url,mixed $body=null,array $headers=[]): array
    {
        $stage = Diadoc\Http::stage($method,$url,$body);
        $this->calls[] = $stage;
        $next = array_shift($this->responses);
        if ($next===null || $next[0]!==$stage) throw new LogicException('UNEXPECTED_PROVIDER_REQUEST');
        return ['status'=>$next[1],'body'=>$next[2]??'','headers'=>[],'url'=>$url];
    }
}

$assert = static function(bool $ok): void { if (!$ok) throw new LogicException('FAILED_ASSERTION'); };
$profile = ['username'=>'offline','password'=>'offline','box_id'=>'00000000-0000-4000-8000-000000000001'];
$page = '{"documentsByMessage":[],"paging":{},"totalCounters":{}}';
$challenge = '{"errorStatus":"NeedApproveSecondFactorByTotpPush","details":{"PushId":"fixture","UserId":"offline","PartialFactorToken":"fixture"}}';
$fixture = new DiadocRestoreFixture([['registry-page',200,$page]]);
$result = DiadocSessionRestore::advance(new Diadoc\WebClient($fixture),$profile,null);
$assert($result['status']==='success' && $fixture->calls===['registry-page']);

$fixture = new DiadocRestoreFixture([['registry-page',401],['auth-bootstrap',200],['auth-login',403,$challenge]]);
$result = DiadocSessionRestore::advance(new Diadoc\WebClient($fixture),$profile,null);
$assert($result['code']==='PUSH_REQUIRED' && $fixture->calls===['registry-page','auth-bootstrap','auth-login']);
$pending = $result['pending'];

$fixture = new DiadocRestoreFixture([['auth-confirm-status',200,'{"Status":"Waiting"}']]);
$result = DiadocSessionRestore::advance(new Diadoc\WebClient($fixture),$profile,$pending);
$assert($result['code']==='PUSH_NOT_CONFIRMED' && $fixture->calls===['auth-confirm-status']);

$fixture = new DiadocRestoreFixture([
    ['auth-confirm-status',200,'{"Status":"Confirmed","Code":"fixture"}'],['auth-confirm',200,'{}'],['registry-page',200,$page],
]);
$result = DiadocSessionRestore::advance(new Diadoc\WebClient($fixture),$profile,$pending);
$assert($result['status']==='success' && $fixture->calls===['auth-confirm-status','auth-confirm','registry-page']);

$fixture = new DiadocRestoreFixture([['registry-page',500]]);
try {
    DiadocSessionRestore::advance(new Diadoc\WebClient($fixture),$profile,null);
    throw new LogicException('UNEXPECTED_FALLBACK');
} catch (RuntimeException $error) {
    $assert($error->getMessage()==='EXPORT_FAILED_HTTP_500' && $fixture->calls===['registry-page']);
}
echo "PASS Diadoc existing session, explicit login/PUSH, single confirmation and failed-read guard\n";

$run = ['provider'=>'diadoc','operation'=>'diadoc-session-refresh',
    'status'=>'uncertain','result_code'=>'CONFIRMATION_CHECK_FAILED_HTTP_404'];
$statusRequest = ['stage'=>'auth-confirm-status','http'=>404,'curl_errno'=>0];
$coverage = ['auth_post_started'=>true,'requests'=>[$statusRequest]];
$assert(DiadocSessionRestore::isReconciliableStatus404($run,$coverage));
$assert(!DiadocSessionRestore::isReconciliableStatus404(array_replace($run,['status'=>'pending']),$coverage));
$assert(!DiadocSessionRestore::isReconciliableStatus404(array_replace($run,['result_code'=>'CONFIRMATION_FAILED_HTTP_404']),$coverage));
foreach ([
    [],
    [array_replace($statusRequest,['http'=>410])],
    [array_replace($statusRequest,['curl_errno'=>28])],
    [array_replace($statusRequest,['stage'=>'auth-confirm'])],
    [$statusRequest,['stage'=>'auth-confirm','http'=>0,'curl_errno'=>28]],
] as $requests) {
    $assert(!DiadocSessionRestore::isReconciliableStatus404($run,array_replace($coverage,['requests'=>$requests])));
}
$fixture = new DiadocRestoreFixture([['auth-confirm-status',404,'{}']]);
try {
    DiadocSessionRestore::advance(new Diadoc\WebClient($fixture),$profile,$pending);
    throw new LogicException('UNEXPECTED_FALLBACK');
} catch (RuntimeException $error) {
    $assert($error->getMessage()===$run['result_code'] && $fixture->calls===['auth-confirm-status']);
}
echo "PASS Diadoc exact status 404 retirement guard and final POST fence\n";
