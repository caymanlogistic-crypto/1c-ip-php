<?php
declare(strict_types=1);

require dirname(__DIR__).'/app/bootstrap.php';

use Modules\Rental\{UpdEvidence,UpdRegister};

function updExpect(mixed $actual,mixed $expected,string $case): void
{
    if ($actual!==$expected) throw new RuntimeException($case);
    echo "PASS $case\n";
}

$db=new class extends PDO {
    public function __construct() {}
};
$date=new ReflectionMethod(UpdRegister::class,'datePlan');
foreach (['rent','regular','extra'] as $kind) {
    $result=$date->invoke(null,$db,[],'2026-10',$kind,'company');
    updExpect($result['date'],'2026-10-31','month end remains Saturday for '.$kind);
}
foreach ([['rent','entrepreneur'],['pass','person'],['regular','person'],['rent',null]] as [$kind,$type]) {
    $result=$date->invoke(null,$db,[],'2026-10',$kind,$type);
    updExpect(isset($result['date']),false,'no automatic date for '.$kind.'/'.($type ?? 'unknown'));
}
updExpect(UpdEvidence::money('50000.00000000'),'50000.00','exact amount from stored archive');
updExpect(UpdEvidence::money('50000.001'),null,'archive cents are never rounded silently');
