<?php
declare(strict_types=1);
namespace Modules\Integrations;
use App\{Auth,DB,Http};

final class OnecOperationsController
{
    public static function page(string $connection): string
    {
        Auth::requirePermission('integrations.read');Auth::requirePermission('rental.read');
        $user=Auth::user();
        (new OnecOperations(DB::pdo(),Config::store()))->authorize($connection,(int)$user['id'],'read');
        return OnecOperationsView::render($connection);
    }

    public static function action(string $connection,string $action): never
    {
        header('Cache-Control: private, no-store');
        try {
            $post=!in_array($action,['preview','state'],true);
            if (($_SERVER['REQUEST_METHOD']??'GET')!==($post?'POST':'GET')) throw new \RuntimeException('NATIVE_METHOD_REJECTED',405);
            Auth::requirePermission('integrations.read');Auth::requirePermission('rental.read');
            if ($post) Http::checkCsrf();
            $input=$post?$_POST:$_GET;unset($input['_csrf']);
            $result=(new OnecOperations(DB::pdo(),Config::store()))->execute($connection,$action,(int)Auth::user()['id'],$input);
            if (!empty($result['missing'])) $result['messages']=OnecOperations::missingMessages($result['missing']);
            // Private source packets and request keys are not a public preview contract.
            unset($result['packet'],$result['packet_sha256'],$result['request_key'],$result['missing']);
            Http::json($result,in_array($result['state']??'',['awaiting_template','write_disabled'],true)?409:200);
        } catch (OnecSetupGap|OnecNormalConfirmationRequired $error) {
            Http::json(['state'=>'needs_setup','written'=>false,'messages'=>OnecOperations::missingMessages($error->fields),'title'=>'Данные пока не готовы к передаче в 1С.'],409);
        } catch (\Throwable $error) {
            $denied=in_array($error->getMessage(),['ONEC_ACTOR_DENIED','ONEC_ACTOR_SCOPE_CHANGED'],true)||$error->getCode()===403;
            $status=$denied?403:(in_array($error->getCode(),[400,401,404,405],true)?$error->getCode():409);
            Http::json(['state'=>'blocked','written'=>false,'title'=>$denied?'Недостаточно прав для этого действия.':'Действие не подтверждено. Проверьте исходный документ и настройки 1С.'], $status);
        }
    }
}
