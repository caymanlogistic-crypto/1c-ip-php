<?php
declare(strict_types=1);

namespace Modules\Integrations;

use App\{Auth, DB, Http};
use RuntimeException;
use Throwable;

/** Business workspace API; it cannot accept credentials, native bodies or transport endpoints. */
final class OnecController
{
    private static function input(array $input, array $allowed): void
    {
        if (array_diff(array_keys($input), $allowed)) throw new RuntimeException('NATIVE_INPUT_REJECTED', 400);
    }

    private static function identity(array $input): array
    {
        $id = $input['record_id'] ?? null;
        if (!is_string($id) || !preg_match('/^[1-9][0-9]{0,18}$/D', $id) || bccomp($id, (string)PHP_INT_MAX, 0) > 0
            || !in_array($input['record_kind'] ?? null, ['contract', 'document'], true)
            || !in_array($input['kind'] ?? null, ['counterparty', 'customer_invoice'], true)
            || !is_string($input['subject_id'] ?? null) || !preg_match('/^(?:party|invoice):[1-9][0-9]{0,18}$/D', $input['subject_id'])) {
            throw new RuntimeException('NATIVE_INPUT_REJECTED', 400);
        }
        return ['record_kind'=>$input['record_kind'], 'record_id'=>(int)$id, 'subject_id'=>$input['subject_id']];
    }

    private static function intent(array $input): string
    {
        $id=$input['intent_id']??null;
        if (!is_string($id) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $id)) throw new RuntimeException('NATIVE_INPUT_REJECTED', 400);
        return $id;
    }

    private static function actor(string $action): int
    {
        Auth::requirePermission('rental.read');
        $user=Auth::user() ?? throw new RuntimeException('ONEC_ACTOR_DENIED', 401);
        (new OnecOwnerFacade(DB::pdo(), Config::store()))->actor((int)$user['id'], $action);
        return (int)$user['id'];
    }

    private static function labels(array $fields): array
    {
        $labels=[];
        foreach ($fields as $field) {
            $labels[]=match (true) {
                str_starts_with($field,'confirmation.') => 'Бухгалтер должен подтвердить актуальный пакет.',
                str_starts_with($field,'invoice_scope.') => 'Состав объектов или период счёта ещё не подтверждён для передачи в 1С.',
                str_starts_with($field,'connection_binding.') => 'Не подтверждён текущий источник 1С и его настройки.',
                str_starts_with($field,'native_policy.invoice_kind.') => 'Не подтверждены назначение договора и услуга 1С для этого вида счёта.',
                str_starts_with($field,'native_refs.contract.') => 'Не подтверждена ссылка договора 1С для выбранного назначения.',
                str_starts_with($field,'native_refs.item.') => 'Не подтверждены услуга 1С и её версия.',
                str_starts_with($field,'native_refs.counterparty.'),str_starts_with($field,'native_party_identity.') => 'Не подтверждены реквизиты контрагента в 1С.',
                str_starts_with($field,'native_refs.currency.') => 'Не подтверждена валюта расчётов в 1С.',
                str_starts_with($field,'native_refs.organization.') => 'Не подтверждена организация в 1С.',
                default => 'Нужно подтвердить актуальные условия и состав счёта.',
            };
        }
        return array_values(array_unique($labels));
    }

    private static function result(array $intent): array
    {
        $state=$intent['state'];
        return ['intent_id'=>$intent['id'],'state'=>$state,'revision'=>(int)$intent['revision'],
            'native_number'=>$intent['native_number'],'reason_code'=>$intent['reason_code'],
            'title'=>match($state) {
                'saved'=>'Счёт сохранён в 1С и проверен.',
                'existing_verified'=>'Подтверждена существующая запись 1С.',
                'prepared'=>'Счёт подготовлен. Запись в 1С ещё не выполнялась.',
                'unknown','unconfirmed','posting','ack_received'=>'Результат требует сверки. Повторная запись не выполняется.',
                default=>'Операция 1С требует проверки.',
            }];
    }

    private static function handle(string $operation): never
    {
        header('Cache-Control: private, no-store');
        try {
            $post=in_array($operation,['prepare','dispatch','reconcile'],true);
            if (($_SERVER['REQUEST_METHOD']??'GET') !== ($post?'POST':'GET')) throw new RuntimeException('NATIVE_METHOD_REJECTED',405);
            if ($post) Http::checkCsrf();
            $actor=self::actor(match($operation){'state'=>'read_existing','reconcile'=>'reconcile',default=>$operation==='dispatch'?'create':'prepare'});
            $input=$post?$_POST:$_GET;
            $service=OnecNativeService::project(DB::pdo(),Config::store());
            if (in_array($operation,['preview','prepare'],true)) {
                self::input($input,['_csrf','record_kind','record_id','kind','subject_id']);
                $identity=self::identity($input);
                if ($operation==='preview') {
                    $result=(new OnecOwnerFacade(DB::pdo(),Config::store()))->preview($actor,$input['kind'],$identity);
                    $result['messages']=self::labels($result['missing']??[]);
                    Http::json($result);
                }
                Http::json(self::result($service->prepare($actor,$input['kind'],$identity)));
            }
            self::input($input,['_csrf','intent_id']);
            $id=self::intent($input);
            if ($operation==='dispatch' && !OnecWriteAdmission::createAllowed(DB::pdo())) {
                Http::json(['code'=>'NATIVE_WRITES_DISABLED','title'=>'Запись в 1С пока не разрешена. Счёт не отправлялся.'],409);
            }
            $result=match($operation) {
                'dispatch'=>$service->dispatch($id,$actor),
                'reconcile'=>$service->reconcile($id,$actor),
                'state'=>$service->readExisting($id,$actor),
            };
            Http::json(self::result($result));
        } catch (OnecSetupGap|OnecNormalConfirmationRequired $error) {
            Http::json(['code'=>$error->getMessage(),'missing'=>$error->fields,'messages'=>self::labels($error->fields),'written'=>false],409);
        } catch (Throwable $error) {
            $denied=in_array($error->getMessage(),['ONEC_ACTOR_DENIED','ONEC_ACTOR_SCOPE_CHANGED','NATIVE_PACKET_ACTOR_DENIED','NATIVE_PACKET_CONFIRMATION_ACTOR_CHANGED'],true);
            $status=$denied?403:(in_array($error->getCode(),[400,401,403,405],true)?$error->getCode():409);
            Http::json(['code'=>$denied?'NATIVE_ACCESS_DENIED':'NATIVE_OPERATION_NOT_CONFIRMED',
                'title'=>$denied?'Действие недоступно для текущих прав.':'Операция не подтверждена. Проверьте актуальный пакет и состояние подключения.'], $status);
        }
    }

    public static function preview(): never { self::handle('preview'); }
    public static function prepare(): never { self::handle('prepare'); }
    public static function dispatch(): never { self::handle('dispatch'); }
    public static function reconcile(): never { self::handle('reconcile'); }
    public static function state(): never { self::handle('state'); }
}
