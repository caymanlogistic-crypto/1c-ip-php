<?php
declare(strict_types=1);
namespace Modules\Integrations;
use App\{Auth,DB,Http,View,Audit};

final class Controller
{
    private static function message(string $p): bool {return in_array($p,['email','max'],true);}
    private static function read(string $p): void {Auth::requirePermission(self::message($p)?'messaging.read':'integrations.read');}
    private static function write(string $p,string $action): void {Auth::requirePermission(self::message($p)?($action==='send'||$action==='check'?'messaging.send':'messaging.write'):'integrations.write');}
    private static function field(string $name,string $label,mixed $value='',string $type='text',bool $required=false): string {return '<label class="field"><span class="field-label">'.Support::e($label).'</span><input class="input" name="'.Support::e($name).'" type="'.$type.'" value="'.Support::e($value).'"'.($required?' required':'').'></label>';}
    private static function form(string $p,string $action,string $inner,string $button='Сохранить'): string {return '<form method="post" action="/integrations/action" enctype="multipart/form-data" class="demo-stack"><input type="hidden" name="_csrf" value="'.Support::e(Http::csrf()).'"><input type="hidden" name="provider" value="'.$p.'"><input type="hidden" name="action" value="'.$action.'"><input type="hidden" name="request_key" value="'.Support::uuid().'">'.$inner.'<div class="modal-foot-actions"><button class="btn btn-primary" type="submit">'.$button.'</button></div></form>';}
    public static function page(string $provider): string {self::read($provider);return PageView::render($provider);}
    public static function status(string $s): string {return ['pending'=>'Прервано или ожидается подтверждение; повтора нет','uncertain'=>'Неопределённо; повтора нет','accepted'=>'Принято провайдером','success'=>'Выполнено','failed'=>'Ошибка','awaiting_code'=>'Ожидается код'][$s]??$s;}
    public static function resultCode(string $code): string {return Support::e([
        'CHECKED_NO_SEND'=>'Подключение проверено. Сообщение не отправлялось.',
        'AUTH_METADATA_CHECKED'=>'Вход и сведения подключения подтверждены. Документы и файлы не проверялись.',
        'BOUNDED_METADATA_CHECKED'=>'Проверены сведения выборки документов. Полнота списка и исходные файлы не подтверждены; импорт не выполнялся.',
        'SHARED_ONEC_AUTH_REQUIRED'=>'Для банковской выписки требуется подтверждённый вход в 1С.',
        'SHARED_ONEC_AUTH_CHECKED_BANK_DATA_NOT_READ'=>'Используется подтверждённый вход в 1С. Банковские операции не запрашивались.',
        'SMTP_ACCEPTED_NOT_INBOX'=>'SMTP-сервер принял письмо. Доставка во входящие не подтверждена.',
        'MAX_ACCEPTED'=>'MAX подтвердил отправку в выбранную группу.',
        'EXTERNAL_ACCESS_NOT_AUTHORIZED'=>'Подключение пока недоступно. Обращение к источнику не выполнялось.',
        'SMTP_REJECTED'=>'SMTP отклонил отправителя, получателя или письмо.',
        'SMTP_CHECK_OR_AUTH_FAILED'=>'Проверьте сервер, порт, шифрование и доступ SMTP.',
        'SMTP_ACCEPTANCE_UNKNOWN'=>'Нет подтверждения приёма письма. Автоматического повтора нет.',
        'MAX_ACCEPTANCE_UNKNOWN'=>'Нет подтверждения отправки MAX. Автоматического повтора нет.',
        'MAX_REJECTED'=>'MAX отклонил запрос. Проверьте бота и права группы.',
        'CHECK_FAILED'=>'Подключение не подтверждено. Проверьте настройки и доступ.',
        'TRANSPORT_RESULT_UNKNOWN'=>'Результат не подтверждён. Автоматического повтора нет.',
        'TARGET_NOT_APPROVED'=>'Получатель не включён в разрешённый список.',
        'IMPORTED'=>'Полученные документы сохранены.', 'ORIGINAL_BYTES_VERIFIED'=>'Файл получен; целостность и источник подтверждены.',
        'AUTH_REQUIRED'=>'Требуется вход в подключение.', 'PUSH_REQUIRED'=>'Подтвердите вход в приложении провайдера.',
        'OTP_REQUIRED'=>'Требуется код подтверждения.', 'OTP_SENT'=>'Код подтверждения запрошен.',
        'OTP_EXPIRED_RELOGIN_REQUIRED'=>'Код истёк. Начните вход заново.', 'OTP_REQUEST_NOT_REPLAYABLE'=>'Повтор запроса кода заблокирован; сначала сверьте результат.',
        'AUTHENTICATED'=>'Вход подтверждён.', 'CONNECTION_DISABLED'=>'Подключение выключено.',
        'ONEC_CONNECTION_DISABLED'=>'Сначала включите подключение 1С.',
        'FILE_MAPPING_NOT_CONFIRMED'=>'Источник не подтвердил способ получения печатной формы.',
        'FILE_RESULT_UNKNOWN_NO_REPLAY'=>'Получение файла не подтверждено. Автоматического повтора нет.',
        'FILE_BINDING_FAILED'=>'Файл не соответствует выбранному документу.', 'FILE_CHECKSUM_FAILED'=>'Целостность файла не подтверждена.',
        'FILE_FORMAT_CHANGED'=>'Источник вернул файл другого формата.',
        'SOURCE_SCHEMA_CHANGED'=>'Формат данных источника изменился.', 'SOURCE_TOTAL_CHANGED'=>'Источник не подтвердил общий объём данных.',
        'INCOMPLETE_SOURCE_COUNT'=>'Получены не все страницы источника.', 'REPEATED_CURSOR'=>'Источник повторил страницу; получение остановлено.',
        'PROVIDER_OPERATION_FAILED'=>'Не удалось подтвердить операцию источника.',
        'PROCESS_INTERRUPTED_NO_REPLAY'=>'Операция прервана; результат неизвестен, повтор не выполняется.',
    ][$code]??($code===''?'':'Результат требует сверки.'));}
    public static function documents(string $p): string {return RegistryView::render($p);}
    public static function action(): never {
        if(($_SERVER['REQUEST_METHOD']??'GET')!=='POST')throw new \RuntimeException('Требуется POST',405);Http::checkCsrf();$p=Support::provider((string)($_POST['provider']??''));$action=(string)($_POST['action']??'');self::write($p,$action);$actor=(int)Auth::user()['id'];$db=DB::pdo();$result='';$key=(string)($_POST['request_key']??'');
        try{
            if($action==='save'){$old=Config::get($db,$p);$expected=(int)($_POST['revision']??-1);$config=[];$secret=Config::secrets($p);if($p==='email'){foreach(['host','username','from_email','from_name','security'] as $k)$config[$k]=trim((string)($_POST[$k]??''));$config['port']=(int)($_POST['port']??0);if(!in_array($config['security'],['ssl','starttls'],true)||$config['port']<1||$config['port']>65535||!preg_match('/^[a-z0-9.-]{1,253}$/iD',$config['host'])||!filter_var($config['from_email'],FILTER_VALIDATE_EMAIL))throw new \InvalidArgumentException('Проверьте SMTP, порт, TLS и адрес отправителя.');}elseif(!self::message($p)){foreach(['label','access_reference','note'] as $k)$config[$k]=trim((string)($_POST[$k]??''));Config::reference($config['access_reference']);$config['timeout_seconds']=(int)($_POST['timeout_seconds']??300);if($config['timeout_seconds']<30||$config['timeout_seconds']>900||strlen($config['label'])>200||strlen($config['note'])>4000)throw new \InvalidArgumentException('Неверные настройки подключения.');foreach(['username','password','secret','box_id'] as $forbidden)if(isset($_POST[$forbidden]))throw new \InvalidArgumentException('Секреты POD не принимаются веб-формой.');}
                foreach(self::message($p)?['secret']:[] as $k){$value=(string)($_POST[$k]??'');if($value!==''){if(strlen($value)>8192||preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Неверный секрет подключения.');$secret[$k]=$value;if($k==='username'&&$p!=='email')unset($secret['session']);}}
                $groups=[];if($p==='max'){$rows=$_POST['group_rows']??[];if(!is_array($rows)||count($rows)>100)throw new \InvalidArgumentException('Не более 100 групп.');foreach($rows as $row){if(!is_array($row))throw new \InvalidArgumentException('Неверная группа.');$name=trim((string)($row['name']??''));$chat=trim((string)($row['chat_id']??''));if($name===''&&$chat==='')continue;$chat=Support::chatId($chat);if($name===''||mb_strlen($name)>120||isset($groups[$chat]))throw new \InvalidArgumentException('Проверьте названия и уникальные идентификаторы групп.');$groups[$chat]=['name'=>$name,'enabled'=>isset($row['enabled'])&&($row['delete']??'0')!=='1'];}}

                if((int)$old['revision']!==$expected)throw new \RuntimeException('REVISION_CONFLICT');Config::save($db,$p,$config,$expected,isset($_POST['enabled']));if(self::message($p)&&$secret)Config::saveSecrets($p,$secret);
                if($p==='max'){DB::transaction(function(\PDO $db)use($groups){$db->exec("UPDATE messaging_groups SET enabled=0 WHERE provider='max'");foreach($groups as $chat=>$row)$db->prepare("INSERT INTO messaging_groups(id,provider,chat_id,name,enabled) VALUES(?,'max',?,?,?) ON DUPLICATE KEY UPDATE name=VALUES(name),enabled=VALUES(enabled)")->execute([Support::uuid(),$chat,$row['name'],(int)$row['enabled']]);});}Audit::record('integration.settings',$p,$p,['revision'=>$expected],['revision'=>$expected+1]);$result='Настройки сохранены. Живая проверка ещё не выполнена.';
            }
            elseif($action==='resume'){$run=(string)($_POST['run_id']??'');$q=$db->prepare('SELECT id FROM integration_runs WHERE id=? AND provider=?');$q->execute([$run,$p]);if(!$q->fetchColumn())throw new \RuntimeException('Получение не найдено.');$r=(new ProviderService($db,Config::store()))->resume($run,$actor);$result=self::status($r['status']).' · '.self::resultCode($r['result_code']??'');Audit::record('integration.resume',$p,$run);}
            elseif($action==='fetch_file'){$document=(string)($_POST['document_id']??'');$q=$db->prepare('SELECT id FROM external_documents WHERE id=? AND provider=?');$q->execute([$document,$p]);if(!$q->fetchColumn())throw new \RuntimeException('Документ не найден.');$r=(new FileService($db,Config::store()))->fetch($document,(string)($_POST['kind']??''),$actor,$key);$result=self::status($r['status']).' · '.self::resultCode($r['result_code']??'');Audit::record('integration.file.fetch',$p,$r['id']);}
            elseif($action==='import'){$f=$_FILES['snapshot']??null;if(!$f||$f['error']!==UPLOAD_ERR_OK||!is_uploaded_file($f['tmp_name'])||$f['size']>67108864)throw new \InvalidArgumentException('Выберите JSON-снимок до 64 МБ.');$r=(new Registry($db,Config::store()))->import($p,(string)file_get_contents($f['tmp_name']),$actor,$key);Audit::record('integration.import',$p,$r['id']);$result='Импортировано: '.$r['coverage']['records_imported'].'. Полнота: '.($r['coverage']['complete']?'подтверждена':'не подтверждена').'.';}
            elseif($action==='reconcile'&&self::message($p)){$id=(string)($_POST['attempt_id']??'');$q=$db->prepare('SELECT id FROM messaging_attempts WHERE id=? AND provider=?');$q->execute([$id,$p]);if(!$q->fetchColumn())throw new \RuntimeException('Попытка не найдена.');(new Messaging($db,Config::store()))->recoverPending($id);Audit::record('messaging.reconcile',$p,$id);$result='Неопределённость зафиксирована. Повтор не выполнялся.';}
            elseif(self::message($p)&&in_array($action,['check','send'],true)){$targets=$p==='email'?preg_split('/[,;\s]+/',trim((string)($_POST['targets']??'')),-1,PREG_SPLIT_NO_EMPTY):($_POST['targets']??[]);if(!is_array($targets))throw new \InvalidArgumentException('Неверные получатели.');$attachments=[];$files=$_FILES['attachments']??null;if($files){foreach($files['error'] as $i=>$error){if($error===UPLOAD_ERR_NO_FILE)continue;if($error!==UPLOAD_ERR_OK||!is_uploaded_file($files['tmp_name'][$i]))throw new \InvalidArgumentException('Не удалось прочитать вложение.');$attachments[]=['name'=>$files['name'][$i],'bytes'=>(string)file_get_contents($files['tmp_name'][$i])];}}$attempts=(new Messaging($db,Config::store()))->dispatch($p,$actor,$key,$action,$targets,(string)($_POST['text']??''),(string)($_POST['subject']??''),$attachments);$result=implode('; ',array_map(static fn($a)=>self::status($a['status']).' · '.self::resultCode($a['result_code']??''),$attempts));Audit::record('messaging.'.$action,$p,$key,null,['attempts'=>array_column($attempts,'id')]);}
            elseif(in_array($action,['check','sync','login','send_code','confirm'],true)){$r=(new ProviderService($db,Config::store()))->execute($p,$action,$actor,$key,['from'=>(string)($_POST['from']??'2026-10-01'),'to'=>(string)($_POST['to']??date('Y-m-d')),'code'=>(string)($_POST['code']??'')]);$result=self::status($r['status']).' · '.self::resultCode($r['result_code']??'');Audit::record('integration.'.$action,$p,$r['id']);}
            else throw new \InvalidArgumentException('Неизвестное действие.');
        }catch(\PDOException $e){$result='Операция не выполнена: конфликт данных или ограничение базы.';}catch(\Throwable $e){$safe=['REVISION_CONFLICT'=>'Настройки уже изменены. Обновите страницу.','IDEMPOTENCY_CONFLICT'=>'Запрос уже использован с другими данными.','EXTERNAL_ACCESS_NOT_AUTHORIZED'=>'Внешний доступ не разрешён. Отправка не выполнялась.','CONNECTION_DISABLED'=>'Сначала сохраните и включите подключение.','ATTACHMENT_LIMIT'=>'Вложения: только Email, не более 10 файлов и 10 МБ.','GROUP_DISABLED_OR_UNKNOWN'=>'Группа выключена или не найдена.'];$result='Операция не выполнена. '.($safe[$e->getMessage()]??(in_array($e->getMessage(),['INVALID_EXACT_DECIMAL','SECRET_IN_BUSINESS_PAYLOAD','PROVIDER_BINDING_FAILED','CONFLICTING_SOURCE_ID','DELETED_OR_UNKNOWN_INVOICE','INVALID_DOCUMENT_DATE','ACCOUNT_BINDING_FAILED','INVALID_TARGET','MESSAGE_TARGETS_REQUIRED','SPOOL_HMAC_FAILED'],true)?$e->getMessage():($e instanceof \InvalidArgumentException?'Проверьте введённые данные.':'Операция не подтверждена. Сверьте результат в истории перед следующим действием.')));}
        Http::redirect('/connections/'.$p.'?tab='.(in_array($action,['save','login','send_code','confirm'],true)?'settings':($action==='template'?'templates':'history')).'&result='.rawurlencode($result));
    }
    public static function file(): never {$p=Support::provider((string)($_GET['provider']??''));self::read($p);if(isset($_GET['capture']))CaptureFiles::download(DB::pdo(),$p,(string)$_GET['capture'],(string)($_GET['id']??''));$q=DB::pdo()->prepare('SELECT f.* FROM external_files f JOIN external_documents d ON d.id=f.document_id WHERE f.id=? AND d.provider=?');$q->execute([(string)($_GET['id']??''),$p]);$f=$q->fetch(\PDO::FETCH_ASSOC);if(!$f)throw new \RuntimeException('Файл не найден',404);$bytes=Config::store()->read($f['private_path'],$f['sha256']);header('X-Content-Type-Options: nosniff');header('Content-Type: '.$f['mime']);header("Content-Disposition: attachment; filename*=UTF-8''".rawurlencode($f['filename']));header('Content-Length: '.strlen($bytes));echo $bytes;exit;}
    public static function export(): never
    {
        $provider=Support::provider((string)($_GET['provider']??''));
        self::read($provider);
        if(self::message($provider))throw new \RuntimeException('Неверный источник',400);
        $type=(string)($_GET['type']??'json');
        if(!in_array($type,['html','zip','csv','json'],true))throw new \RuntimeException('Формат выгрузки не найден',404);
        header('X-Content-Type-Options: nosniff');
        $store=Config::store();
        if($type==='html'||$type==='zip'){
            $export=new ArchiveExport(DB::pdo(),$store);
            if($type==='html'){
                $selection=$export->selection($provider);
                header('Content-Type: text/html; charset=utf-8');
                header('Content-Disposition: attachment; filename="'.$provider.'-report.html"');
                echo ArchiveExport::html($selection['report']);
                exit;
            }
            $archive=$export->build($provider);
            try{
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="'.$provider.'.zip"');
                header('Content-Length: '.$archive['bytes']);
                header('X-Archive-SHA256: '.$archive['sha256']);
                readfile($archive['path']);
            }finally{if(is_file($archive['path']))unlink($archive['path']);}
            exit;
        }
        $query=DB::pdo()->prepare('SELECT * FROM external_documents WHERE provider=? ORDER BY document_date DESC,source_id');
        $query->execute([$provider]);
        $documents=$query->fetchAll(\PDO::FETCH_ASSOC);
        if($type==='csv'){
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="'.$provider.'.csv"');
            $stream=fopen('php://output','wb');
            fwrite($stream,"\xEF\xBB\xBF");
            fputcsv($stream,['source_id','date','number','title','account','amount','status'],';',enclosure:'"',escape:'');
            foreach($documents as$document){
                $row=[$document['source_id'],$document['document_date'],$document['number'],$document['title'],$document['account_id'],$document['amount'],$document['status']];
                $row=array_map(static fn($value)=>preg_match('/^[=+\-@\t\r]/',(string)$value)?"'".$value:$value,$row);
                fputcsv($stream,$row,';',enclosure:'"',escape:'');
            }
            fclose($stream);
            exit;
        }
        $records=[];
        foreach($documents as$document)$records[]=Support::decode($store->read($document['raw_path'],$document['raw_sha256']));
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="'.$provider.'.json"');
        echo Support::json(['provider'=>$provider,'records'=>$records,'coverage'=>['complete'=>false,'scope'=>'exported_selection'],'exported_at'=>date(DATE_ATOM)]);
        exit;
    }
}