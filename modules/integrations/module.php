<?php
declare(strict_types=1);
use Modules\Integrations\Controller;
$routes=[];
foreach(array_keys(\Modules\Integrations\Support::PROVIDERS) as $provider)$routes['/connections/'.$provider]=static fn()=>Controller::page($provider);
$routes['/email']=static fn()=>Controller::page('email');$routes['/max']=static fn()=>Controller::page('max');
foreach(array_keys(\Modules\Integrations\OnecOperations::CONNECTIONS) as $connection){
    $routes['/connections/'.$connection]=static fn()=>\Modules\Integrations\OnecOperationsController::page($connection);
    foreach(['check','preview','prepare','dispatch','reconcile','state','generate'] as $operation)
        $routes['/integrations/onec-actions/'.$connection.'/'.$operation]=static fn()=>\Modules\Integrations\OnecOperationsController::action($connection,$operation);
}
$routes['/integrations/action']=[Controller::class,'action'];$routes['/integrations/file']=[Controller::class,'file'];$routes['/integrations/export']=[Controller::class,'export'];
foreach(['preview','prepare','dispatch','reconcile','state'] as $operation)$routes['/integrations/onec/'.$operation]=[\Modules\Integrations\OnecController::class,$operation];
foreach(['max','email','top'] as $channel){$routes['/connection-templates-'.$channel]=static fn()=>\Modules\Integrations\TemplateView::page($channel);$routes['/connections/templates-'.$channel]=static fn()=>\Modules\Integrations\TemplateView::page($channel);}
$routes['/integrations/catalog-save']=[\Modules\Integrations\TemplateView::class,'save'];$routes['/integrations/ui/notification-templates.js']=[\Modules\Integrations\TemplateView::class,'asset'];
foreach(['onec','diadoc','mosenergo','mosvodokanal','pik','bank'] as $provider)$routes['/external-documents/'.$provider]=static fn()=>Controller::page($provider);
return ['routes'=>$routes,'nav'=>[]];
