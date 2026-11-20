<?php
declare(strict_types=1);

define('APP_ROOT',sys_get_temp_dir().'/planex-design-test-'.bin2hex(random_bytes(8)));
require dirname(__DIR__).'/app/InterfaceDesign.php';
require dirname(__DIR__).'/app/InterfaceDesignStore.php';

use App\{InterfaceDesign,InterfaceDesignStore};

function verify(bool $result, string $message): void
{
    if (!$result) throw new RuntimeException($message);
}

function rejects(int $code, callable $operation): void
{
    try {
        $operation();
    } catch (RuntimeException $e) {
        verify($e->getCode()===$code,$e->getMessage());
        return;
    }
    throw new RuntimeException('Invalid operation accepted.');
}

$id='rental.objects';
$defaults=InterfaceDesign::defaults($id);
$draft=$defaults;
$draft['columns'][0]['label']='Помещение';
$draft['columns'][0]['width']=220;
$draft['columns']=array_reverse($draft['columns']);
try {
    verify(InterfaceDesignStore::read($id)['revision']===0,'Initial revision');
    rejects(409,fn()=>InterfaceDesignStore::change($id,0,'apply'));
    $state=InterfaceDesignStore::change($id,0,'draft',$draft);
    verify($state['applied']===null && $state['draft']==$draft,'Draft leaked into applied layout');
    rejects(409,fn()=>InterfaceDesignStore::change($id,0,'apply'));
    $state=InterfaceDesignStore::change($id,1,'apply');
    verify($state['applied']==$draft && $state['previous']===$defaults && $state['draft']===null,'Apply state');
    verify(InterfaceDesignStore::read('rental.invoice-list')['applied']===null,'Layouts share storage keys');
    $state=InterfaceDesignStore::change($id,2,'restore');
    verify($state['applied']===$defaults && $state['revision']===3,'Rollback state');
    $invalid=$draft;
    $invalid['columns'][1]=$invalid['columns'][0];
    rejects(422,fn()=>InterfaceDesignStore::change($id,3,'draft',$invalid));
    $dialog=InterfaceDesign::defaults('rental.new-contract');
    $dialog['actions'][0]['width']=481;
    rejects(422,fn()=>InterfaceDesignStore::change('rental.new-contract',0,'draft',$dialog));
    verify(InterfaceDesignStore::read($id)===$state,'Invalid operation changed stored state');
    echo "PASS: isolated layouts, draft, apply, rollback, stale version and invalid keys/sizes.\n";
} finally {
    $directory=APP_ROOT.'/storage/interface-design';
    foreach (glob($directory.'/*') as $file) unlink($file);
    if (is_dir($directory)) {
        rmdir($directory);
        rmdir(dirname($directory));
        rmdir(APP_ROOT);
    }
}
