<?php
declare(strict_types=1);
namespace Modules\Rental;
use App\{DB,Http,View};
use RuntimeException;

final class WorkspaceResetController
{
    public static function section(): string
    {
        ZeroPoint::requireAdmin();
        $body='<span class="registry-ui-modal-summary">Очистка текущей работы</span>';
        if(!empty($_SESSION['workspace_result'])){$body.=RentalUI::alert('Операция завершена',$_SESSION['workspace_result']);unset($_SESSION['workspace_result']);}
        $body.=RentalUI::alert('Черновики, брони и согласования','Перед очисткой будет показан точный список. Записи и их история сохраняются в резерве. Подписанные документы, действующая аренда и финансовые документы защищены.');
        $body.='<form method="post" action="/administration/zero-point/workspace-preview"><input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'"><button class="btn btn-danger" type="submit">Очистить текущие рабочие данные</button></form>';
        $rows=[];
        foreach(DB::pdo()->query("SELECT source_key,fact_json FROM rental_facts WHERE namespace='workspace_reset' ORDER BY id DESC LIMIT 10")->fetchAll() as $r){
            $f=json_decode($r['fact_json'],true);$rows[]=[View::e($f['created_at']),View::e((string)$f['count']),!empty($f['undone'])?'Возвращено':
                '<form method="post" action="/administration/zero-point/workspace-undo"><input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'"><input type="hidden" name="target" value="'.View::e($r['source_key']).'"><input type="hidden" name="command_key" value="'.bin2hex(random_bytes(16)).'"><button class="btn btn-secondary" type="submit">Вернуть очищенные записи</button></form>'];
        }
        if($rows)$body.=RentalUI::alert('Возврат из резерва','Автоматический возврат возможен, пока последующая работа не изменила данные. Иначе резерв остаётся для сверки.').Controller::table(['Сохранено','Записей','Действие'],$rows);
        return $body;
    }
    public static function preview(): string
    {
        self::post();$plan=WorkspaceReset::preview();$key=bin2hex(random_bytes(16));
        $_SESSION['workspace_plans']=[$key=>$plan+['created_at'=>time()]];
        $body=View::heading('Очистка текущей работы','Предварительная проверка');$rows=[];
        foreach($plan['changes'] as $c)$rows[]=[View::e($c['label']),View::e(RegistryState::stage($c['before'])),'Будет убрано из текущей работы'];
        $body.=Controller::table(['Запись','Состояние','Результат'],$rows);
        if($plan['blocked'])$body.=RentalUI::alert('Защищённые записи останутся','Для них требуется отдельная сверка.','warning').Controller::table(['Запись','Причина'],array_map(static fn($r)=>[View::e($r['label']),View::e($r['reason'])],$plan['blocked']));
        if($rows)$body.='<form method="post" action="/administration/zero-point/workspace-reset"><input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'"><input type="hidden" name="command_key" value="'.$key.'">'.DirectoryUi::input('confirmation','Введите ОЧИСТИТЬ для подтверждения','','text',true).'<div class="modal-foot-actions"><a class="btn btn-secondary" href="/administration/zero-point">Отменить</a><button class="btn btn-danger" type="submit">Очистить и сохранить резерв</button></div></form>';
        else $body.=RentalUI::alert('Рабочих записей для очистки нет','Текущая работа уже пуста.').'<a class="btn btn-secondary" href="/administration/zero-point">Вернуться</a>';
        return View::page('Очистка текущей работы',$body);
    }
    public static function reset(): never
    {
        self::post();$key=(string)($_POST['command_key'] ?? '');$plan=$_SESSION['workspace_plans'][$key] ?? null;
        if(!$plan || time()-$plan['created_at']>900)throw new RuntimeException('Предварительная проверка устарела.',409);
        unset($plan['created_at']);$result=WorkspaceReset::run($plan,$key,(string)($_POST['confirmation'] ?? ''));
        $_SESSION['workspace_result']=$result['message'];Http::redirect('/administration/zero-point');
    }
    public static function undo(): never
    {
        self::post();$result=Service::command('workspace.undo',['command_key'=>(string)($_POST['command_key'] ?? ''),'target'=>(string)($_POST['target'] ?? '')]);
        $_SESSION['workspace_result']=$result['message'];Http::redirect('/administration/zero-point');
    }
    private static function post(): void
    {
        ZeroPoint::requireAdmin();if(($_SERVER['REQUEST_METHOD'] ?? '')!=='POST')throw new RuntimeException('Требуется POST.',405);Http::checkCsrf();
    }
}
