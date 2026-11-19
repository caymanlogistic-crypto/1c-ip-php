<?php
declare(strict_types=1);

namespace Modules\Integrations {
    final class CabinetSyncAdmission {
        public static bool $allowed = false;
        public static function allows(\PDO $db,string $provider,string $operation,string $ref): bool {
            return self::$allowed&&[$provider,$operation,$ref]===['mosenergo','authenticate','mes-test'];
        }
    }
    final class CabinetNetwork {
        public static array $requests = [];
        public static function mesTransport(): callable {
            return static function(string $method,string $path,?array $body,array $query,array $headers): array {
                self::$requests[] = [$method,$path];
                return match ($path) {
                    '/api/v8/users/auth' => ['status'=>424,'raw'=>Support::json([
                        'transactionId'=>'12345678-1234-1234-1234-123456789abc','types'=>['EMAIL']])],
                    '/api/v7/users/12345678-1234-1234-1234-123456789abc/email/check/confirmation/send' => ['status'=>200,'raw'=>'{}'],
                    '/api/v7/users/12345678-1234-1234-1234-123456789abc/email/check/verification' =>
                        ['status'=>200,'raw'=>Support::json(['auth'=>'test-auth','access'=>'test-access'])],
                    default => throw new \RuntimeException('UNEXPECTED_FIXTURE_REQUEST'),
                };
            };
        }
    }
}

namespace {
    require dirname(__DIR__).'/app/bootstrap.php';
    use Modules\Integrations\{CabinetNetwork,CabinetSyncAdmission,Config,MesCabinetAuthorization,Support};
    $db = App\DB::pdo();
    if (!str_starts_with((string)$db->query('SELECT DATABASE()')->fetchColumn(),'ipphp_task_') || Config::liveAllowed()) {
        throw new RuntimeException('ISOLATED_FIXTURE_REQUIRED');
    }
    $assert = static function(bool $ok,string $message): void {
        if (!$ok) throw new RuntimeException($message);
    };
    $deny = static function(callable $call,string $code): void {
        try { $call(); } catch (RuntimeException $error) {
            if ($error->getMessage() === $code) return;
            throw $error;
        }
        throw new LogicException('EXPECTED_REJECTION');
    };
    $assert(Config::profile('mosenergo','mes-test')===['_reference'=>'mes-test'],'TEST_SECRET_ALREADY_EXISTS');
    $previousStand = getenv('APP_STAND');
    $oldSecrets = Config::secrets('mosenergo');
    foreach (['integration_runs','integration_connections','integration_configuration_sources'] as $table) {
        $db->exec("CREATE TEMPORARY TABLE `mes_fixture_$table` LIKE `$table`");
        $db->exec("CREATE TEMPORARY TABLE `$table` LIKE `mes_fixture_$table`");
    }
    try {
        putenv('APP_STAND=ui');
        $db->exec("INSERT INTO integration_connections(provider,enabled,revision,display_name,config_json)
            VALUES ('mosenergo',1,2,'Fixture',JSON_OBJECT('access_reference','mes-test'))");
        $db->exec("INSERT INTO integration_configuration_sources(provider,variant,source_receipt_sha256,source_revision,selected_profile,metadata_json)
            VALUES ('mosenergo','fixture',REPEAT('a',64),2,'mes-test',JSON_OBJECT())");
        Config::saveProfile('mosenergo','mes-test',['username'=>'test-user','password'=>'test-password']);
        $service = new MesCabinetAuthorization($db);
        $requestKey = Support::uuid();
        $deny(fn()=>$service->execute('start',$requestKey),'MES_AUTH_NOT_ADMITTED');
        $assert(CabinetNetwork::$requests===[],'UNADMITTED_NETWORK');
        CabinetSyncAdmission::$allowed=true;
        $first=$service->execute('start',$requestKey);
        $assert($first['code']==='OTP_REQUIRED'&&count(CabinetNetwork::$requests)===1,'LOGIN_SENT_CODE');
        $deny(fn()=>$service->execute('start',Support::uuid()),'MES_RECENT_AUTH_ATTEMPT_EXISTS');
        $deny(fn()=>$service->execute('confirm',$requestKey,'12345'),'MES_AUTH_PHASE_NOT_READY');
        $assert($service->execute('request-code',$requestKey)['code']==='OTP_SENT','SEND_NOT_ACKNOWLEDGED');
        $deny(fn()=>$service->execute('request-code',$requestKey),'MES_AUTH_PHASE_NOT_READY');
        $assert($service->execute('confirm',$requestKey,'12345')['code']==='MES_AUTHENTICATED','CONFIRM_FAILED');
        $deny(fn()=>$service->execute('confirm',$requestKey,'12345'),'MES_AUTH_PHASE_NOT_READY');
        $assert(count(CabinetNetwork::$requests)===3,'AUTH_REPLAY');
        $assert(Config::profile('mosenergo','mes-test')['session']['auth']==='test-auth','SESSION_NOT_SAVED');
        $assert($db->query("SELECT status FROM integration_runs WHERE provider='mosenergo'")->fetchColumn()==='success','JOURNAL_NOT_FINISHED');
        echo "PASS MES manual start, OTP send and verify exactly once, admission and encrypted session\n";
    } finally {
        foreach (['integration_runs','integration_connections','integration_configuration_sources'] as $table) {
            $db->exec("DROP TEMPORARY TABLE IF EXISTS `$table`,`mes_fixture_$table`");
        }
        Config::saveSecrets('mosenergo',$oldSecrets);
        $previousStand===false?putenv('APP_STAND'):putenv('APP_STAND='.$previousStand);
    }
}
