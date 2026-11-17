<?php
declare(strict_types=1);
namespace App;

final class Showcase {
    public static function page(string $path):never {
        $name=basename($path);
        if(!in_array($name,['index.html','guide.html','charts.html','layout-rules.html'],true))throw new \RuntimeException('Страница не найдена',404);
        if($_SERVER['REQUEST_METHOD']!=='GET'&&$_SERVER['REQUEST_METHOD']!=='HEAD')throw new \RuntimeException('Метод не поддерживается',405);
        // Existing app session is checked in index.php; demo pages make no network calls.
        header("Content-Security-Policy: default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'none'; base-uri 'none'; form-action 'none'; object-src 'none'; frame-ancestors 'none'");
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: private, no-store');
        if($_SERVER['REQUEST_METHOD']!=='HEAD')readfile(APP_ROOT.'/public/design/demo/'.$name);
        exit;
    }
}
