<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Http,View};
use RuntimeException;

final class ZeroPointController
{
    public static function page(): string
    {
        ZeroPoint::requireAdmin();$body=View::heading('Нулевая точка','Восстановление коммерческих данных из проверенного снимка.');
        $body.=WorkspaceResetController::section();
        if (!empty($_SESSION['zero_point_result'])) {
            $body.=RentalUI::alert('Восстановление завершено','Предыдущее состояние сохранено. Пользователи, права и подключения не изменены.');
            unset($_SESSION['zero_point_result']);
        }
        try {$point=ZeroPoint::load();} catch (RuntimeException $error) {
            if ($error->getCode()!==409) throw $error;
            return View::page('Нулевая точка',$body.RentalUI::alert('Точка недоступна',$error->getMessage(),'warning'));
        }
        $counts=$point['data']['tables'];
        $body.=DirectoryUi::pairs([
            ['Дата состояния',date('d.m.Y',strtotime($point['as_of']))],['Область','Физическая аренда. Договоры юридических адресов исключены.'],
            ['Контрагенты',count($counts['parties'])],['Договоры',count($counts['contracts'])],
            ['Счета',count($counts['invoices'])],['Оплаты',count($counts['payments'])],
            ['Последние арендаторы','Сохранены отдельно от текущей занятости'],
        ]);
        foreach ($point['limitations'] ?? [] as $note) $body.=RentalUI::alert('Особенность исходных данных',$note,'warning');
        $body.='<form method="post" action="/administration/zero-point/preview"><input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'"><input type="hidden" name="target" value="baseline"><button class="btn btn-primary" type="submit">Проверить восстановление на '.View::e(date('d.m.Y',strtotime($point['as_of']))).'</button></form>';
        $rows=[];$receipts=[];$unreadable=0;
        foreach (glob(ZeroPoint::directory().'/backups/*.receipt.json') as $file) {
            try {$receipt=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);}
            catch (\Throwable) {$unreadable++;continue;}
            if (!is_array($receipt) || empty($receipt['created_at'])) {$unreadable++;continue;}
            $receipts[]=$receipt+['id'=>basename($file,'.receipt.json')];
        }
        usort($receipts,static fn($a,$b)=>strcmp($b['created_at'],$a['created_at']));
        foreach ($receipts as $receipt) {
            $id=$receipt['id'];
            $q=\App\DB::pdo()->prepare("SELECT 1 FROM rental_facts WHERE namespace='zero_point_operation' AND source_key=?");$q->execute([$id]);
            $label=$q->fetchColumn()?'Сохранён перед восстановлением':'Резерв незавершённой операции';
            $rows[]=[View::e($receipt['created_at']),View::e($label),
                '<form method="post" action="/administration/zero-point/preview"><input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'"><input type="hidden" name="target" value="'.View::e($id).'"><button class="btn btn-secondary" type="submit">Проверить возврат</button></form>'];
        }
        if ($unreadable) $body.=RentalUI::alert('Часть резервов недоступна','Недоступные квитанции сохранены для проверки: '.$unreadable.'.','warning');
        $body.='<span class="registry-ui-modal-summary">Состояния до восстановления</span>'.Controller::table(['Сохранено','Состояние','Действие'],$rows);
        return View::page('Нулевая точка',$body);
    }

    public static function preview(): string
    {
        ZeroPoint::requireAdmin();self::post();
        $plan=ZeroPoint::preview((string)($_POST['target'] ?? 'baseline'));
        $key=bin2hex(random_bytes(16));$_SESSION['zero_point_plans'][$key]=$plan+['created_at'=>time()];
        $rows=[];$labels=['parties'=>'Контрагенты','party_bank_accounts'=>'Банковские счета','contracts'=>'Договоры',
            'contract_objects'=>'Привязки объектов','contract_terms'=>'Условия','invoices'=>'Счета','payments'=>'Оплаты','payment_allocations'=>'Распределения оплат','reservations'=>'Брони','rental_documents'=>'Допсоглашения'];
        foreach ($labels as $table=>$label) $rows[]=[View::e($label),(string)$plan['current_counts'][$table],(string)$plan['target_counts'][$table]];
        $body=View::heading('Проверка восстановления','Состояние на '.date('d.m.Y',strtotime($plan['as_of'])));
        $body.=RentalUI::alert('Текущие коммерческие данные будут заменены','Перед изменением сохранится резерв. История команд и внешних отправок сохранится; старые отправки не повторяются. Пользователи, права, настройки подключений и файлы не откатываются.','warning');
        $body.=Controller::table(['Данные','Сейчас','После восстановления'],$rows);
        $body.='<form method="post" action="/administration/zero-point/restore"><input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'"><input type="hidden" name="command_key" value="'.$key.'">'
            .DirectoryUi::input('confirmation','Введите ВОССТАНОВИТЬ для подтверждения','','text',true)
            .'<div class="modal-foot-actions"><a class="btn btn-secondary" href="/administration/zero-point">Отменить</a><button class="btn btn-danger" type="submit">Восстановить данные</button></div></form>';
        return View::page('Проверка восстановления',$body);
    }

    public static function restore(): never
    {
        ZeroPoint::requireAdmin();self::post();$key=(string)($_POST['command_key'] ?? '');
        $plan=$_SESSION['zero_point_plans'][$key] ?? null;
        if (!$plan || time()-$plan['created_at']>900) throw new RuntimeException('Предварительная проверка устарела. Повторите её.',409);
        $_SESSION['zero_point_result']=ZeroPoint::restore($plan+['command_key'=>$key,'confirmation'=>(string)($_POST['confirmation'] ?? '')]);
        // Keep the plan briefly so a retried identical POST returns the existing receipt.
        Http::redirect('/administration/zero-point');
    }

    private static function post(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? '')!=='POST') throw new RuntimeException('Требуется POST.',405);
        Http::checkCsrf();
    }
}
