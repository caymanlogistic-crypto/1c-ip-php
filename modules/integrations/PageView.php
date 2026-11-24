<?php
declare(strict_types=1);
namespace Modules\Integrations;
use App\{Auth,DB,Http,View};

/** PHP rendering of C connection/page and M messaging/settings/history templates. No geometry CSS. */
final class PageView
{
    public static function canEdit(string $provider,string $permission): bool {$actor=Auth::user();return $actor!==null&&Auth::can($permission)&&(empty($actor['grants_configured'])||\App\PagePolicy::level('connections.'.$provider,$actor)>=2);}
    private static function hidden(string $p,string $action): string {return '<input type="hidden" name="_csrf" value="'.Support::e(Http::csrf()).'"><input type="hidden" name="provider" value="'.$p.'"><input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="request_key" value="'.Support::uuid().'">';}
    private static function field(string $name,string $label,mixed $value='',string $type='text',string $extra=''): string {$id='field-'.preg_replace('/[^a-z0-9_-]/i','-',$name);return '<div class="field"><label class="field-label" for="'.$id.'">'.Support::e($label).'</label><input id="'.$id.'" class="field-input" name="'.Support::e($name).'" type="'.$type.'" value="'.Support::e($value).'" '.$extra.'></div>';}
    private static function text(string $name,string $label,string $value='',int $rows=5): string {return '<div class="field"><label class="field-label" for="field-'.$name.'">'.Support::e($label).'</label><textarea id="field-'.$name.'" class="field-textarea" name="'.$name.'" rows="'.$rows.'">'.Support::e($value).'</textarea></div>';}
    public static function tabs(array $items,string $active): string {$h='<form method="get" class="registry-ui-tabs" role="tablist" aria-label="Подключение">';foreach($items as $id=>$label)$h.='<button type="submit" role="tab" name="tab" value="'.$id.'"'.($id===$active?' class="is-active" aria-selected="true"':' aria-selected="false"').'>'.$label.'</button>';return $h.'</form>';}
    private static function notice(): string {return !empty($_GET['result'])?'<div class="form-alert alert-info" role="status"><span class="alert-mark" aria-hidden="true">!</span><div class="alert-body"><div class="alert-body-title">'.Support::e($_GET['result']).'</div></div></div>':'';}
    private static function evidence(string $provider): string
    {
        if ($provider === 'onec') return '';
        $evidence = (new ConnectionEvidence(DB::pdo(),Config::store()))->read($provider);
        $text = ConnectionEvidence::text($evidence);
        if ($evidence['checked_at'] !== null) $text .= ' Последний результат: '.date('d.m.Y H:i',strtotime($evidence['checked_at'])).'.';
        return '<p class="registry-ui-secondary" role="status">'.Support::e($text).'</p>';
    }
    public static function header(string $title,string $description,string $actions): string {return '<div class="page-head registry-ui-head"><div class="page-head-left"><span class="rp-identity-icon"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><path d="M14 2v6h6M8 13h8M8 17h8"/></svg></span><span class="rp-head-text"><h1 class="page-title">'.Support::e($title).'</h1><span class="page-summary">'.Support::e($description).'</span></span></div><div class="page-head-actions">'.$actions.'</div></div>';}
    public static function render(string $p): string {return in_array($p,['email','max'],true)?self::messages($p):self::provider($p);}
    private static function provider(string $p): string {
        $e=Support::e(...);$db=DB::pdo();$c=Config::get($db,$p);$config=json_decode($c['config_json'],true);$tab=(string)($_GET['tab']??'data');if(!in_array($tab,['data','settings','history'],true))$tab='data';$edit=self::canEdit($p,'integrations.write');$title=Support::PROVIDERS[$p];$description=$p==='bank'?'Поступления на расчётный счёт из 1С':'Проверка подключения и получение файлов документов';$body=self::header($title,$description,($p === 'onec' ? '<button class="btn btn-secondary" type="button" data-dialog="onec-settings">Настройки</button>' : '').($edit?'<button class="btn btn-primary" type="submit" form="connection-check">Проверить</button>':'')).self::notice().self::evidence($p);
        if($edit)$body.='<form method="post" action="/integrations/action" id="connection-check">'.self::hidden($p,'check').'</form>';
        $cabinet = in_array($p,CabinetSyncAdmission::PROVIDERS,true);
        $openSettings = $p === 'onec' && $tab === 'settings';
        if ($openSettings) $tab = 'data';
        $body.=self::tabs($p === 'onec' ? ['data'=>'Данные','history'=>'История'] : ['data'=>'Данные','settings'=>'Настройки','history'=>$cabinet?'Журнал загрузок':'История'],$tab);
        if($tab==='data'){$body.='<section role="tabpanel" id="probe-panel" class="demo-stack">'.(!$c['enabled']?'<span class="registry-ui-secondary" id="settings-note">Сначала сохраните настройки подключения.</span>':'').Controller::documents($p).'</section>';if($edit && $p !== 'onec'){$body.='<div class="inline-actions"><button class="btn btn-secondary" type="button" data-dialog="connection-collect">Получить данные</button><button class="btn btn-secondary" type="button" data-dialog="connection-import">Импортировать снимок</button></div><dialog class="modal registry-ui-modal registry-ui-modal--reading ui-detail-card" id="connection-collect" aria-labelledby="collect-title"><form method="post" action="/integrations/action" class="registry-ui-modal-form">'.self::hidden($p,'sync').'<div class="modal-head"><h2 class="modal-title" id="collect-title">Получение документов · '.$e($title).'</h2><button type="button" class="modal-close" data-dialog-close aria-label="Закрыть">×</button></div><div class="modal-body registry-ui-modal-grid"><div class="form-grid-2">'.self::field('from','Период с',$p==='onec'?'2026-09-01':'2026-10-01','date','required').self::field('to','По',date('Y-m-d'),'date','required').'</div><p class="registry-ui-modal-secondary">Будут сохранены только подтверждённые данные выбранного источника. Полнота периода показывается отдельно.</p></div><div class="modal-foot"><div class="modal-foot-actions"><button type="button" class="btn btn-secondary" data-dialog-close>Отмена</button><button type="submit" class="btn btn-primary">Получить данные</button></div></div></form></dialog><dialog class="modal registry-ui-modal registry-ui-modal--reading ui-detail-card" id="connection-import" aria-labelledby="import-title"><form method="post" action="/integrations/action" enctype="multipart/form-data" class="registry-ui-modal-form">'.self::hidden($p,'import').'<div class="modal-head"><h2 class="modal-title" id="import-title">Импорт снимка · '.$e($title).'</h2><button type="button" class="modal-close" data-dialog-close aria-label="Закрыть">×</button></div><div class="modal-body registry-ui-modal-grid"><div class="field"><label class="field-label" for="snapshot">Подготовленный снимок источника</label><input id="snapshot" name="snapshot" type="file" accept=".json,application/json" required></div></div><div class="modal-foot"><div class="modal-foot-actions"><button type="button" class="btn btn-secondary" data-dialog-close>Отмена</button><button type="submit" class="btn btn-primary">Импортировать</button></div></div></form></dialog>';}}
        elseif($tab==='settings'){$body.='<section role="tabpanel" id="settings-panel" class="table-card registry-ui-card"><form method="post" action="/integrations/action" id="connection-settings">'.self::hidden($p,'save').'<input type="hidden" name="revision" value="'.$e($c['revision']).'"><section class="panel-section"><div class="form-grid-2">'.self::field('label','Название подключения',$config['label']??$title).'<div class="field"><span class="field-label">Статус</span><label class="checkbox-inline"><input class="field-checkbox" type="checkbox" name="enabled" value="1"'.($c['enabled']?' checked':'').'> Подключение включено</label></div>'.self::field('access_reference','Профиль доступа',$config['access_reference']??'').self::field('timeout_seconds','Лимит проверки, секунд',$config['timeout_seconds']??300,'number','min="30" max="900"').self::text('note','Примечание',$config['note']??'').'</div><div id="settings-feedback" aria-live="polite"></div>'.($edit?'<button type="submit" class="btn btn-primary">Сохранить</button>':'').'</section></form></section>';
            if($p==='mosenergo'&&$edit)$body.='<section class="table-card registry-ui-card"><section class="panel-section"><h2 class="section-title">Подтверждение входа</h2><div class="inline-actions"><form method="post" action="/integrations/action">'.self::hidden($p,'login').'<button class="btn btn-secondary">Начать вход</button></form><form method="post" action="/integrations/action">'.self::hidden($p,'send_code').'<button class="btn btn-secondary">Запросить код</button></form></div><form method="post" action="/integrations/action">'.self::hidden($p,'confirm').self::field('code','Код подтверждения','','text','inputmode="numeric" pattern="[0-9]{5}" required').'<button class="btn btn-primary">Подтвердить</button></form></section></section>';
        }
        elseif ($cabinet) $body .= self::cabinetHistory($db,$p,$edit);
        else {$q=$db->prepare('SELECT *,TIMESTAMPDIFF(MICROSECOND,created_at,finished_at) DIV 1000 AS duration_ms FROM integration_runs WHERE provider=? ORDER BY created_at DESC LIMIT 100');$q->execute([$p]);$body.='<section role="tabpanel" id="history-panel" class="table-card registry-ui-card"><div class="table-scroll"><table class="table registry-ui-table registry-ui-data" data-column-profile="connection-history"'.($p==='onec'?' data-column-spec="'.$e(Support::json([['key'=>'started','type'=>'text','size'=>'long'],['key'=>'result','type'=>'text','size'=>'flex'],['key'=>'duration','type'=>'text','size'=>'code']])).'"':'').'><thead><tr><th scope="col">Начало</th><th scope="col">Результат</th><th scope="col">Время</th></tr></thead><tbody id="history-body">';$n=0;foreach($q as $r){$n++;$body.='<tr tabindex="0"><td>'.$e(date('d.m.Y H:i:s',strtotime($r['created_at']))).'</td><td>'.Controller::status($r['status']).'<span class="registry-ui-secondary"> '.Controller::resultCode($r['result_code']??'').'</span>'.($edit&&$r['operation']==='sync'&&in_array($r['status'],['failed','pending'],true)?'<form method="post" action="/integrations/action">'.self::hidden($p,'resume').'<input name="run_id" type="hidden" value="'.$e($r['id']).'"><button class="btn btn-toolbar">Продолжить</button></form>':'').'</td><td>'.$e($r['duration_ms']??'—').' мс</td></tr>';}if(!$n)$body.='<tr><td colspan="3">Проверок ещё не было.</td></tr>';$body.='</tbody></table></div></section>';}
        if ($p === 'onec') {
            $body .= self::onecSettings($c, $config, $edit, $openSettings);
            $body .= '<script defer src="/design/system/palette-runtime.js"></script><script defer src="/design/system/registry-interactions.js"></script>';
        }
        if ($cabinet && $tab==='history') $body .= '<script defer src="/design/system/registry-interactions.js"></script>';
        return View::page($title,$body,'connection-'.$p,$p === 'onec' ? ['connection-onec-ui.js'] : []);
    }
    private static function cabinetHistory(\PDO $db,string $provider,bool $edit): string
    {
        $e=Support::e(...);
        $query=$db->prepare('SELECT *,TIMESTAMPDIFF(MICROSECOND,created_at,finished_at) DIV 1000 AS duration_ms
            FROM integration_runs WHERE provider=? ORDER BY created_at DESC LIMIT 100');
        $query->execute([$provider]);
        $labels=['Начало','Завершение','Запуск','Период','Результат','Получено'];
        $sizes=['long','long','code','long','flex','short'];
        $spec=array_map(static fn($index,$size)=>['key'=>'column_'.$index,'type'=>'text','size'=>$size],array_keys($sizes),$sizes);
        $html='<section role="tabpanel" id="history-panel" class="table-card registry-ui-card"><div class="table-scroll">'
             .'<table class="table registry-ui-table registry-ui-data registry-ui-data--columns" data-column-profile="cabinet-history" data-column-spec="'.$e(Support::json($spec)).'"><thead><tr>';
        foreach($labels as $label) $html.='<th scope="col">'.$e($label).'</th>';
        $html.='</tr></thead><tbody id="history-body">';
        $empty=true;
        foreach($query as $run) {
            $empty=false;
            $coverage=json_decode($run['coverage_json']??'{}',true)?:[];
            $context=$coverage['command_context']??[];
            $hourly=($context['trigger']??null)==='hourly';
            $status=$run['status']==='pending'&&$hourly&&time()-strtotime($run['created_at'])<2700
                ?'Выполняется':Controller::status($run['status']);
            $result=$status.'<span class="registry-ui-secondary"> '.Controller::resultCode($run['result_code']??'').'</span>';
            if($edit&&!$hourly&&$run['operation']==='sync'&&in_array($run['status'],['failed','pending'],true)) {
                $result.='<form method="post" action="/integrations/action">'.self::hidden($provider,'resume')
                    .'<input name="run_id" type="hidden" value="'.$e($run['id']).'"><button class="btn btn-toolbar">Продолжить</button></form>';
            }
            $start=$e(date('d.m.Y H:i:s',strtotime($run['created_at'])));
            $html.='<tr><td>'.$start.'</td>';
            $finish=$run['finished_at']?date('d.m.Y H:i:s',strtotime($run['finished_at'])):'—';
            $period=isset($context['from'],$context['to'])?date('d.m.Y',strtotime($context['from'])).' — '.date('d.m.Y',strtotime($context['to'])):'—';
            $count=isset($coverage['records_imported'])?(string)$coverage['records_imported']:'—';
            if($run['operation']==='sync-files') {
                $count='Файлов '.$coverage['files']['saved'].' из '.$coverage['files']['required'];
            } elseif($hourly&&$run['status']==='success'&&!($coverage['complete']??false)) {
                $result.='<span class="registry-ui-secondary"> Полнота не подтверждена.</span>';
            }
            $html.='<td>'.$e($finish).'</td><td>'.($hourly?'По расписанию':'Вручную').'</td><td>'.$e($period).'</td>'
                .'<td>'.$result.'</td><td>'.$e($count).'</td>';
            $html.='</tr>';
        }
        if($empty) $html.='<tr><td colspan="'.count($labels).'">Загрузок пока не было.</td></tr>';
        return $html.'</tbody></table></div></section>';
    }
    public static function onecSettings(array $connection, array $config, bool $edit, bool $open): string
    {
        $e = Support::e(...);
        return '<dialog class="modal registry-ui-modal registry-ui-modal--confirmation ui-detail-card" id="onec-settings" aria-labelledby="onec-settings-title"'.($open ? ' data-open-on-load' : '').'><form method="post" action="/integrations/action" class="registry-ui-modal-form" id="connection-settings">'
            .self::hidden('onec','save').'<input type="hidden" name="revision" value="'.$e($connection['revision']).'"><input type="hidden" name="access_reference" value="'.$e($config['access_reference']??'').'">'
            .'<div class="modal-head"><h2 class="modal-title" id="onec-settings-title">Настройки · 1С</h2><button type="button" class="modal-close" data-dialog-close aria-label="Закрыть настройки">×</button></div>'
            .'<div class="modal-body registry-ui-modal-grid"><fieldset class="registry-ui-modal-grid"'.(!$edit ? ' disabled' : '').'>'
            .self::field('label','Название подключения',$config['label']??'1С')
            .'<div class="form-grid-2"><div class="field"><span class="field-label">Статус</span><label class="checkbox-inline"><input class="field-checkbox" type="checkbox" name="enabled" value="1"'.($connection['enabled']?' checked':'').'> Подключение включено</label></div>'
            .self::field('timeout_seconds','Лимит проверки, секунд',$config['timeout_seconds']??300,'number','min="30" max="900" required').'</div>'
            .self::text('note','Примечание',$config['note']??'',3).'</fieldset><div id="settings-feedback" aria-live="polite"></div></div>'
            .'<div class="modal-foot"><div class="modal-foot-actions"><button type="button" class="btn btn-secondary" data-dialog-close>Отмена</button>'.($edit?'<button type="submit" class="btn btn-primary">Сохранить</button>':'').'</div></div></form></dialog>';
    }
    private static function messages(string $p): string {
        $db=DB::pdo();$e=Support::e(...);$c=Config::get($db,$p);$config=json_decode($c['config_json'],true);$edit=self::canEdit($p,'messaging.write');$send=self::canEdit($p,'messaging.send');$tab=(string)($_GET['tab']??'data');$settings=$tab==='settings';if(!in_array($tab,['data','history','templates'],true))$tab='data';$title=$p==='email'?'Электронная почта':'MAX';$actions=($edit?'<button type="button" class="btn btn-secondary" data-dialog="message-settings">Настройки</button>':'').($send?'<button type="submit" class="btn btn-secondary" form="message-check">Проверить подключение</button>':'');$body=self::header($title,$p==='email'?'Отправка писем по SMTP':'Отправка сообщений ботом в выбранные группы',$actions).self::notice().self::evidence($p);$body.=self::tabs(['data'=>'Отправка','history'=>'Журнал отправок'],$tab);if($send)$body.='<form method="post" action="/integrations/action" id="message-check">'.self::hidden($p,'check').'</form>';
        $q=$db->prepare('SELECT * FROM messaging_groups WHERE provider=? ORDER BY name');$q->execute([$p]);$groups=$q->fetchAll(\PDO::FETCH_ASSOC);
        if($tab==='history'){$search=trim((string)($_GET['q']??''));$where='provider=?';$args=[$p];if($search!==''){$where.=' AND (target LIKE ? OR subject LIKE ? OR body_text LIKE ? OR result_code LIKE ?)';for($i=0;$i<4;$i++)$args[]='%'.$search.'%';}$q=$db->prepare('SELECT COUNT(*) FROM messaging_attempts WHERE '.$where);$q->execute($args);$count=(int)$q->fetchColumn();$q=$db->prepare('SELECT * FROM messaging_attempts WHERE '.$where.' ORDER BY created_at DESC LIMIT 100');$q->execute($args);$body.='<section class="table-card registry-ui-card registry-ui-sticky-table registry-ui-sticky-table--wide-scroll registry-ui-scroll" data-registry-id="messaging-'.$p.'-history"><div class="table-toolbar registry-ui-toolbar"><div class="registry-ui-checkboxes"><span class="found-label">Найдено: <b>'.$count.'</b></span></div><form class="toolbar-right" method="get"><input name="tab" value="history" type="hidden"><div class="registry-ui-search-group"><input class="registry-ui-search" type="search" name="q" value="'.$e($search).'" placeholder="Поиск по журналу" aria-label="Поиск по журналу"></div></form></div><div class="table-scroll" role="region" tabindex="0" aria-label="Журнал отправок"><table class="table registry-ui-table registry-ui-data registry-ui-data--columns" data-column-profile="messaging-journal"><thead><tr><th scope="col">Дата и время</th><th scope="col">Получатель</th><th scope="col">Сообщение</th><th scope="col">Результат</th></tr></thead><tbody>';foreach($q as $a){$body.='<tr tabindex="0"><td>'.$e(date('d.m.Y H:i:s',strtotime($a['created_at']))).'</td><td><span class="registry-ui-primary">'.$e($a['target']==='@connection'?'Проверка подключения':$a['target']).'</span></td><td><span class="registry-ui-primary">'.$e($a['subject']?:mb_strimwidth($a['body_text'],0,100,'…')).'</span></td><td><strong class="'.($a['status']==='accepted'||$a['status']==='success'?'registry-ui-success':($a['status']==='failed'?'registry-ui-error':'')).'">'.Controller::status($a['status']).'</strong><span class="registry-ui-secondary"> '.Controller::resultCode($a['result_code']??'').'</span></td></tr>';}if(!$count)$body.='<tr><td colspan="4"><span class="registry-ui-secondary">Отправок и проверок пока нет.</span></td></tr>';$body.='</tbody></table></div></section>';}
        else {$body.='<section class="demo-stack"><section class="table-card registry-ui-card"><section class="panel-section"><h2 class="section-title">Подключение</h2><p class="registry-ui-primary">'.($c['enabled']?($p==='email'?'Настройки сохранены. SMTP: '.$e($config['host']??'').':'.$e($config['port']??'').' · Отправитель '.$e($config['from_email']??''):'Групп: '.count(array_filter($groups,static fn($g)=>$g['enabled'])).'. Отметьте получателей ниже.'):'Откройте «Настройки», укажите доступ и сохраните. После этого проверьте подключение и отправьте тест.').'</p><p class="registry-ui-secondary">Проверка подключения не отправляет сообщения. «Отправить тест» выполняет настоящую отправку указанным получателям.</p></section></section>';
            if($send){$fields=$p==='email'?self::text('targets','Получатели','',2).self::field('subject','Тема письма','','text','maxlength="200" required'):'<div class="field"><span class="field-label" id="message-groups-label">Группы для отправки</span><div class="registry-ui-checkboxes" role="group" aria-labelledby="message-groups-label">';if($p==='max'){foreach($groups as $g)if($g['enabled'])$fields.='<label class="checkbox-inline"><input name="targets[]" type="checkbox" value="'.$e($g['id']).'"> '.$e($g['name']).'</label>';if(!array_filter($groups,static fn($g)=>$g['enabled']))$fields.='<span class="field-msg">Добавьте и включите хотя бы одну группу в настройках.</span>';$fields.='</div></div>';}$body.='<form method="post" action="/integrations/action" enctype="multipart/form-data" class="table-card registry-ui-card demo-form-width">'.self::hidden($p,'send').'<section class="panel-section"><h2 class="section-title">Тестовая отправка</h2><div class="form-grid-2">'.$fields.'</div>'.self::text('text','Текст сообщения').'</section>';if($p==='email')$body.='<section class="panel-section"><h2 class="section-title">Вложения</h2><div class="file-dropzone"><b>Перетащите файлы сюда</b><label class="btn btn-secondary">Выбрать файлы<input class="file-input-hidden" type="file" name="attachments[]" multiple data-integration-files></label><span class="field-msg">До 10 файлов, суммарно до 10 МБ.</span></div><div class="file-list demo-file-list" data-integration-file-list aria-live="polite"></div></section>';$body.='<section class="panel-section"><button type="submit" class="btn btn-primary"'.(!$c['enabled']?' disabled':'').'>Отправить тест</button></section></form>';}$body.='</section>';}
        if($edit)$body.=self::messageSettings($p,$c,$config,$groups,$settings);$body.='<script>'.self::uiScript().'</script>';return View::page($title,$body,'connection-'.$p);
    }
    private static function messageSettings(string $p,array $c,array $config,array $groups,bool $open): string {
        $e=Support::e(...);$fields='';$enabled='<div class="field"><span class="field-label">Статус</span><label class="checkbox-inline"><input class="field-checkbox" type="checkbox" name="enabled" value="1"'.($c['enabled']?' checked':'').'> Подключение включено</label></div>';
        if($p==='email')$fields.=self::field('host','Сервер SMTP',$config['host']??'').self::field('port','Порт SMTP',$config['port']??465,'number','min="1" max="65535"').'<div class="field"><label class="field-label" for="message-security">Шифрование</label><select class="field-select" id="message-security" name="security"><option value="ssl"'.(($config['security']??'ssl')==='ssl'?' selected':'').'>SSL/TLS</option><option value="starttls"'.(($config['security']??'')==='starttls'?' selected':'').'>STARTTLS</option></select></div>'.self::field('username','Логин',$config['username']??'').self::field('from_email','Email отправителя',$config['from_email']??'','email').self::field('from_name','Имя отправителя',$config['from_name']??'').self::field('secret','Пароль SMTP','','password','autocomplete="new-password"');else $fields.=self::field('secret','Ключ бота MAX (токен)','','password','autocomplete="new-password"');$fields.=$enabled;
        $h='<dialog class="modal registry-ui-modal registry-ui-modal--reading ui-detail-card" id="message-settings" aria-labelledby="message-settings-title"'.($open?' data-open-on-load':'').'><form method="post" action="/integrations/action" class="registry-ui-modal-form">'.self::hidden($p,'save').'<input name="revision" type="hidden" value="'.$e($c['revision']).'"><div class="modal-head"><h2 class="modal-title" id="message-settings-title">Настройки · '.($p==='email'?'Электронная почта':'MAX').'</h2><button type="button" class="modal-close" data-dialog-close aria-label="Закрыть настройки">×</button></div><div class="modal-body registry-ui-modal-grid"><div class="form-grid-2">'.$fields.'</div><p class="registry-ui-modal-secondary">Для замены секрета введите новый; пустое поле оставит прежний.</p>';
        $destinationForm='';
        if($p==='email')$h.='<p class="registry-ui-modal-secondary">Для отправки используется SMTP. IMAP предназначен для чтения входящих писем.</p>';else {$h.='<h3 class="section-title">Группы MAX</h3><p class="registry-ui-modal-secondary">Добавьте бота в группу и укажите её числовой ID. Новую группу можно сохранить выключенной.</p><div class="registry-ui-modal-grid" data-integration-groups>';$i=0;foreach($groups as $g)$h.=self::group($i++,$g);$h.='</div><template id="integration-group-template">'.self::group('__INDEX__',[]).'</template><div class="inline-actions"><button type="button" class="btn btn-secondary" data-integration-group-add>Добавить группу</button></div>';
            [$destination,$destinationForm]=self::workingDestination(DB::pdo());
            $h.=$destination;
        }
        $h.='</div><div class="modal-foot"><div class="modal-foot-actions"><button type="button" class="btn btn-secondary" data-dialog-close>Отмена</button><button type="submit" class="btn btn-primary">Сохранить</button></div></div></form></dialog>';return $h.$destinationForm;
    }
    private static function workingDestination(\PDO $db): array
    {
        $e=Support::e(...);
        $choice=MaxWorkingDestination::selection($db);
        $settings=null;$work=null;
        try {
            $settings=MaxWorkingDestination::settings($db);
            if ($settings) $work=!empty($settings['via_relay'])?$settings:MaxWorkingDestination::admission($db,$settings);
        } catch (\RuntimeException) {
            // Unavailable admission leaves the TEST option and existing group controls usable.
        }
        $selectedWork=$choice['destination']===MaxWorkingDestination::WORK;
        $test=!empty($settings['via_relay'])?'ТЕСТОВАЯ':($settings['group_name']??'ТЕСТОВАЯ');
        $name=$work['group_name']??($selectedWork ? ($choice['group_name']??'Рабочая') : 'Рабочая');
        $field='<h3 class="section-title">Автоматические уведомления ERP</h3><div class="demo-row">'
            .'<label class="field"><span class="field-label">Группа для новых событий</span>'
            .'<select class="field-select" name="destination" form="max-working-destination-form"'.($settings?'':' disabled').'>'
            .'<option value="test"'.($selectedWork?'':' selected').'>'.$e($test).'</option>'
            .'<option value="work"'.($selectedWork?' selected':'').($work?'':' disabled').'>'
            .$e($name.($work?'':' · ждёт допуска')).'</option></select></label>'
            .'<button type="submit" class="btn btn-secondary" form="max-working-destination-form"'.($settings?'':' disabled').'>Применить выбор</button></div>';
        $form='<form method="post" action="/integrations/action" id="max-working-destination-form">'
            .self::hidden('max','select_working_destination').'<input type="hidden" name="destination_serial" value="'.$e($choice['serial']).'"></form>';
        return [$field,$form];
    }
    private static function group(int|string $i,array $g): string {$e=Support::e(...);$prefix='group_rows['.$i.']';return '<fieldset class="demo-fieldset" data-integration-group><legend class="field-label">'.$e($g['name']??'Новая группа').'</legend><div class="form-grid-2">'.self::field($prefix.'[name]','Название группы',$g['name']??'').self::field($prefix.'[chat_id]','Идентификатор группы',$g['chat_id']??'').'</div><div class="demo-row"><label class="checkbox-inline"><input type="checkbox" name="'.$prefix.'[enabled]" value="1"'.(($g['enabled']??false)?' checked':'').'> Отправка включена</label><button type="button" class="btn btn-secondary" data-integration-group-remove>Убрать группу</button></div><input type="hidden" name="'.$prefix.'[delete]" value="0" data-integration-group-delete></fieldset>';}
    private static function uiScript(): string {return <<<'JS'
document.querySelectorAll('dialog[data-open-on-load]').forEach(d=>d.showModal());
document.querySelector('[data-integration-group-add]')?.addEventListener('click',()=>{const root=document.querySelector('[data-integration-groups]');const index=root.children.length;const text=document.getElementById('integration-group-template').innerHTML.replaceAll('__INDEX__',String(index));root.insertAdjacentHTML('beforeend',text);});
document.addEventListener('click',event=>{const button=event.target.closest('[data-integration-group-remove]');if(button){const group=button.closest('[data-integration-group]');group.querySelector('[data-integration-group-delete]').value='1';group.hidden=true;}});
document.querySelector('[data-integration-files]')?.addEventListener('change',event=>{const list=document.querySelector('[data-integration-file-list]');list.replaceChildren();for(const file of event.target.files){const row=document.createElement('div');row.className='file-row';row.textContent=file.name+' · '+file.size+' Б';list.append(row);}});
JS;}
}
