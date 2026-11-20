<?php
declare(strict_types=1);
namespace App;

use RuntimeException;

final class InterfaceDesignPage
{
    public static function handle(): string
    {
        if (!InterfaceDesign::canEdit()) throw new RuntimeException('Конструктор доступен администратору.',403);
        $method = $_SERVER['REQUEST_METHOD'];
        header('Cache-Control: private, no-store');
        if ($method === 'POST') {
            if ((int)($_SERVER['CONTENT_LENGTH']??0)>16384) throw new RuntimeException('Компоновка слишком велика.',413);
            $revision = filter_var($_POST['revision']??null,FILTER_VALIDATE_INT);
            if ($revision===false || $revision<0) throw new RuntimeException('Укажите версию компоновки.',400);
            try {
                $layout = json_decode((string)($_POST['layout']??'null'),true,16,JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                throw new RuntimeException('Некорректная компоновка.',422);
            }
            Http::json(InterfaceDesignStore::change((string)($_POST['id']??''),$revision,(string)($_POST['action']??''),$layout));
        }
        if ($method!=='GET') throw new RuntimeException('Метод не поддерживается.',405);
        if (isset($_GET['backup'])) {
            $screens = [];
            foreach (InterfaceDesign::SCREENS as $id=>$_) $screens[$id]=InterfaceDesignStore::read($id);
            header('Content-Disposition: attachment; filename="planex-interface-design.json"');
            Http::json(['schema'=>1,'screens'=>$screens]);
        }
        $html = View::heading('Конструктор интерфейса','Откройте рабочий экран, чтобы изменить его компоновку.',
            actions:'<a class="btn btn-secondary" href="?backup=1">Скачать резервную копию</a>');
        $html .= '<section class="table-card registry-ui-card"><div class="table-scroll"><table class="table registry-ui-table"><thead><tr><th>Экран</th><th>Версия</th><th>Черновик</th></tr></thead><tbody>';
        foreach (InterfaceDesign::SCREENS as $id=>[$label,$url]) {
            $state=InterfaceDesignStore::read($id);
            $html.='<tr><td><a href="'.View::e($url).'">'.View::e($label).'</a></td><td>'.$state['revision'].'</td><td>'.($state['draft']!==null?'Сохранён':'—').'</td></tr>';
        }
        return View::page('Конструктор интерфейса',$html.'</tbody></table></div></section>');
    }
}
