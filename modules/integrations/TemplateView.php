<?php
declare(strict_types=1);
namespace Modules\Integrations;
use App\{Auth,DB,Http,View};

final class TemplateView
{
    public const CHANNELS=['max'=>'Шаблоны сообщений MAX','email'=>'Шаблоны сообщений Email','top'=>'Шаблоны верхних попапов'];
    public static function allowed(): bool {
        $actor=Auth::user();
        return $actor && in_array($actor['role'],['director','admin'],true) && Auth::can('messaging.write');
    }
    private static function access(string $channel): void {
        if(!isset(self::CHANNELS[$channel]))throw new \RuntimeException('Страница не найдена',404);
        if(!self::allowed())throw new \RuntimeException('Недостаточно прав',403);
    }
    public static function url(string $channel): string {return '/administration/templates-'.$channel;}
    public static function page(string $channel,?array $draft=null,string $error=''): string {
        self::access($channel);
        if($draft===null&&($_SERVER['REQUEST_METHOD']??'GET')!=='GET')throw new \RuntimeException('Требуется GET',405);
        $e=View::e(...);$db=DB::pdo();
        $helper=match($channel){'max'=>MaxTemplateAdmin::class,'email'=>EmailTemplateAdmin::class,'top'=>TopTemplateAdmin::class};
        $body=View::heading(self::CHANNELS[$channel],$channel==='top'?'Редактируйте заголовки и тексты уведомлений в верхней части экрана.':'Редактируйте тексты сообщений, которые система готовит по событиям.');
        $body.='<nav class="demo-row" aria-label="Шаблоны уведомлений">';
        foreach(self::CHANNELS as $key=>$label)$body.='<a class="btn '.($key===$channel?'btn-primary':'btn-secondary').'" href="'.self::url($key).'"'.($key===$channel?' aria-current="page"':'').'>'.$e($label).'</a>';
        $body.='</nav>';
        $flash=$_SESSION['template_admin_flash']??null;unset($_SESSION['template_admin_flash']);
        if(is_array($flash)&&($flash['channel']??'')===$channel)$body.='<p class="registry-ui-modal-text" role="status">Шаблон «'.$e($flash['name']).'»: '.(!empty($flash['unchanged'])?'изменений нет.':'сохранён.').' Версия '.$e($flash['version']).'.</p>';
        $query=trim((string)($_GET['q']??''));
        $q=$db->prepare('SELECT * FROM integration_template_catalog WHERE channel=? ORDER BY name,source_key');$q->execute([$channel]);$all=$q->fetchAll(\PDO::FETCH_ASSOC);
        $entries=array_filter($all,static fn($t)=>$query===''||mb_stripos($t['name'].' '.$t['trigger_text'].' '.$t['body_text'].' '.$t['subject'].' '.$t['title'],$query)!==false);
        $body.='<form method="get" class="toolbar-form"><label class="field"><span class="field-label">Поиск шаблона</span><input class="field-input" type="search" name="q" value="'.$e($query).'" placeholder="Название, событие или текст"></label><button class="btn btn-secondary">Найти</button>'.($query!==''?'<a class="btn btn-secondary" href="'.self::url($channel).'">Сбросить</a>':'').'</form><p class="registry-ui-secondary">Найдено: '.count($entries).' из '.count($all).'</p>';
        $body.='<section class="table-card registry-ui-card"><div class="table-scroll"><table class="table registry-ui-table registry-ui-data"><thead><tr><th scope="col">Шаблон</th><th scope="col">Когда используется</th><th scope="col">Действие</th></tr></thead><tbody>';
        foreach($entries as $t){
            $usage=method_exists($helper,'usage')?$helper::usage($t):null;
            $body.='<tr><td><span class="registry-ui-name">'.$e($t['name']).'</span><span class="registry-ui-secondary">Версия '.$e($t['version']).'</span></td><td><span class="registry-ui-modal-text">'.$e($t['trigger_text']?:'Условие задаётся событием системы').'</span>'.(is_array($usage)?'<span class="registry-ui-secondary">'.$e($usage['text']??'').'</span>':'').'</td><td><a class="btn btn-toolbar" href="'.self::url($channel).'?'.http_build_query(['q'=>$query,'edit'=>$t['source_key']]).'" aria-label="Изменить '.$e($t['name']).'">Изменить</a></td></tr>';
        }
        if(!$entries)$body.='<tr><td colspan="3">'.($all?'Шаблоны не найдены. Измените поисковый запрос.':'В этом разделе пока нет шаблонов. Каталог должен быть получен из подтверждённого источника.').'</td></tr>';
        $body.='</tbody></table></div></section>';
        $key=$draft['source_key']??($_GET['edit']??null);
        if($key!==null){
            $entry=null;foreach($all as $t)if($t['source_key']===$key)$entry=$t;
            if(!$entry)throw new \RuntimeException('Шаблон не найден',404);
            $currentVersion=(int)$entry['version'];
            if($draft){foreach(['title','subject','format'] as $name)if(isset($draft[$name]))$entry[$name]=$draft[$name];$entry['body_text']=$draft['body'];}
            $expected=$draft['expected_version']??$currentVersion;
            $usage=method_exists($helper,'usage')?$helper::usage($entry):null;
            $body.='<dialog class="modal registry-ui-modal registry-ui-modal--reading ui-detail-card" id="template-editor" aria-labelledby="template-editor-heading"><form method="post" action="/integrations/catalog-save" class="registry-ui-modal-form" id="template-editor-form"><input type="hidden" name="_csrf" value="'.$e(Http::csrf()).'"><input type="hidden" name="channel" value="'.$channel.'"><input type="hidden" name="source_key" value="'.$e($key).'"><input type="hidden" name="expected_version" value="'.$e($expected).'"><div class="modal-head"><h2 class="modal-title" id="template-editor-heading">'.$e($entry['name']).'</h2><button class="modal-close" type="button" data-template-close aria-label="Закрыть">×</button></div><div class="modal-body registry-ui-modal-grid">';
            if($error!=='')$body.='<p class="registry-ui-modal-text" role="alert">'.$e($error).'</p>';
            if(is_array($usage))$body.='<p class="registry-ui-modal-help">'.$e($usage['text']).'</p>';
            $body.='<p class="registry-ui-modal-help">'.$e($entry['trigger_text']).' · Версия '.$currentVersion.'</p>'.$helper::fields($entry).'<span class="registry-ui-modal-help" id="template-counter" aria-live="polite"></span>';
            $help=$helper::help($entry);$body.=$channel==='email'?$help:'<p class="registry-ui-modal-help">'.$e($help).'</p>';
            if($error!==''&&$expected!==$currentVersion)$body.='<a class="btn btn-secondary" href="'.self::url($channel).'?edit='.rawurlencode($key).'">Открыть актуальную версию</a>';
            $body.='<section class="registry-ui-modal-grid" aria-label="Предпросмотр"><h3 class="registry-ui-modal-summary">Предпросмотр на примере</h3><div class="registry-ui-modal-text" id="template-preview" aria-live="polite"></div><p class="registry-ui-modal-help" id="template-preview-error" role="alert" hidden></p>'.($channel==='top'?'<button class="btn btn-secondary" type="button" id="template-show-popup">Показать верхний попап</button>':'').'</section>';
            $body.='<details><summary>Подстановки — как вставить данные</summary><p class="registry-ui-modal-help">Выберите поле текста, темы или заголовка, затем нажмите нужную подстановку. Данные появятся при событии; пример не меняет шаблон.</p><div class="registry-ui-modal-grid">';
            foreach($helper::variables($entry) as $token=>$label)$body.='<button class="btn btn-secondary" type="button" data-template-token="'.$e($token).'">'.$e($label).' · {'.$e($token).'}</button>';
            $body.='</div></details>';
            $history=$db->prepare('SELECT version,title,subject,body_text,format,actor_label,changed_at FROM integration_template_history WHERE channel=? AND source_key=? ORDER BY version DESC LIMIT 10');$history->execute([$channel,$key]);
            $body.='<details><summary>История изменений</summary><div class="registry-ui-modal-grid">';$rows=$history->fetchAll(\PDO::FETCH_ASSOC);
            foreach($rows as $row)$body.='<details><summary>Версия '.$e($row['version']).' · '.$e($row['actor_label']?:'Исходная версия').' · '.$e($row['changed_at']).'</summary><p class="registry-ui-modal-text">'.$e($row['title']?:$row['subject']).'</p><div class="registry-ui-modal-text">'.nl2br($e($row['body_text'])).'</div></details>';
            $body.=($rows?'':'<p class="registry-ui-modal-help">Предыдущих редакций пока нет. При сохранении текущая версия останется в истории.</p>').'</div></details></div><div class="modal-foot"><div class="modal-foot-actions"><button class="btn btn-secondary" type="button" data-template-close>Отмена</button><button class="btn btn-primary" type="submit">Сохранить</button></div></div></form></dialog>';
            $config=['channel'=>$channel,'values'=>$helper::sampleValues($entry),'variables'=>$channel==='top'?TemplateCatalog::TOP_VARS:TemplateCatalog::messageVariables($channel,$key),'closeUrl'=>self::url($channel).($query!==''?'?'.http_build_query(['q'=>$query]):''),'tone'=>$entry['tone']??'success'];
            if($channel==='top')$config=array_replace($config,$helper::previewConfig($entry));
            $body.='<div id="template-admin-config" hidden data-config="'.$e(Support::json($config)).'"></div><script src="/integrations/ui/notification-templates.js"></script>';
        }
        // Inline scripts are blocked by the application's CSP.
        return View::page(self::CHANNELS[$channel],$body,'templates-'.$channel,['template-admin.js']);
    }
    public static function save(): never {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new \RuntimeException('Требуется POST',405);
        Http::checkCsrf();$channel=(string)($_POST['channel']??'');self::access($channel);
        $key=(string)($_POST['source_key']??'');$fields=match($channel){'max'=>['format','body'],'email'=>['subject','body'],'top'=>['title','body']};
        $input=[];foreach($fields as $name)$input[$name]=(string)($_POST[$name]??'');
        try{$r=(new TemplateCatalog(DB::pdo(),Config::store()))->save($channel,$key,(int)($_POST['expected_version']??0),$input,Auth::user()['name']);}
        catch(\InvalidArgumentException|\RuntimeException $error){
            $conflict=$error->getMessage()==='REVISION_CONFLICT';
            $message=$conflict?'Этот шаблон уже изменён другим пользователем. Ваш текст остался в форме. Скопируйте его и откройте актуальную версию перед повторным сохранением.':'Шаблон не сохранён. Проверьте обязательные поля, длину текста, подстановки и формат. Ваш текст остался в форме.';
            http_response_code($conflict?409:422);echo self::page($channel,$input+['source_key'=>$key,'expected_version'=>(int)($_POST['expected_version']??0)],$message);exit;
        }
        if(empty($r['unchanged']))\App\Audit::record('notification.template.saved',$channel,$key,null,['version'=>$r['version']]);
        $q=DB::pdo()->prepare('SELECT name FROM integration_template_catalog WHERE channel=? AND source_key=?');$q->execute([$channel,$key]);
        $_SESSION['template_admin_flash']=['channel'=>$channel,'name'=>$q->fetchColumn(),'version'=>$r['version'],'unchanged'=>!empty($r['unchanged'])];
        Http::redirect(self::url($channel));
    }
    public static function asset(): never {Auth::requirePermission('messaging.write');header('Content-Type: application/javascript; charset=utf-8');header('X-Content-Type-Options: nosniff');readfile(__DIR__.'/ui/notification-templates.js');exit;}
}
