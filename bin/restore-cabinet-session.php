<?php
declare(strict_types=1);

use App\DB;
use Modules\Integrations\{CabinetSyncAdmission,Config,Support};

$ifExpired = ($argv[2]??null)==='--if-expired';
if (PHP_SAPI !== 'cli' || getenv('APP_STAND') !== 'ui'
    || ($argc !== 2 && !($argc === 3 && $ifExpired))
    || !in_array($argv[1]??null,['pik','mosvodokanal'],true)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR,"Usage on the common server: php bin/restore-cabinet-session.php pik|mosvodokanal [--if-expired]\n");
    }
    exit(2);
}

// Reuse the current adapters and bounded report validator without invoking the worker entry point.
require dirname(__DIR__).'/modules/integrations/worker.php';

function confirmedBillingAccounts(mixed $rows,bool $pik): int
{
    if (!is_array($rows) || !array_is_list($rows) || count($rows)>10000) {
        throw new RuntimeException('SOURCE_SCHEMA_CHANGED');
    }
    $seen = [];
    foreach ($rows as $row) {
        if (!is_array($row) || array_is_list($row)) throw new RuntimeException('SOURCE_SCHEMA_CHANGED');
        if ($pik) {
            $number = $row['number']??null;
            if (($row['_type']??null)==='virtualaccount' && ($number===null || $number==='')) continue;
            if (($row['_type']??null)!=='mobile_dashboard'
                || (!is_string($number) && !is_int($number)) || trim((string)$number)==='') {
                throw new RuntimeException('SOURCE_SCHEMA_CHANGED');
            }
            $key = $row['_uid']??null;
            $valid = is_string($key) && preg_match('/^[A-Za-z0-9_-]{1,100}$/D',$key);
        } else {
            $key = $row['ID']??$row['ACCOUNT_ID']??$row['id']??null;
            $valid = (is_int($key) || is_string($key)) && preg_match('/^[0-9]{1,25}$/D',(string)$key);
        }
        if (!$valid || isset($seen[$key])) throw new RuntimeException('SOURCE_SCHEMA_CHANGED');
        $seen[$key] = true;
    }
    if (!$seen) throw new RuntimeException('NO_BILLING_ACCOUNTS');
    return count($seen);
}

function selectedCabinetProfile(PDO $db,string $provider): array
{
    $connection = Config::get($db,$provider);
    $config = Support::decode($connection['config_json']);
    $reference = Config::reference((string)($config['access_reference']??''));
    if (!CabinetSyncAdmission::allows($db,$provider,'authenticate',$reference)) {
        throw new RuntimeException('CABINET_AUTH_NOT_ADMITTED');
    }
    $profile = Config::profile($provider,$reference);
    Config::assertSecretRevision($profile,(int)$connection['revision']);
    return [$reference,$profile,(int)$connection['revision']];
}

function cabinetAuthExpired(string $provider,Throwable $error): bool
{
    $code = $error->getMessage();
    return $provider==='pik'
        ? $error instanceof PikComfort\AuthenticationError && $code==='HTTP 401: sign in again'
        : in_array($code,['HTTP_401','SESSION_NOT_AUTHENTICATED'],true);
}

function cabinetAccounts(Mosvodokanal\Api|PikComfort\Client $client): int
{
    if ($client instanceof PikComfort\Client) {
        $response=$client->json(PikComfort\ENDPOINTS['accounts'],['notifications_size'=>0]);
        return confirmedBillingAccounts($response['accounts']??null,true);
    }
    return confirmedBillingAccounts(IpyaAccess\rows(
        $client->page('NATURAL_PERSON_USER_ACCOUNT_REGISTER',[],0,0),10000
    )['items'],false);
}

function cabinetClient(string $provider,array $session=[]): Mosvodokanal\Api|PikComfort\Client
{
    if ($provider==='pik') {
        return new PikComfort\Client($session['token']??null,.25,30,caFile:Support::caFile(),accountsTimeout:60);
    }
    $client = new Mosvodokanal\Api(Config::root().'/private',30,150,
        Config::root().'/modules/integrations/certificates/mvk-chain.crt',recordResponses:false);
    if ($session!==[]) $client->importSession($session);
    return $client;
}

