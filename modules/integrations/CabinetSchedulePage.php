<?php
declare(strict_types=1);

namespace Modules\Integrations;

use App\{Audit,Auth,Http,View};

final class CabinetSchedulePage
{
    public const URL = '/connections/schedule';
    public const TITLE = 'Расписание подключений';

    public static function allowed(): bool
    {
        return (Auth::user()['role_id']??null)==='admin';
    }

    public static function handle(): string
    {
        if (!self::allowed()) throw new \RuntimeException('Недостаточно прав для управления расписанием.',403);
        $schedule=CabinetSchedule::current();
        $message='';
        $error=false;
        $edit='';
        if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
            Http::checkCsrf();
            $edit=is_string($_POST['provider']??null)?$_POST['provider']:'';
            $version=filter_var($_POST['version']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            try {
                if ($version===false) throw new \InvalidArgumentException('Откройте актуальное расписание и повторите сохранение.');
                $schedule->save($edit,$_POST['first']??null,$_POST['second']??null,
                    ($_POST['enabled']??null)==='1',$version);
            } catch (\InvalidArgumentException|\RuntimeException $failure) {
                http_response_code($failure->getCode()===409?409:422);
                $message=$failure instanceof \InvalidArgumentException||$failure->getCode()===409
                    ?$failure->getMessage():'Сохранить расписание не удалось.';
                $error=true;
            }
            if (!$error) {
                $settings=$schedule->settings()[$edit];
                Audit::record('integration.schedule.saved','connection',$edit,null,[
                    'times'=>$settings['times'],'enabled'=>$settings['enabled'],'version'=>$settings['version'],
                ]);
                $_SESSION['cabinet_schedule_saved']=true;
                Http::redirect(self::URL);
            }
        } elseif (!empty($_SESSION['cabinet_schedule_saved'])) {
            unset($_SESSION['cabinet_schedule_saved']);
            $message='Расписание сохранено.';
        }
        $e=View::e(...);
        $body=View::heading(self::TITLE,'Время запуска по Москве');
        if ($message!=='') {
            $body.='<div class="form-alert '.($error?'alert-danger':'alert-success').'" role="'.($error?'alert':'status').'">'
                .'<div class="alert-body"><div class="alert-body-title">'.$e($message).'</div></div></div>';
        }
        $body.='<section class="table-card registry-ui-card"><div class="table-scroll" role="region" aria-label="Расписание подключений" tabindex="0">'
            .'<table class="table registry-ui-table registry-ui-data"><thead><tr><th scope="col">Подключение</th>'
            .'<th scope="col">Время запуска</th><th scope="col">Синхронизация</th><th scope="col">Действия</th></tr></thead><tbody>';
        $dialogs='';
        foreach ($schedule->settings() as $provider=>$setting) {
            $label=Support::PROVIDERS[$provider];
            $id='schedule-'.$provider;
            $body.='<tr><th scope="row">'.$e($label).'</th><td>'.$e(implode(' и ',$setting['times'])).'</td><td>'
                .($setting['enabled']?'Включена':'Приостановлена').'</td><td>'
                .'<button type="button" class="btn btn-secondary" data-dialog="'.$id.'" aria-label="Изменить расписание '.$e($label).'">Изменить</button></td></tr>';
            $dialogs.=self::dialog($id,$provider,$label,$setting,$edit===$provider);
        }
        return View::page(self::TITLE,$body.'</tbody></table></div></section>'.$dialogs,'connection-schedule');
    }

    private static function dialog(string $id,string $provider,string $label,array $setting,bool $open): string
    {
        $e=View::e(...);
        $first=$open?($_POST['first']??''):$setting['times'][0];
        $second=$open?($_POST['second']??''):($setting['times'][1]??'');
        $enabled=$open?($_POST['enabled']??null)==='1':$setting['enabled'];
        $version=$open&&http_response_code()===409?($_POST['version']??''):$setting['version'];
        return '<dialog class="modal registry-ui-modal registry-ui-modal--reading registry-ui-modal--weight-single ui-detail-card" id="'.$id.'" aria-labelledby="'.$id.'-title"'
            .($open?' data-open-on-load':'').'><form method="post" action="'.self::URL.'" class="registry-ui-modal-form">'
            .'<input type="hidden" name="_csrf" value="'.$e(Http::csrf()).'"><input type="hidden" name="provider" value="'.$e($provider).'">'
            .'<input type="hidden" name="version" value="'.$e(is_scalar($version)?$version:'').'"><div class="modal-head">'
            .'<h2 class="modal-title" id="'.$id.'-title">'.$e($label).'</h2><button type="button" class="modal-close" data-dialog-close aria-label="Закрыть">×</button></div>'
            .'<div class="modal-body registry-ui-modal-grid"><div class="form-grid-2">'
            .self::timeField($id.'-first','first','Первый запуск',$first,true)
            .self::timeField($id.'-second','second','Второй запуск (необязательно)',$second,false)
            .'</div><label class="checkbox-inline"><input type="checkbox" class="field-checkbox" name="enabled" value="1"'
            .($enabled?' checked':'').'> Синхронизация включена</label></div>'
            .'<div class="modal-foot"><div class="modal-foot-actions"><button type="button" class="btn btn-secondary" data-dialog-close>Отмена</button>'
            .'<button type="submit" class="btn btn-primary">Сохранить</button></div></div></form></dialog>';
    }

    private static function timeField(string $id,string $name,string $label,mixed $time,bool $required): string
    {
        return '<div class="field"><label class="field-label" for="'.$id.'">'.$label.'</label>'
            .'<input class="field-input" id="'.$id.'" type="time" name="'.$name.'" value="'.View::e(is_string($time)?$time:'').'"'
            .($required?' required':'').'></div>';
    }
}
