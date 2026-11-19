<?php
declare(strict_types=1);
namespace App;
final class View {
    public static function e(mixed $v):string{return htmlspecialchars((string)($v??''),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');}
    public static function login(string $error=''):string {
        // Exact native login composition from S/templates/registration/login.html.
        $css=self::fontPreloads();foreach(['system/palette.css','system/css/erp-ui.css','system/css/registry-components.css','system/css/ui-controls.css','system/css/time-picker.css','system/css/ui-shell.css','system/css/registry-viewport.css','system/css/notification-icons.css','demo/demo_geometry.css','erp/login-brand.css']as $asset)$css.='<link rel="stylesheet" href="/design/'.$asset.'">';
        $name='ИП Яковлев';return '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="color-scheme" content="light"><title>Вход в систему · '.self::e($name).'</title>'.$css.'</head><body><main class="modal-overlay is-open login-screen" aria-label="Вход в систему"><dialog open class="modal registry-ui-modal registry-ui-modal--confirmation" aria-labelledby="login-title"><form method="post" class="registry-ui-modal-form"><input type="hidden" name="_csrf" value="'.self::e(Http::csrf()).'"><div class="modal-head"><h1 class="modal-title" id="login-title">'.self::e($name).'</h1></div><div class="modal-body registry-ui-modal-grid"><p class="registry-ui-modal-text">Войдите в свою учётную запись</p>'.$error.'<div class="field"><label class="field-label" for="id_username">Логин</label><input type="text" name="username" autofocus autocapitalize="none" autocomplete="username" maxlength="150" class="field-input" required id="id_username"></div><div class="field"><label class="field-label" for="id_password">Пароль</label><input type="password" name="password" autocomplete="current-password" class="field-input" required id="id_password"></div></div><div class="modal-foot"><button class="btn btn-primary" type="submit">Войти в систему</button></div></form></dialog></main></body></html>';
    }
    public static function page(string $title,string $body,string $active='',array $pageScripts=[],?\Modules\Rental\ObjectReadModel $objects=null):string {
        $extraScripts='';
        foreach($pageScripts as $script){
            if(!is_string($script)||!preg_match('~^[a-zA-Z0-9_-]+\.js$~D',$script))throw new \RuntimeException('Invalid local page script');
            $extraScripts.='<script defer src="/assets/'.self::e($script).'"></script>';
        }
        $e=self::e(...);try{$u=Auth::user();}catch(\Throwable){$u=null;}$nav='';
        $rentalAllowed=$u&&Auth::can('rental.read');$groups=$rentalAllowed?Menu::GROUPS:[];$projected=$rentalAllowed?Menu::counts($u,$objects):['pages'=>[],'folders'=>[]];
        $folder='<svg class="nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 4h4l3 3h7a2 2 0 0 1 2 2v9a2 2 0 0 1 -2 2H5a2 2 0 0 1 -2 -2V6a2 2 0 0 1 2 -2z"/></svg>';$folderIndex=0;
        foreach($groups as $name=>$items){$fid='menu-folder-'.$folderIndex++;$links='';foreach($items as $key=>$label){if($u&&!Menu::visible($key,$u))continue;$links.='<a class="nav-sub-item'.($active===$key?' is-active':'').'" href="/rental/'.$e($key).'" data-page="'.$e($key).'"'.($active===$key?' aria-current="page"':'').'><span class="nav-label">'.$e($label).'</span>'.Menu::badges($projected['pages'][$key]??[0,0,0,0],true,$key).'</a>';}
          $nav.=self::navigationFolder($fid,$name,$links,$folder,Menu::badges($projected['folders'][$fid]??[0,0,0,0]));
        }
        $connections='';$administration='';
        $connectionLabels=['onec'=>'1С','onec-yakovlev'=>'1С ИП Яковлев']+array_map(static fn($d)=>$d['title'],\Modules\Integrations\OnecOperations::CONNECTIONS)+['diadoc'=>'Диадок','mosenergo'=>'Мосэнергосбыт','mosvodokanal'=>'Мосводоканал','pik'=>'ПИК-Комфорт','bank'=>'Банковская выписка · 1С'];
        if($u&&Auth::can('integrations.read'))foreach($connectionLabels as $id=>$label){
            if(isset(\Modules\Integrations\OnecOperations::CONNECTIONS[$id])&&!Auth::can('rental.read'))continue;
            if(!empty($u['grants_configured'])&&PagePolicy::level('connections.'.$id,$u)<1)continue;
            $connections.=self::navigationLink('/connections/'.$id,$label);
        }
        foreach(['/email'=>'Email','/max'=>'MAX','/auxiliary'=>'Дополнительные разделы','/profile'=>'Профиль','/users'=>'Пользователи','/roles'=>'Роли','/permissions'=>'Права доступа','/rental/thresholds'=>'Пороги и календарь'] as $url=>$label){
            if(!$u)continue;
            $messaging=in_array($url,['/email','/max'],true);
            if(str_starts_with($url,'/rental/')&&!$rentalAllowed)continue;
            if(!empty($u['grants_configured'])&&$messaging&&PagePolicy::level('connections.'.substr($url,1),$u)<1)continue;
            if($url==='/auxiliary'&&!$rentalAllowed)continue;
            if($messaging&&!Auth::can('messaging.read'))continue;
            if($url==='/users'&&!Auth::can('identity.manage'))continue;
            if(in_array($url,['/roles','/permissions','/rental/thresholds'],true)&&!Auth::can('identity.permissions'))continue;
            if($messaging)$connections.=self::navigationLink($url,$label);
            else $administration.=self::navigationLink($url,$label);
        }
        if($u&&($u['role_id']??null)==='admin')$administration.=self::navigationLink('/settings/roles','Роли и доступы');
        if($u&&($u['role_id']??null)==='admin')$administration.=self::navigationLink('/administration/zero-point','Нулевая точка');
        if($u&&\Modules\Integrations\TemplateView::allowed())foreach(\Modules\Integrations\TemplateView::CHANNELS as $channel=>$label)$administration.=self::navigationLink(\Modules\Integrations\TemplateView::url($channel),$label);
        if($u&&\Modules\Integrations\CabinetSchedulePage::allowed()) {
            $connections.=self::navigationLink(\Modules\Integrations\CabinetSchedulePage::URL,\Modules\Integrations\CabinetSchedulePage::TITLE);
        }
        $nav.=self::navigationFolder('menu-connections','Подключения',$connections,$folder);
        $administration.=self::navigationLink('/design/demo/index.html','Витрина');
        if($u&&$u['role']==='admin')$nav.=self::navigationFolder('menu-administration','Администрирование',$administration,$folder);
        if(!$u)$nav='';
        $css=self::fontPreloads();foreach(['system/palette.css','system/css/erp-ui.css','system/css/registry-components.css','system/css/ui-controls.css','system/css/ui-shell.css','system/css/registry-viewport.css','system/css/notification-icons.css','demo/demo_geometry.css'] as $asset)$css.='<link rel="stylesheet" href="/design/'.$asset.'">';
        ob_start();require APP_ROOT.'/app/views/workspace.php';return ob_get_clean();
    }
    public static function workspaceBody(string $body):string {
        return '<section class="demo-page demo-stack">'.$body.'</section>';
    }
    public static function workspaceFragment(string $body):string {
        return '<main id="workspace">'.self::workspaceBody($body).'</main>';
    }
    private static function fontPreloads():string {
        $html='';foreach(['Regular','Medium','SemiBold','Bold'] as $weight)$html.='<link rel="preload" href="/design/system/fonts/IBMPlexSans-'.$weight.'.woff2" as="font" type="font/woff2" crossorigin>';
        return $html;
    }
    private static function navigationLink(string $url,string $label):string {
        $current=(parse_url($_SERVER['REQUEST_URI']??'',PHP_URL_PATH)===$url);
        return '<a class="nav-sub-item'.($current?' is-active':'').'" href="'.self::e($url).'"'.($current?' aria-current="page"':'').'><span class="nav-label">'.self::e($label).'</span></a>';
    }
    private static function navigationFolder(string $id,string $label,string $links,string $icon,string $badges=''):string {
        if($links==='')return '';
        return '<a href="#'.$id.'" role="button" class="nav-item is-parent" data-folder="'.$id.'" aria-expanded="false" aria-controls="'.$id.'">'.$icon.'<span class="nav-label">'.self::e($label).'</span>'.$badges.'<svg class="nav-chevron" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m9 6 6 6 -6 6"/></svg></a><div class="nav-sub" id="'.$id.'" inert>'.$links.'</div>';
    }
    public static function heading(string $title,string $description='',string $icon='document',string $actions=''):string {$svg='<svg class="page-head-tabler-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1-2-2v-14a2 2 0 0 1 2-2h7l5 5v11a2 2 0 0 1-2 2"/><path d="M9 9h1M9 13h6M9 17h6"/></svg>';if(in_array($icon,['user','users-group'],true))$svg=preg_replace('/class="icon /','aria-hidden="true" class="page-head-tabler-icon icon ',file_get_contents(APP_ROOT.'/public/design/system/icons/'.$icon.'.svg'),1);return '<div class="page-head registry-ui-head"><div class="page-head-left"><span class="rp-identity-icon">'.$svg.'</span><span class="rp-head-text"><h1 class="page-title">'.self::e($title).'</h1><span class="page-summary">'.self::e($description).'</span></span></div><div class="page-head-actions">'.$actions.'</div></div>';}
}
