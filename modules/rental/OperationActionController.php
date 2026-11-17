<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,DB,Http,View};
use Modules\Integrations\{Config,RentalOutbox};
use RuntimeException;

/** Explicit operation callbacks; never routed through Service::command. */
final class OperationActionController
{
    public static function mayOperate(): bool
    {
        try {self::operator();return true;}
        catch (RuntimeException $error) {
            if (in_array($error->getCode(),[401,403],true)) return false;
            throw $error;
        }
    }

    private static function operator(): array
    {
        Access::pages(['operations'],2);
        $actor=Auth::user() ?? throw new RuntimeException('Требуется вход.',401);
        $permission=match($actor['role']) {
            'manager'=>'rental.write',
            'lawyer'=>'rental.approve.legal',
            'accountant'=>'finance.write',
            'director'=>'rental.approve.executive',
            'admin'=>'*',
            default=>throw new RuntimeException('Операция недоступна.',403),
        };
        Auth::requirePermission($permission);
        return $actor;
    }

    private static function reference(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^email:([a-f0-9]{32})$/D',$value,$match)) {
            throw new RuntimeException('Шаг не найден.',404);
        }
        return $match[1];
    }

    /** Safe for an HTTP controller: no actor/role/version is taken from input. */
    public static function execute(array $input): array
    {
        $actor=self::operator();
        $action=$input['action'] ?? '';
        if (!in_array($action,['operation.retry','operation.manual'],true)) {
            throw new RuntimeException('Действие не найдено.',404);
        }
        $id=self::reference($input['step'] ?? null);
        return DB::transaction(static function($db) use($actor,$action,$id): array {
            return $action==='operation.retry'
                ?DeliveryRetry::retryExisting($db,$id,$actor)
                :DeliveryRetry::manualExisting($db,$id,$actor);
        });
    }

    public static function manual(): string
    {
        self::operator();
        $id=self::reference($_GET['step'] ?? null);
        $query=DB::pdo()->prepare("SELECT * FROM rental_delivery_intents WHERE id=? AND provider='email'
            AND ((entity_type='contract' AND operation='contract-packet')
                OR (entity_type='document' AND operation='addendum-packet'))");
        $query->execute([$id]);
        $intent=$query->fetch();
        if (!$intent) throw new RuntimeException('Шаг не найден.',404);
        if (!in_array($intent['status'],['prepared','failed','unknown','claimed','blocked'],true)
            || Delivery::manualMarked($intent['receipt_json'])) {
            throw new RuntimeException('Нет незавершённой операции.',409);
        }
        if($intent['status']==='blocked'){
            $descriptor=OperationReadModel::blockedManualPacket($intent);
            if($descriptor===null)throw new RuntimeException('Не подтверждён состав ручной передачи.',409);
            PacketDeliveryProof::validateManualDescriptor(DB::pdo(),$descriptor);
        }
        $form='<form method="post" action="/rental/operation-action">'
            .'<input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'">'
            .'<input type="hidden" name="action" value="operation.manual">'
            .'<input type="hidden" name="step" value="email:'.$id.'">'
            .RentalUI::alert('Подтвердите фактическое выполнение вне автоматической операции.',
                'Запись сохранится в журнале, электронная доставка не будет выдумана.')
            .'<button class="btn btn-primary" type="submit">Сделано вручную</button></form>';
        return RentalUI::modal('Отметить ручное выполнение',$form,'','operations');
    }

    public static function action(): never
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') throw new RuntimeException('Метод не разрешён.',405);
        Http::checkCsrf();
        try {
            $result=self::execute($_POST);
            $worker=new RentalOutbox(DB::pdo(),Config::store());
            if (($_POST['action'] ?? '')==='operation.retry' && $result['status']==='prepared') {
                $result=array_replace($result,$worker->runForCurrentActor($result['id']));
            } elseif (($_POST['action'] ?? '')==='operation.manual') {
                self::continuePending($worker,$result['pending_intents'] ?? []);
            }
        } catch (\Throwable $error) {
            if (in_array($error->getCode(),[401,403],true)) {
                if (preg_match('/^[A-Z][A-Z0-9_]+$/D',$error->getMessage())) {
                    throw new RuntimeException($error->getCode()===401?'Требуется вход.':'Недостаточно прав для выполнения этой операции.',(int)$error->getCode(),$error);
                }
                throw $error;
            }
            if (in_array($error->getCode(),[404,405],true)) throw $error;
            $message=$error instanceof \PDOException || !$error instanceof RuntimeException
                ?'Не удалось сохранить результат операции.':$error->getMessage();
            if (preg_match('/^(?:NATIVE_PACKET_|PACKET_)[A-Z0-9_]+$/D',$message)) {
                $message='Проверьте актуальность подтверждённого пакета.';
            }
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')==='ERP-Fetch') {
                http_response_code($error->getCode()===409?409:422);
                echo RentalUI::alert($message,'','error');
                exit;
            }
            $_SESSION['rental_notice']=$message;
            Http::redirect('/rental/operations');
        }
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')==='ERP-Fetch') {
            header('Content-Type: text/html; charset=UTF-8');
            header('X-Rental-Refresh-URL: '.self::workspace());
            $_GET=['id'=>'email:'.$result['id']];
            echo OperationsRegistry::card();
            exit;
        }
        Http::redirect('/rental/operation?id='.rawurlencode('email:'.$result['id']));
    }

    /** Continue only this operation's untouched pending steps owned by the actual sender. */
    private static function continuePending(RentalOutbox $worker,array $ids): void
    {
        foreach ($ids as $id) {
            $actor=Auth::user() ?? throw new RuntimeException('Требуется вход.',401);
            $query=DB::pdo()->prepare("SELECT status,payload_json,receipt_json FROM rental_delivery_intents
                WHERE id=? AND provider='email' AND operation IN ('contract-packet','addendum-packet')");
            $query->execute([$id]);$row=$query->fetch();
            if (!$row || $row['status']!=='prepared' || Delivery::manualMarked($row['receipt_json'])) continue;
            $payload=json_decode($row['payload_json'],true,128,JSON_THROW_ON_ERROR);
            $sender=$payload['sender_context'] ?? $payload['actor_context'] ?? [];
            if ((int)($sender['id'] ?? 0)!==(int)$actor['id'] || ($sender['role_id'] ?? null)!==$actor['role_id']
                || (int)($sender['auth_version'] ?? 0)!==(int)$actor['auth_version']) continue;
            $worker->runForCurrentActor($id);
        }
    }

    private static function workspace(): string
    {
        $value=$_SERVER['HTTP_X_RENTAL_WORKSPACE_URL'] ?? '';
        $url=is_string($value)?parse_url($value):false;
        if (!$url || isset($url['scheme']) || isset($url['host']) || isset($url['fragment'])
            || ($url['path'] ?? '')!=='/rental/operations') return '/rental/operations';
        parse_str($url['query'] ?? '',$input);
        $query=[];
        if (isset($input['q']) && is_string($input['q']) && strlen($input['q'])<=1000) $query['q']=$input['q'];
        if (in_array($input['flag'] ?? null,['all','errors'],true)) $query['flag']=$input['flag'];
        return '/rental/operations'.($query?'?'.http_build_query($query):'');
    }
}
