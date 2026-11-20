<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';

use App\{Auth,DB,Menu};
use Modules\Rental\{ApprovalRegistry,DirectoryDetails,PartyLegalReview,Service};

set_exception_handler(static function(Throwable $error): void {
    fwrite(STDERR,$error->getMessage().PHP_EOL);
    exit(1);
});

function check(bool $ok,string $message): void {
    if (!$ok) throw new RuntimeException($message);
}

$db=DB::pdo();
$database=$db->query('SELECT DATABASE()')->fetchColumn();
$proof=json_decode(file_get_contents(getenv('APP_TEST_COPY_PROOF')?:APP_ROOT.'/.local/copy-verification.json'),true,32,JSON_THROW_ON_ERROR);
check(PHP_SAPI==='cli' && preg_match('/^ipphp_task_[a-z0-9_]+$/D',$database)
    && $proof['database']===$database && $proof['isolated_test_data']===true
    && realpath($proof['app_root'])===realpath(APP_ROOT)
    && !getenv('IPPHP_ALLOW_EXTERNAL') && !getenv('IPPHP_RENTAL_MAX_POSTCOMMIT'),'Verified isolated test copy required.');
$db->beginTransaction();
try {
    $makeUser=$db->prepare('INSERT INTO users(username,name,password_hash,role) VALUES(?,?,?,?)');
    $ids=[];
    foreach (['manager','lawyer'] as $role) {
        $makeUser->execute(['review-count-'.bin2hex(random_bytes(8)).'@example.test','Local QA '.$role,
            password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT),$role]);
        $ids[$role]=(int)$db->lastInsertId();
    }
    $act=static function(string $role)use($ids): void {
        $_SESSION=['user_id'=>$ids[$role],'auth_version'=>1];
    };
    $act('manager');
    $saved=Service::command('party.save',['name'=>'Учебная карточка для счётчика','party_type'=>'person','command_key'=>bin2hex(random_bytes(16))]);
    $id=(int)$saved['id'];
    DirectoryDetails::store($db,'party_legal_review',$id,['status'=>'pending']);
    $url='/rental/party?id='.$id;
    $view=static function(string $role,int $mine,string $flag,string $filter,bool $visible)use($act,$url): void {
        $act($role);
        $_GET=['flag'=>$flag,'filter'=>$filter];
        $previous=libxml_use_internal_errors(true);
        $html=new DOMDocument();
        $html->loadHTML('<?xml encoding="UTF-8">'.ApprovalRegistry::page());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath=new DOMXPath($html);
        check((int)$xpath->evaluate('string((//button[@name="flag" and @value="mine"]/b)[1])')===$mine,
            'Unexpected tab count for '.$role.' with '.$filter);
        $rows=$xpath->query('//tr[@data-rental-url="'.$url.'"]');
        check($rows->length===($visible?1:0),'Stage/tab and visible row differ for '.$role);
    };
    $badge=static function(string $role,int $expected)use($act): void {
        $act($role);
        check(Menu::counts(Auth::user())['pages']['approvals']===[0,$expected,0,0],'Unexpected work count for '.$role);
    };

    $badge('lawyer',1);
    $badge('manager',0);
    $view('lawyer',1,'mine','party:pending',true);
    $view('lawyer',0,'mine','legal',false);
    $view('lawyer',1,'returned','all',false);
    $view('manager',0,'mine','all',false);
    $act('lawyer');
    $party=Service::get($db,'parties',$id);
    Service::command(PartyLegalReview::RETURN,['id'=>$id,'version'=>$party['version'],
        'reason'=>'Учебная причина возврата','command_key'=>bin2hex(random_bytes(16))]);
    $badge('lawyer',0);
    $badge('manager',1);
    $view('manager',1,'mine','party:returned',true);
    $view('manager',1,'returned','all',true);
    $view('manager',0,'mine','party:pending',false);
    $view('manager',0,'mine','returned',false);
    echo "PASS: one legal task, then one manager task; matching registry, tabs, stage filters and menu counts.\n";
} finally {
    $db->rollBack();
}