/** Null means there is no session or the provider explicitly rejected it. */
function probeCabinetSession(string $provider,mixed $session,callable $factory): ?int
{
    if (!is_array($session)) throw new RuntimeException('INVALID_SESSION');
    $field = $provider==='pik'?'token':'cookies';
    if ($session===[] || (array_key_exists($field,$session)
        && $session[$field]===($provider==='pik'?null:[]))) return null;
    if ($provider==='pik'
        ? !is_string($session[$field]??null) || $session[$field]==='' || preg_match('/\s/',$session[$field])
        : !is_array($session[$field]??null)) {
        throw new RuntimeException('INVALID_SESSION');
    }
    try {
        return cabinetAccounts($factory($provider,$session));
    } catch (Throwable $error) {
        if (cabinetAuthExpired($provider,$error)) return null;
        throw $error;
    }
}

function reserveCabinetLogin(PDO $db,string $provider,string $reference,int $revision,bool $ifExpired): string
{
    $scope = hash('sha256',Support::canonical([$provider,$reference,$revision]));
    $query = $db->prepare("SELECT result_code,status FROM integration_runs
        WHERE provider=? AND operation='cabinet-session-auth' AND fingerprint=? AND status='uncertain'
        ORDER BY created_at DESC LIMIT 1");
    $query->execute([$provider,$scope]);
    if ($query->fetch()) throw new RuntimeException('LOGIN_RESULT_UNRESOLVED');
    if ($ifExpired) {
        $query = $db->prepare("SELECT result_code,status FROM integration_runs
            WHERE provider=? AND operation='cabinet-session-auth' AND fingerprint=? ORDER BY created_at DESC LIMIT 1");
        $query->execute([$provider,$scope]);
        $latest = $query->fetch();
        if (($latest['status']??null)==='failed') throw new RuntimeException('LOGIN_OPERATOR_REQUIRED');
    }
    $id = Support::uuid();
    // The fence precedes the request in autocommit. An interrupted process cannot leave a gap for replay.
    $db->prepare("INSERT INTO integration_runs
        (id,provider,operation,request_key,actor_id,revision,fingerprint,status,result_code,coverage_json)
        VALUES(?,?,'cabinet-session-auth',?,0,?,?,'uncertain','LOGIN_INTENT_RECORDED',?)")
        ->execute([$id,$provider,$id,$revision,$scope,Support::json(['profile_reference'=>$reference,'if_expired'=>$ifExpired])]);
    return $id;
}

function finishCabinetLogin(PDO $db,string $id,string $status,string $code): void
{
    $update = $db->prepare("UPDATE integration_runs SET status=?,result_code=?,finished_at=NOW(6)
        WHERE id=? AND operation='cabinet-session-auth' AND status='uncertain'");
    $update->execute([$status,$code,$id]);
    if ($update->rowCount()!==1) throw new RuntimeException('LOGIN_INTENT_UPDATE_FAILED');
}

function cabinetRestoreCode(Throwable $error,string $stage): string
{
    $message = $error->getMessage();
    if (in_array($message,['CAPTCHA_REQUIRED','LOGIN_REQUIRES_CAPTCHA'],true)) return 'CAPTCHA_REQUIRED';
    if (in_array($message,['CABINET_AUTH_NOT_ADMITTED','CABINET_ADMISSION_INVALID','PROVIDER_BUSY',
        'PROFILE_NOT_CONFIGURED','UNEXPECTED_DATABASE','PROFILE_CHANGED','SOURCE_SCHEMA_CHANGED',
        'NO_BILLING_ACCOUNTS','INVALID_SESSION','MFA_REQUIRED','LOGIN_REJECTED','INVALID_PHONE',
        'LOGIN_RESULT_UNRESOLVED','LOGIN_OPERATOR_REQUIRED','CREDENTIAL_REVISION_CONFLICT'],true)) {
        return $message;
    }
    if ($stage==='probe') return 'SESSION_CHECK_UNCONFIRMED';
    if ($stage==='login') {
        if (in_array($message,['HTTP 401: sign in again','HTTP_401','SESSION_NOT_AUTHENTICATED'],true)) {
            return 'AUTH_REQUIRED';
        }
        // No second sign-in: a response may have been lost after the request was sent.
        return 'LOGIN_RESULT_UNKNOWN';
    }
    if ($stage==='accounts') return 'ACCOUNT_CHECK_UNCONFIRMED';
    return $stage==='save'?'SESSION_SAVE_FAILED':'CABINET_RESTORE_FAILED';
}

$provider = $argv[1];
$stage = 'preflight';
$accounts = null;
$intent = null;
$db = null;
$locked = false;
$exit = 1;
$GLOBALS['IPPHP_EMBEDDED_NATIVE'] = true;
$GLOBALS['IPPHP_NATIVE_AUTH_EPHEMERAL'] = true;
$GLOBALS['ONLINE_DEADLINE'] = hrtime(true)/1e9+100;
try {
    App\NativePlatform::requireOwnerChecks();
    $db = DB::pdo();
    if ($db->query('SELECT DATABASE()')->fetchColumn()!=='ipphp_ui') throw new RuntimeException('UNEXPECTED_DATABASE');
    $lock = 'ipphp-provider:'.$provider;
    $claim = $db->prepare('SELECT GET_LOCK(?,0)');
    $claim->execute([$lock]);
    if ((int)$claim->fetchColumn()!==1) throw new RuntimeException('PROVIDER_BUSY');
    $locked = true;
    [$reference,$profile,$revision] = selectedCabinetProfile($db,$provider);
    if ($ifExpired) {
        $stage = 'probe';
        $accounts = probeCabinetSession($provider,$profile['session']??[],'cabinetClient');
    }
    if ($accounts!==null) {
        $code = 'SESSION_CURRENT';
        $exit = 0;
    } else {
        $stage = 'preflight';
        foreach (['username','password'] as $field) {
            if (!is_string($profile[$field]??null) || $profile[$field]===''
                || preg_match('/[\x00-\x1f\x7f]/',$profile[$field])) {
                throw new RuntimeException('PROFILE_NOT_CONFIGURED');
            }
        }

        if ($provider==='pik') {
            try {
                PikComfort\phone($profile['username']);
            } catch (PikComfort\ExportError) {
                throw new RuntimeException('INVALID_PHONE');
            }
        }
        $client = cabinetClient($provider);
        if (selectedCabinetProfile($db,$provider)!==[$reference,$profile,$revision]) throw new RuntimeException('PROFILE_CHANGED');
        $intent = reserveCabinetLogin($db,$provider,$reference,$revision,$ifExpired);
        $stage = 'login';
        $client->login($profile['username'],$profile['password']);
        $stage = 'accounts';
        $accounts = cabinetAccounts($client);
        $session = $client->session();
        if ($client instanceof PikComfort\Client) {
            if (!is_string($session['token']??null) || $session['token']==='') throw new RuntimeException('INVALID_SESSION');
        } else {
            if (empty($session['cookies'])) throw new RuntimeException('INVALID_SESSION');
            $client->importSession($session);
        }

        $stage = 'save';
        DB::transaction(static function(PDO $db) use ($provider,$reference,$profile,$revision,$session,$intent): void {
            $query = $db->prepare('SELECT revision FROM integration_connections WHERE provider=? FOR UPDATE');
            $query->execute([$provider]);
            if ($query->fetchColumn()===false) throw new RuntimeException('PROFILE_CHANGED');
            [$currentReference,$currentProfile,$currentRevision] = selectedCabinetProfile($db,$provider);
            if ($currentReference!==$reference || $currentRevision!==$revision
                || !hash_equals(Support::canonical($profile),Support::canonical($currentProfile))) {
                throw new RuntimeException('PROFILE_CHANGED');
            }
            $profile['session'] = $session;
            Config::saveProfile($provider,$reference,$profile);
            finishCabinetLogin($db,$intent,'success','SESSION_RESTORED');
        });
        $code = 'SESSION_RESTORED';
        $exit = 0;
    }
} catch (Throwable $error) {
    $code = cabinetRestoreCode($error,$stage);
    if ($intent!==null) {
        $status = $stage==='login' && in_array($code,['CAPTCHA_REQUIRED','MFA_REQUIRED','LOGIN_REJECTED','AUTH_REQUIRED'],true)
            ? 'failed' : 'uncertain';
        try {
            finishCabinetLogin($db,$intent,$status,$code);
        } catch (Throwable) {
            $code = 'LOGIN_RESULT_UNRESOLVED';
        }
    }
} finally {
    if ($locked) {
        try {
            $release=$db->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lock]);
        } catch (Throwable) {
            // The process exit closes the database connection and releases the lock.
        }
    }
}
echo Support::json([
    'provider'=>$provider,'status'=>$exit===0?'success':'failed','code'=>$code,'accounts'=>$accounts,
]),PHP_EOL;
exit($exit);
