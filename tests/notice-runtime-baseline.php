<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Rental\WorkflowNotices as N;
$default=['epoch'=>N::EPOCH,'event_floor'=>N::BASELINE];
if(N::baselineFor(N::EPOCH,null)!==$default)throw new RuntimeException('Legacy baseline changed');
$new='restored-current-epoch';$fact=['epoch'=>$new,'event_floor'=>1090];
if(N::baselineFor($new,$fact)!==$fact)throw new RuntimeException('Known restore boundary rejected');
foreach([null,['epoch'=>'other','event_floor'=>1090],['epoch'=>$new,'event_floor'=>-1],['epoch'=>$new,'event_floor'=>'1090']] as $bad)
 if(N::baselineFor($new,$bad)!==$default)throw new RuntimeException('Unknown epoch must remain closed');
echo "PASS explicit restored epoch/floor; legacy baseline preserved; missing/mismatched/invalid boundary closed\n";
