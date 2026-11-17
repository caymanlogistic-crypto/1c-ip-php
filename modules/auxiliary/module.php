<?php
declare(strict_types=1);
use Modules\Auxiliary\Controller;
$routes=['/auxiliary'=>[Controller::class,'index'],'/auxiliary/premises'=>[Controller::class,'premises'],'/auxiliary/records'=>[Controller::class,'records'],'/auxiliary/analytics'=>[Controller::class,'analytics'],'/auxiliary/quality'=>[Controller::class,'quality']];
foreach(['dashboard','runs','comparison','history','settings']as $page)$routes['/auxiliary/market/'.$page]=static fn()=>Controller::market($page);
return ['routes'=>$routes,'nav'=>[]];
