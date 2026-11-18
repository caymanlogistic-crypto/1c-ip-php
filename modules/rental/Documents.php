<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{Auth, DB, Http, Audit};
use RuntimeException;
final class Documents {
    private static function e(mixed $v):string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_XML1, 'UTF-8');
    }
    private static function directory():string {
        $dir = APP_ROOT
        . '/.local/documents';
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir))throw new RuntimeException('Не удалось создать хранилище документов.');
        return $dir;
    }
    public static function buildDocx(array $values, string $type, string $target, ?string $approvedSource=null):void {
        if (!in_array($type, [
            'office',
            'parking',
            'mixed',
            'addendum'
        ], true))throw new RuntimeException('Неизвестный шаблон Word.');
        $source = __DIR__
        . '/templates/'
        . $type
        . '.docx';
        DocxTemplate::build($approvedSource ?? $source,$values,$type,$target);
    }
    public static function renderPdf(string $html):string {
        if (!class_exists(\Dompdf\Dompdf::class))throw new RuntimeException('PDF renderer недоступен.');
        $temp = APP_ROOT
        . '/runtime/pdf-tmp';
        $cache = APP_ROOT
        . '/runtime/pdf-font-cache';
        foreach ([
            $temp,
            $cache
        ] as $dir)if (!is_dir($dir))mkdir($dir, 0700, true);
        $dom = new \Dompdf\Dompdf([
            'isRemoteEnabled' => false,
            'defaultFont' => 'DejaVu Sans',
            'tempDir' => $temp,
            'fontCache' => $cache,
            'chroot' => APP_ROOT
        ]);
        $dom->loadHtml($html, 'UTF-8');
        $dom->setPaper('A4');
        $dom->render();
        return $dom->output();
    }
    public static function validateFile(string $path, string $extension):void {
        if ($extension === 'pdf') {
            $size = filesize($path);
            $head = file_get_contents($path, false, null, 0, 8);
            $tail = file_get_contents($path, false, null, max(0, $size-4096));
            if (!str_starts_with($head, '%PDF-') || !preg_match('/startxref\s+(\d+)\s+%%EOF/s', $tail, $m) || (int)$m[1] <= 0 || (int)$m[1] >= $size)throw new RuntimeException('PDF повреждён или не содержит таблицу объектов.');
        }
        elseif($extension === 'docx') {
            $zip = new \ZipArchive();
            if ($zip->open($path) !== true)throw new RuntimeException('Word DOCX повреждён.');
            $xml = $zip->getFromName('word/document.xml');
            $types = $zip->getFromName('[Content_Types].xml');
            $zip->close();
            $dom = new \DOMDocument();
            $old = libxml_use_internal_errors(true);
            $valid = $xml !== false && $types !== false && $dom->loadXML($xml, LIBXML_NONET);
            libxml_clear_errors();
            libxml_use_internal_errors($old);
            if (!$valid)throw new RuntimeException('Word DOCX не содержит корректный документ.');
        }
        elseif($extension === 'doc' && (!str_starts_with(file_get_contents($path, false, null, 0, 8), "\xD0\xCF\x11\xE0") || filesize($path)<512))throw new RuntimeException('Word DOC повреждён.');
    }
    /** Diagnostics are scoped to this upload request; they never change its result. */
    private static function uploadFailure(array $state, \Throwable $failure):void {
        try {
            $state=array_intersect_key($state,array_flip(['stage','request_started_at','version_field_present','provided_version',
                'entity_category','entity_id','kind_category','upload_error','upload_size','mime_category','extension_category',
                'current_version','current_status','move_succeeded']));
            $code=(string)$failure->getCode();
            $state['exception_code']=preg_match('/^[a-zA-Z0-9]{0,12}$/D',$code)?$code:'';
            $class=get_class($failure);
            $state['exception_class']=preg_match('/^[a-zA-Z_\\\\][a-zA-Z0-9_\\\\]*$/D',$class)?$class:'other';
            $state['category']=$failure instanceof \PDOException?'database':($failure instanceof \Error?'php':'guard_or_storage');
            if($failure instanceof \PDOException){
                $info=$failure->errorInfo;
                $sqlstate=is_array($info)?($info[0]??null):null;
                $driver=is_array($info)?($info[1]??null):null;
                $state['pdo_sqlstate']=is_string($sqlstate)&&preg_match('/^[a-zA-Z0-9]{5}$/D',$sqlstate)?$sqlstate:null;
                $state['pdo_driver_code']=is_int($driver)||(is_string($driver)&&preg_match('/^[0-9]{1,10}$/D',$driver))?$driver:null;
            }
            $state['recorded_at']=date(DATE_ATOM);
            $state['elapsed_seconds']=round(microtime(true)-$state['request_started_at'],3);
            $state['php_pid']=getmypid();
            $state['trace']=bin2hex(random_bytes(12));
            $directory=APP_ROOT.'/runtime/upload-diagnostics';
            if(is_link($directory)||(!is_dir($directory)&&!@mkdir($directory,0700,true)))return;
            $stream=@fopen($directory.'/'.$state['trace'].'.json','xb');
            if(!$stream)return;
            try { $bytes=Importer::json($state);fwrite($stream,$bytes);fflush($stream); }
            finally { fclose($stream); }
        } catch (\Throwable) { /* Preserve the original exception even if diagnostics fail. */ }
    }

    public static function upload():never {
        $version=$_POST['version']??null;
        $state=['stage'=>'request_entry','request_started_at'=>microtime(true),
            'version_field_present'=>array_key_exists('version',$_POST),
            'provided_version'=>is_scalar($version)&&preg_match('/^[0-9]{1,10}$/D',(string)$version)?(int)$version:null];
        try { self::uploadChecked($state); }
        catch (\Throwable $failure) { self::uploadFailure($state,$failure);throw $failure; }
    }

    private static function uploadChecked(array &$state):never {
        $state['stage']='csrf';
        Http::checkCsrf();
        $state['stage']='access';
        Auth::requirePermission('rental.read');
        $u = Auth::user();
        $kind = (string)($_POST['kind'] ?? '');
        $type = (string)($_POST['entity_type'] ?? 'contract');
        $id = (int)($_POST['id'] ?? 0);
        $version = (int)($_POST['version'] ?? 0);
        $state['entity_category']=in_array($type,['contract','document'],true)?$type:'other';
        $state['entity_id']=$id;
        $state['kind_category']=in_array($kind,['signed-contract','signed-return-act','lawyer-file','signed-addendum','removed-return-act'],true)?$kind:'other';
        if ($type === 'contract')Access::contract($id, 2);
        elseif($type === 'document')Access::document($id, 2);
        else throw new RuntimeException('Неизвестный контекст документа.', 403);
        if (!in_array($kind, [
            'signed-contract',
            'signed-return-act',
            'lawyer-file',
            'signed-addendum',
            'removed-return-act'
        ], true) || !in_array($type, [
            'contract',
            'document'
        ], true))throw new RuntimeException('Неизвестный вид документа.');
        Auth::requirePermission($kind === 'lawyer-file'?'rental.approve.legal':'rental.write');
        Service::roles($kind === 'lawyer-file'?[
            'lawyer'
        ]:[
            'manager'
        ]);
        $state['stage']='upload_payload';
        $f = $_FILES['file'] ?? null;
        $state['upload_error']=is_array($f)&&is_int($f['error']??null)?$f['error']:null;
        $state['upload_size']=is_array($f)&&is_int($f['size']??null)?$f['size']:null;
        $mime=is_array($f)?($f['type']??null):null;
        $state['mime_category']=match($mime){'application/pdf'=>'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
            'application/msword'=>'doc',default=>'other'};
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] <= 0 || $f['size']>20*1024*1024 || !is_uploaded_file($f['tmp_name']))throw new RuntimeException('Выберите Word или PDF размером до 20 МБ.', 422);
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, [
            'doc',
            'docx',
            'pdf'
        ], true))throw new RuntimeException('Поддерживаются Word и PDF.');
        $head = file_get_contents($f['tmp_name'], false, null, 0, 8);
        if (($ext === 'pdf' && !str_starts_with($head, '%PDF-')) || ($ext === 'docx' && !str_starts_with($head, 'PK')) || ($ext === 'doc' && !str_starts_with($head, "\xD0\xCF\x11\xE0")))throw new RuntimeException('Формат файла не соответствует расширению.');
        $state['extension_category']=in_array($ext,['pdf','docx','doc'],true)?$ext:'other';
        $state['stage']='file_validation';
        self::validateFile($f['tmp_name'], $ext);
        $fid = bin2hex(random_bytes(16));
        $state['stage']='target_directory';
        $path = self::directory()
        . '/'
        . $fid
        . '.'
        . $ext;
        try {
            DB::transaction(function($p)use($f, $path, $fid, $ext, $kind, $type, $id, $version, $u, &$state) {
                $state['stage']='row_lock';
                FileOrder::lock($p);
                $r = Service::get($p, $type === 'contract'?'contracts':'rental_documents', $id);
                $state['current_version']=(int)$r['version'];
                $state['current_status']=in_array($r['status'],['draft','legal','accounting','returned','approved','sent','active','signed','reconciling','closed','cancelled'],true)?$r['status']:'other';
                $state['stage']='row_version';
                if ((int)$r['version'] !== $version)throw new RuntimeException('Версия документа изменилась.');
                $state['stage']='row_stage';
                if (($kind === 'signed-contract' && ($type !== 'contract' || !in_array($r['status'], [
                    'sent',
                    'active'
                ], true))) || ($kind === 'signed-addendum' && ($type !== 'document' || $r['status'] !== 'sent')) || ($kind === 'signed-return-act' && $type !== 'contract') || ($kind === 'removed-return-act' && ($type !== 'document' || $r['status'] !== 'signed' || !$r['applied'])))throw new RuntimeException('Вид файла не соответствует документу или этапу.');
                if ($kind === 'lawyer-file' && !($r['status'] === 'legal' || ($r['status'] === 'returned' && $r['return_to'] === 'lawyer')))throw new RuntimeException('Работа с файлом не назначена юристу.');
                if ($kind === 'signed-return-act' && $r['status'] !== 'reconciling')throw new RuntimeException('Договор не на сверке.');
                $state['stage']='baseline';
                $baseline = $type === 'contract'?Evidence::signature($p, $id):null;
                $state['stage']='file_move';
                $state['move_succeeded']=move_uploaded_file($f['tmp_name'], $path);
                if (!$state['move_succeeded'])throw new RuntimeException('Файл не сохранён.');
                $state['stage']='file_insert';
                $p->prepare('INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime,baseline_hash) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([
                    $fid,
                    $type,
                    $id,
                    $version,
                    $kind,
                    mb_substr(basename($f['name']),
                    0,
                    250),
                    basename($path),
                    hash_file('sha256',
                    $path),
                    $ext === 'pdf'?'application/pdf':($ext === 'docx'?'application/vnd.openxmlformats-officedocument.wordprocessingml.document':'application/msword'),
                    $baseline
                ]);
                if ($type === 'document' && $kind === 'signed-addendum'
                    && (\App\BillingPartTerms::state($p, 'document', $id)!==null || \App\BillingParts::terms($p, 'document', $id))) {
                    $state['stage']='signed_document_baseline';
                    $signature = Evidence::documentSignature($p, $r, $fid);
                    $update = $p->prepare('UPDATE rental_files SET baseline_hash=? WHERE id=? AND baseline_hash IS NULL');
                    $update->execute([$signature,$fid]);
                    if ($update->rowCount()!==1) throw new RuntimeException('Не удалось зафиксировать условия подписанного ДС.', 409);
                }
                $state['stage']='approval_cleanup';
                if ($kind === 'lawyer-file')$p->prepare('DELETE FROM rental_approvals WHERE entity_type=? AND entity_id=?')->execute([
                    $type,
                    $id
                ]);
                $state['stage']='file_order';
                FileOrder::assign($p, $fid);
                if($kind==='lawyer-file') {
                    $q=$p->prepare('SELECT * FROM rental_files WHERE id=?');$q->execute([$fid]);WordPack::inherit($p,$q->fetch());
                }
                $state['stage']='audit';
                Audit::record('document.upload', $type, $id, null, [
                    'file' => $fid,
                    'kind' => $kind,
                    'sha256' => hash_file('sha256',
                    $path)
                ]);
                $state['stage']='transaction_commit';
            });
        }
        catch (\Throwable $e) {
            if (is_file($path))unlink($path);
            throw $e;
        }
        $state['stage']='redirect';
        Http::redirect('/rental/'
        . ($type === 'contract'?'contract':'document')
        . '?id='
        . $id);
    }
    public static function dateText(?string $date):string {
        return $date?(new \DateTimeImmutable($date))->format('d.m.Y'):'Не подтверждено';
    }
    public static function amountText(?string $amount):string {
        if ($amount === null)return 'Не подтверждено';
        [
            $whole,
            $fraction
        ] = explode('.', bcadd($amount, '0', 2));
        return preg_replace('/\B(?=(\d{3})+(?!\d))/u', ' ', $whole)
        . ','
        . $fraction;
    }
    public static function produce(\PDO $p, string $entity, int $id, int $version):array {
        Auth::requirePermission('rental.read');
        Auth::requirePermission('rental.approve.legal');
        Service::roles([
            'lawyer'
        ]);
        if ($entity === 'contract')Access::contract($id, 2);
        elseif($entity === 'document')Access::document($id, 2);
        else throw new RuntimeException('Неизвестный контекст документа.', 403);
        \App\PagePolicy::requireCommand($entity === 'contract'?'contract.legal':'document.legal');
        if (!in_array($entity, [
            'contract',
            'document'
        ], true))throw new RuntimeException('Неизвестный вид документа.');
        FileOrder::lock($p);
        $record = Service::get($p, $entity === 'contract'?'contracts':'rental_documents', $id);
        if ((int)$record['version'] !== $version)throw new RuntimeException('Версия документа изменилась.');
        if (!($record['status'] === 'legal' || ($record['status'] === 'returned' && $record['return_to'] === 'lawyer')))throw new RuntimeException('Работа с документом не назначена юристу.');
        $c = $entity === 'contract'?$record:Service::get($p, 'contracts', (int)$record['contract_id']);
        if ($entity === 'document' && $record['base_hash'] !== Evidence::documentBase($p, (int)$c['id']))throw new RuntimeException('Исходные условия договора изменились.');
        $q = $p->prepare('SELECT * FROM parties WHERE id=?');
        $q->execute([
            $c['party_id']
        ]);
        $party = $q->fetch();
        $org = $p->query('SELECT * FROM organization WHERE id=1')->fetch();
        $templateType=$entity==='document' ? 'addendum' : TemplateAssets::contractKind($p,$c);
        $template=TemplateAssets::active($p,$templateType);
        if ($entity === 'document') {
            $q = $p->prepare('SELECT o.name,o.area,o.floor,t.start_date,t.end_date,t.amount FROM document_terms t JOIN rental_objects o ON o.id=t.object_id WHERE t.document_id=? ORDER BY o.id');
            $q->execute([
                $id
            ]);
        }
        else {
            $q = $p->prepare('SELECT o.name,o.area,o.floor,t.start_date,t.end_date,t.amount FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id LEFT JOIN contract_terms t ON t.contract_id=co.contract_id AND t.object_id=co.object_id WHERE co.contract_id=? ORDER BY o.id,t.id');
            $q->execute([
                $id
            ]);
        }
        $terms = $q->fetchAll();
        $partState = \App\BillingPartTerms::state($p, $entity, $id);
        if ($partState!==null && $partState['state']!=='KNOWN') throw new RuntimeException('Не подтверждён состав частей для Word.', 409);
        $partTerms = \App\BillingParts::terms($p, $entity, $id);
        if ($partTerms && $partState===null) throw new RuntimeException('Не подтверждён состав частей для Word.', 409);
        foreach ($partTerms as $part) {
            \App\BillingParts::requireReady($part);
            $terms[] = $part + ['billing_part' => true];
        }
        if ($org['vat']===null) throw new RuntimeException('Не указан НДС нашей организации. Проверьте реквизиты перед формированием Word.');
        $roundedZeroDocument = $entity==='document' && $partTerms && count($terms)===count($partTerms)
            && count(array_filter($partTerms,static fn($part)=>$part['amount']!==null && bccomp($part['amount'],'0',2)===0
                && PartTerms::roundedZeroProven($partState,$part)))===count($partTerms);
        if ($record['amount']===null || bccomp($record['amount'],'0',2)<0
            || (bccomp($record['amount'],'0',2)===0 && !$roundedZeroDocument) || !$terms) {
            throw new RuntimeException($partTerms?'Для формирования Word нужны подтверждённые суммы и объекты.':'Для формирования Word нужны подтверждённые суммы и помещения.');
        }
        foreach ($terms as $term) {
            $zeroPartDocument = $entity==='document' && !empty($term['billing_part']) && PartTerms::roundedZeroProven($partState,$term);
            if ($term['amount']===null || bccomp($term['amount'],'0',2)<0 || (!$zeroPartDocument && bccomp($term['amount'],'0',2)===0)) {
                throw new RuntimeException(!empty($term['billing_part'])?'Укажите подтверждённую цену каждой части перед формированием Word.':'Укажите подтверждённую цену каждого помещения перед формированием Word.');
            }
        }
        $lines = [
        ];
        foreach ($terms as $t)$lines[] = (!empty($t['billing_part'])?PartTerms::label($t):$t['name'])
        . ' | '
        . (!empty($t['billing_part'])?($t['area'] ?? '—'):$t['area'])
        . ' м² | '
        . self::dateText($t['start_date'] ?? $record['start_date'])
        . ' — '
        . self::dateText($t['end_date'] ?? $record['end_date'])
        . ' | '
        . ($t['amount'] === null?'Общая сумма договора':self::amountText($t['amount'])
        . ' руб.');
        $values = [
            'title' => $entity === 'document'?'ДОПОЛНИТЕЛЬНОЕ СОГЛАШЕНИЕ':'ДОГОВОР АРЕНДЫ',
            'number' => $record['number'],
            'date' => self::dateText($record['document_date']),
            'base' => $entity === 'document'?'К договору №'
            . $c['number']
            . ' от '
            . self::dateText($record['base_date'] ?? $c['document_date']):'',
            'organization' => $org['name']
            . ', ИНН '
            . $org['inn']
            . ', '
            . $org['address'],
            'party' => $party['name']
            . ', ИНН '
            . $party['inn']
            . ', '
            . $party['address'],
            'terms' => implode('; ',
            $lines),
            'effective' => $entity === 'document'?'Условия вступают в силу '
            . self::dateText($record['effective_date']):'Срок аренды: '
            . self::dateText($c['start_date'])
            . ' — '
            . self::dateText($c['end_date']),
            'total' => self::amountText($record['amount'])
            . ' руб',
            'vat' => $org['vat'] === null?'НДС не подтверждён':'В том числе НДС '
            . rtrim(rtrim($org['vat'],
            '0'),
            '.')
            . '%.',
            'custom_clauses' => $template['body'] ?? '',
            'template_version' => (string)$template['version']
        ];
        $fid = bin2hex(random_bytes(16));
        $path = self::directory()
        . '/'
        . $fid
        . '.docx';
        self::buildDocx($values, $templateType, $path,$template['asset']['path']);
        $filename = ($entity === 'document'?'ДС ':'Договор ')
        . $record['number']
        . ' v'
        . $version
        . '.docx';
        $hash = hash_file('sha256', $path);
        $p->prepare('INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime) VALUES(?,?,?,?,?,?,?,?,?)')->execute([
            $fid,
            $entity,
            $id,
            $version,
            'lawyer-file',
            $filename,
            basename($path),
            $hash,
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
        ]);
        $p->prepare('DELETE FROM rental_approvals WHERE entity_type=? AND entity_id=?')->execute([
            $entity,
            $id
        ]);
        FileOrder::assign($p, $fid);
        Audit::record('document.generate', $entity, $id, null, [
            'file' => $fid,
            'template' => $template['id'],
            'templateVersion' => $template['version'],
            'sha256' => $hash
        ]);
        return [
            'id' => $fid,
            'path' => $path,
            'sha256' => $hash,
            'values' => $values
        ];
    }
    public static function export(\PDO $db,string $entity,int $id,int $version,string $format): array {
        if ($format==='docx') return self::produce($db,$entity,$id,$version);
        if ($format!=='pdf') throw new RuntimeException('Неизвестный формат документа.',422);
        Auth::requirePermission('rental.approve.legal');
        Service::roles(['lawyer']);
        FileOrder::lock($db);
        $record=match($entity) {
            'contract'=>Access::contract($id,2),
            'document'=>Access::document($id,2),
            default=>throw new RuntimeException('Неизвестный контекст документа.',403),
        };
        Service::version($record,['version'=>$version]);
        $query=$db->prepare("SELECT COUNT(*) FROM rental_files WHERE entity_type=? AND entity_id=? AND kind='lawyer-file'");
        $query->execute([$entity,$id]);
        $file=$query->fetchColumn() ? Evidence::latest($db,$entity,$id,'lawyer-file') : self::produce($db,$entity,$id,$version);
        return WordDerivative::produce($db,$file['id']);
    }

    public static function generate():never {
        if (isset($_POST['original_pack'])) WordPack::generate();
        Http::checkCsrf();
        $id = (int)($_POST['id'] ?? 0);
        $version = (int)($_POST['version'] ?? 0);
        $entity = (string)($_POST['entity_type'] ?? 'contract');
        $format=(string)($_POST['format'] ?? 'docx');
        $file = DB::transaction(fn($p) => self::export($p, $entity, $id, $version,$format));
        Http::redirect('/rental/download?id='
        . $file['id']);
    }
    public static function invoice(): never
    {
        Http::checkCsrf();
        Auth::requirePermission('rental.read');
        Access::finance(2);
        $id = (int)($_POST['id'] ?? 0);
        $path = null;
        try {
            $fileId = DB::transaction(function (\PDO $db) use ($id, &$path): string {
                FileOrder::lock($db);
                Service::get($db, 'invoices', $id);
                $invoice = Controller::query(
                    'SELECT i.*,c.number contract_number,p.name party_name,p.inn party_inn,p.address party_address '
                    .'FROM invoices i JOIN contracts c ON c.id=i.contract_id JOIN parties p ON p.id=c.party_id WHERE i.id=?',
                    [$id]
                )[0] ?? throw new RuntimeException('Счёт не найден.', 404);
                $bytes = self::renderPdf(InvoiceDocument::html($db, $invoice));
                $fileId = bin2hex(random_bytes(16));
                $path = self::directory().'/'.$fileId.'.pdf';
                if (file_put_contents($path, $bytes, LOCK_EX) !== strlen($bytes)) {
                    throw new RuntimeException('Не удалось сохранить PDF счёта.');
                }
                $db->prepare(
                    "INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime) "
                    ."VALUES(?,'invoice',?,?,'pdf',?,?,?,'application/pdf')"
                )->execute([
                    $fileId, $id, $invoice['version'], 'Счёт '.$invoice['number'].'.pdf',
                    basename($path), hash('sha256', $bytes),
                ]);
                $order = FileOrder::assign($db, $fileId);
                Audit::record('invoice.pdf', 'invoice', $id, null, [
                    'file' => $fileId, 'sha256' => hash('sha256', $bytes), 'upload_order' => $order,
                ]);
                return $fileId;
            });
        } catch (\Throwable $error) {
            if ($path && is_file($path)) {
                unlink($path);
            }
            throw $error;
        }
        Http::redirect('/rental/download?id='.$fileId);
    }
    public static function download():never {
        Auth::requirePermission('rental.read');
        $id = (string)($_GET['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $id))throw new RuntimeException('Файл не найден.');
        $r = Controller::query('SELECT * FROM rental_files WHERE id=?', [
            $id
        ])[0] ?? throw new RuntimeException('Файл не найден.');
        Access::file($r);
        $path = self::directory()
        . '/'
        . basename($r['path']);
        if (!is_file($path) || hash_file('sha256', $path) !== $r['sha256'])throw new RuntimeException('Проверка целостности файла не пройдена.');
        header('Content-Type: '
        . $r['mime']);
        header('Content-Disposition: attachment; filename="document.'
        . (pathinfo($r['filename'], PATHINFO_EXTENSION)?:'bin')
        . '"; filename*=UTF-8\'\''
        . rawurlencode($r['filename']));
        header('Content-Length: '
        . filesize($path));
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }
}
