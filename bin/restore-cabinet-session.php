<?php
declare(strict_types=1);

use App\DB;
use Modules\Integrations\{CabinetSyncAdmission,Config,Support};

if (PHP_SAPI !== 'cli' || getenv('APP_STAND') !== 'ui' || $argc !== 2
    || !in_array($argv[1],['pik','mosvodokanal'],true)) {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR,"Usage on the common server: php bin/restore-cabinet-session.php pik|mosvodokanal\n");
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
    return [$reference,Config::profile($provider,$reference)];
}

function cabinetRestoreCode(Throwable $error,string $stage): string
{
    $message = $error->getMessage();
    if (in_array($message,['CAPTCHA_REQUIRED','LOGIN_REQUIRES_CAPTCHA'],true)) return 'CAPTCHA_REQUIRED';
    if (in_array($message,['CABINET_AUTH_NOT_ADMITTED','CABINET_ADMISSION_INVALID','PROVIDER_BUSY',
        'PROFILE_NOT_CONFIGURED','UNEXPECTED_DATABASE','PROFILE_CHANGED','SOURCE_SCHEMA_CHANGED',
        'NO_BILLING_ACCOUNTS','INVALID_SESSION','MFA_REQUIRED','LOGIN_REJECTED','INVALID_PHONE'],true)) {
        return $message;
    }
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
    [$reference,$profile] = selectedCabinetProfile($db,$provider);
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
        $client = new PikComfort\Client(null,.25,30,caFile:Support::caFile(),accountsTimeout:60);
        $stage = 'login';
        $client->login($profile['username'],$profile['password']);
        $stage = 'accounts';
        $response = $client->json(PikComfort\ENDPOINTS['accounts'],['notifications_size'=>0]);
        $accounts = confirmedBillingAccounts($response['accounts']??null,true);
        $session = $client->session();
        if (!is_string($session['token']??null) || $session['token']==='') throw new RuntimeException('INVALID_SESSION');
    } else {
        $client = new Mosvodokanal\Api(Config::root().'/private',30,150,
            Config::root().'/modules/integrations/certificates/mvk-chain.crt',recordResponses:false);
        $stage = 'login';
        $client->login($profile['username'],$profile['password']);
        $stage = 'accounts';
        $report = $client->page('NATURAL_PERSON_USER_ACCOUNT_REGISTER',[],0,0);
        $accounts = confirmedBillingAccounts(IpyaAccess\rows($report,10000)['items'],false);
        $session = $client->session();
        if (empty($session['cookies'])) throw new RuntimeException('INVALID_SESSION');
        // Validate the cookie representation with the same reader that the scheduler uses.
        $client->importSession($session);
    }

    $stage = 'save';
    DB::transaction(static function(PDO $db) use ($provider,$reference,$profile,$session): void {
        $query = $db->prepare('SELECT revision FROM integration_connections WHERE provider=? FOR UPDATE');
        $query->execute([$provider]);
        if ($query->fetchColumn()===false) throw new RuntimeException('PROFILE_CHANGED');
        [$currentReference,$currentProfile] = selectedCabinetProfile($db,$provider);
        if ($currentReference!==$reference || !hash_equals(Support::canonical($profile),Support::canonical($currentProfile))) {
            throw new RuntimeException('PROFILE_CHANGED');
        }
        $profile['session'] = $session;
        Config::saveProfile($provider,$reference,$profile);
    });
    $code = 'SESSION_RESTORED';
    $exit = 0;
} catch (Throwable $error) {
    $code = cabinetRestoreCode($error,$stage);
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
