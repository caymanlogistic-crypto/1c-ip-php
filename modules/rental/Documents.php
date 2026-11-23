<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\{Auth, DB, Http, Audit};
use RuntimeException;
final class Documents {
    public static function signedContractLinks(int $contractId,string $label=''): array
    {
        $files=Controller::query("SELECT id,version FROM rental_files WHERE entity_type='contract' AND entity_id=? AND kind='signed-contract' ORDER BY upload_order DESC,id DESC",[$contractId]);
        $links=[];
        foreach ($files as $file) {
            $links[]='<a class="btn btn-secondary" href="/rental/download?id='.self::e($file['id']).'">'
                .self::e('Подписанный договор'.$label.' · версия '.$file['version']).'</a>';
        }
        return $links;
    }
    private static function e(mixed $v):string {
        return htmlspecialchars((string)($v ?? ''), ENT_QUOTES|ENT_XML1, 'UTF-8');
    }
    public static function directory():string {
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
        $actKind=array_search($kind,PremisesActs::FILE_KINDS,true);
        $photo=$kind===PremisesInventory::PHOTO;
        $serviceDoc=$kind===RentalServices::FILE;
        $guarantee=$kind===DebtFollowUp::FILE;
        $expiry=$kind===ExpiryNotice::FILE;
        $closingAgreement=$kind===FinalSettlement::AGREEMENT_FILE;
        $oneReturn=$kind===ReturnAct::ONE_FILE;
        $invoiceId=$guarantee?(int)($_POST['invoice_id']??0):0;
        $serviceKey=$serviceDoc?DirectoryDetails::text($_POST,'service_id',32):'';
        $state['entity_category']=in_array($type,['contract','document','party','object'],true)?$type:'other';
        $state['entity_id']=$id;
        $state['kind_category']=in_array($kind,['signed-contract','signed-return-act','lawyer-file','signed-addendum','removed-return-act'],true)?$kind:'other';
        if ($type === 'contract')Access::contract($id, 2);
        elseif($type === 'document')Access::document($id, 2);
        elseif($type === 'party')Access::pages(['parties'],2);
        elseif($type==='object' && $photo) PremisesInventory::object($id,2);
        else throw new RuntimeException('Неизвестный контекст документа.', 403);
        if (($type==='party')!==($kind===PartyLegalReview::FILE) ||
            (isset(ContractRevision::KINDS[$kind]) && $type!=='contract') ||
            (($actKind!==false || $serviceDoc || $guarantee || $expiry || $closingAgreement || $oneReturn) && $type!=='contract') || ($photo && $type!=='object'))
            throw new RuntimeException('Вид файла не соответствует карточке.',422);
        if (!in_array($kind, [
            'signed-contract',
            'signed-return-act',
            'lawyer-file',
            'signed-addendum',
            'removed-return-act',
            PartyLegalReview::FILE,
            ...array_values(PremisesActs::FILE_KINDS),
            PremisesInventory::PHOTO,
            RentalServices::FILE,
            DebtFollowUp::FILE,
            ExpiryNotice::FILE,
            FinalSettlement::AGREEMENT_FILE,
            ReturnAct::ONE_FILE,
            ...array_keys(ContractRevision::KINDS),
        ], true))throw new RuntimeException('Неизвестный вид документа.');
        if ($expiry) ExpiryNotice::access($id,2);
        else {
            Auth::requirePermission($kind === 'lawyer-file' || $closingAgreement?'rental.approve.legal':'rental.write');
            Service::roles($kind === 'lawyer-file' || $closingAgreement?[
            'lawyer'
        ]:[
            'manager'
            ]);
        }
        if ($kind === ReturnAct::KIND) \App\PagePolicy::requireCommand('contract.return-act');
        if ($closingAgreement || $oneReturn) \App\PagePolicy::requireCommand($closingAgreement?FinalSettlement::AGREEMENT:ReturnAct::RECORD_ONE);
        $state['stage']='upload_payload';
        $f = $_FILES['file'] ?? null;
        $state['upload_error']=is_array($f)&&is_int($f['error']??null)?$f['error']:null;
        $state['upload_size']=is_array($f)&&is_int($f['size']??null)?$f['size']:null;
        $mime=is_array($f)?($f['type']??null):null;
        $state['mime_category']=match($mime){'application/pdf'=>'pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document'=>'docx',
            'application/msword'=>'doc',default=>'other'};
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || $f['size'] <= 0 || $f['size']>($photo?5:20)*1024*1024 || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException($photo?'Выберите фото в PNG или JPEG размером до 5 МБ.':'Выберите Word или PDF размером до 20 МБ.',422);
        }
        $isReturnAct = $kind === ReturnAct::KIND;
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if ($photo) {
            $image=@getimagesize($f['tmp_name']);
            $mime=$image['mime']??'';
            $ext=match($mime){'image/png'=>'png','image/jpeg'=>'jpg',default=>throw new RuntimeException('Фото должно быть в PNG или JPEG.',422)};
        } else {
            if (!in_array($ext,['doc','docx','pdf'],true)) {
                throw new RuntimeException($isReturnAct?'Поддерживаются файлы PDF, DOC и DOCX.':'Поддерживаются Word и PDF.', $isReturnAct?422:0);
            }
            $head=file_get_contents($f['tmp_name'],false,null,0,8);
            if (($ext==='pdf' && !str_starts_with($head,'%PDF-')) || ($ext==='docx' && !str_starts_with($head,'PK'))
                || ($ext==='doc' && !str_starts_with($head,"\xD0\xCF\x11\xE0"))) {
                throw new RuntimeException('Формат файла не соответствует расширению.', $isReturnAct?422:0);
            }
            $state['extension_category']=$ext;
            $state['stage']='file_validation';
            try {
                self::validateFile($f['tmp_name'],$ext);
            } catch (RuntimeException $e) {
                if (!$isReturnAct) throw $e;
                throw new RuntimeException($e->getMessage(),422,$e);
            }
            $mime=match($ext){'pdf'=>'application/pdf','docx'=>'application/vnd.openxmlformats-officedocument.wordprocessingml.document',default=>'application/msword'};
        }
        $fid = bin2hex(random_bytes(16));
        $state['stage']='target_directory';
        $path = self::directory()
        . '/'
        . $fid
        . '.'
        . $ext;
        try {
            DB::transaction(function($p)use($f, $path, $fid, $ext, $mime, $kind, $type, $id, $version, $actKind, $photo, $serviceDoc, $serviceKey, $guarantee, $invoiceId, $expiry, $closingAgreement, $oneReturn, &$state) {
                $state['stage']='row_lock';
                if ($actKind!==false || $photo || $serviceDoc || $guarantee || $expiry || $closingAgreement || $oneReturn) $p->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
                FileOrder::lock($p);
                $table=match($type){'contract'=>'contracts','party'=>'parties','object'=>'rental_objects',default=>'rental_documents'};
                $r = Service::get($p,$table,$id);
                $state['current_version']=(int)$r['version'];
                $state['current_status']=in_array($r['status']??'', ['draft','legal','accounting','returned','approved','sent','active','signed','reconciling','closed','cancelled'],true)?$r['status']:'other';
                $state['stage']='row_version';
                if ((int)$r['version'] !== $version) {
                    if ($kind === ReturnAct::KIND) throw new RuntimeException('Версия документа изменилась. Откройте карточку и повторите загрузку.', 409);
                    throw new RuntimeException('Версия документа изменилась.');
                }
                $state['stage']='row_stage';
                if ($type==='party') PartyLegalReview::checkUpload($r);
                if ($guarantee) DebtFollowUp::requireWritable($p,$invoiceId,$id);
                if (isset(ContractRevision::KINDS[$kind])) ContractRevision::requireOpen($p,$r);
                if (($kind === 'signed-contract' && ($type !== 'contract' || !in_array($r['status'], [
                    'sent',
                    'active'
                ], true))) || ($kind === 'signed-addendum' && ($type !== 'document' || $r['status'] !== 'sent')) || ($kind === ReturnAct::KIND && $type !== 'contract') || ($kind === 'removed-return-act' && ($type !== 'document' || $r['status'] !== 'signed' || !$r['applied'])))throw new RuntimeException('Вид файла не соответствует документу или этапу.', $kind === ReturnAct::KIND?409:0);
                if ($kind === 'lawyer-file' && !($r['status'] === 'legal' || ($r['status'] === 'returned' && $r['return_to'] === 'lawyer')))throw new RuntimeException('Работа с файлом не назначена юристу.');
                if ($kind === ReturnAct::KIND && $r['status'] !== 'reconciling')throw new RuntimeException('Договор не на сверке.', 409);
                if ($closingAgreement) FinalSettlement::stage($r);
                if ($oneReturn && $r['status']!=='reconciling') throw new RuntimeException('Договор не на сверке.',409);
                if (($oneReturn || $kind===ReturnAct::KIND) && OccupiedBilling::noPhysicalReturn($p,$r)) {
                    throw new RuntimeException('Физический возврат не применяется.',409);
                }
                $state['stage']='baseline';
                $baseline = $type === 'contract' && !$serviceDoc && !$guarantee && !$expiry?Evidence::signature($p, $id):null;
                $filename = mb_substr(basename($f['name']), 0, 250);
                if ($photo) $filename=mb_substr(pathinfo($filename,PATHINFO_FILENAME),0,240).'.'.$ext;
                $sha=hash_file('sha256',$f['tmp_name']);
                if ($closingAgreement && in_array($sha,array_column(FinalSettlement::files($p,$id),'sha256'),true)) return;
                if ($oneReturn) {
                    foreach (ReturnAct::files($p,$id,ReturnAct::ONE_FILE) as $old) {
                        if ($old['sha256']===$sha && ((int)$old['version']===$version || ReturnAct::receipt($p,$old['id']))) return;
                    }
                }
                if ($expiry) {
                    $notice=ExpiryNotice::draftForAttachment($p,$r,$_POST);
                    $baseline=ExpiryNotice::fingerprint($notice);
                    if (in_array($sha,array_column(ExpiryNotice::files($p,$notice),'sha256'),true)) return;
                }
                $objectId=(int)($_POST['object_id']??0);
                if ($actKind!==false) {
                    $previous=PremisesActs::checkUpload($p,$r,$objectId,$actKind);
                    if ($previous && hash_equals($previous['sha256'],$sha) && hash_equals((string)$previous['baseline_hash'],$baseline)) return;
                }
                if ($serviceDoc) {
                    $previous=RentalServices::checkUpload($p,$r,$serviceKey,(int)($_POST['service_version']??0));
                    if ($previous && hash_equals($previous['sha256'],$sha)) return;
                }
                if ($guarantee && in_array($sha,array_column(DebtFollowUp::files($p,$invoiceId),'sha256'),true)) return;
                if ($photo) {
                    $item=PremisesInventory::editableItem($p,$id,$_POST);
                    if (in_array($sha,array_column($item['photos'],'sha256'),true)) return;
                }
                if ($kind === ReturnAct::KIND) {
                    $act = ReturnAct::state($p, $r, $baseline);
                    if ($act['confirmed']) throw new RuntimeException('Акт возврата уже подтверждён.', 409);
                    if ($act['file'] && $act['file']['filename'] === $filename
                        && hash_equals($act['file']['sha256'],$sha)) return;
                }
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
                    $filename,
                    basename($path),
                    $sha,
                    $mime,
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
                if ($actKind!==false) PremisesActs::pinUpload($p,$fid,$objectId);
                if ($serviceDoc) RentalServices::pinUpload($p,$fid,$serviceKey);
                if ($guarantee) DebtFollowUp::pin($p,$fid,$invoiceId);
                if ($photo) PremisesInventory::addPhoto($p,(string)$_POST['item_id'],$item,$fid,$sha);
                if($kind==='lawyer-file') {
                    $q=$p->prepare('SELECT * FROM rental_files WHERE id=?');$q->execute([$fid]);WordPack::inherit($p,$q->fetch());
                }
                $state['stage']='audit';
                Audit::record('document.upload', $type, $id, null, [
                    'file' => $fid,
                    'kind' => $kind,
                    'sha256' => hash_file('sha256',
                    $path)
                ] + ($kind === ReturnAct::KIND ? ['return_act_pending'=>true] : []));
                $state['stage']='transaction_commit';
            });
        }
        catch (\Throwable $e) {
            if (is_file($path))unlink($path);
            throw $e;
        }
        $state['stage']='redirect';
        if ($serviceDoc) Http::redirect('/rental/service?id='.$serviceKey);
        if ($guarantee) Http::redirect(DebtFollowUpCard::route($invoiceId));
        if ($expiry) Http::redirect(ExpiryNoticeCard::route($id,(string)$_POST['notice_end']));
        if ($closingAgreement || $oneReturn)
            Http::redirect('/rental/action-form?id='.$id.'&action='.($closingAgreement?FinalSettlement::AGREEMENT:ReturnAct::RECORD_ONE));
        if ($actKind!==false) Http::redirect('/rental/acts?id='.$id.'&object_id='.(int)($_POST['object_id']??0).'&kind='.$actKind);
        if ($photo) Http::redirect('/rental/object-inventory?id='.$id);
        if ($kind===PartyLegalReview::FILE) Http::redirect('/rental/party-review?id='.$id.'&action='.PartyLegalReview::SUBMIT);
        if (isset(ContractRevision::KINDS[$kind])) Http::redirect('/rental/action-form?id='.$id.'&action='.ContractRevision::ACTION);
        if ($kind === ReturnAct::KIND) Http::redirect('/rental/action-form?id='.$id.'&action=contract.return-act');
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
        $created=[];
        try {
            $fileId=DB::transaction(static function(\PDO $db) use ($id,&$created): string {
                return self::persistInvoicePdf($db,$id,$created);
            });
        } catch (\Throwable $error) {
            self::removeUncommitted(DB::pdo(),$created);
            throw $error;
        }
        Http::redirect('/rental/download?id='.$fileId);
    }

    public static function persistInvoicePdf(\PDO $db,int $id,array &$created): string
    {
        FileOrder::lock($db);
        Service::get($db,'invoices',$id);
        $invoice=Controller::query(
            'SELECT i.*,p.name party_name FROM invoices i JOIN contracts c ON c.id=i.contract_id JOIN parties p ON p.id=c.party_id WHERE i.id=?',
            [$id]
        )[0] ?? throw new RuntimeException('Счёт не найден.',404);
        if ($original=InvoiceDocument::original($db,$id)) return $original['id'];
        $query=$db->prepare("SELECT * FROM rental_files WHERE entity_type='invoice' AND entity_id=? AND version=?
            AND kind='pdf' AND mime='application/pdf' ORDER BY upload_order DESC LIMIT 1");
        $query->execute([$id,$invoice['version']]);
        if ($print=$query->fetch()) {
            Evidence::integrity($print);
            return $print['id'];
        }
        $bytes=self::renderPdf(InvoiceDocument::html($db,$invoice));
        $fileId=bin2hex(random_bytes(16));
        $path=self::directory().'/'.$fileId.'.pdf';
        $created[$fileId]=$path;
        if (file_put_contents($path,$bytes,LOCK_EX)!==strlen($bytes)) {
            throw new RuntimeException('Не удалось сохранить PDF счёта.');
        }
        $hash=hash('sha256',$bytes);
        $db->prepare("INSERT INTO rental_files(id,entity_type,entity_id,version,kind,filename,path,sha256,mime)
            VALUES(?,'invoice',?,?,'pdf',?,?,?,'application/pdf')")
            ->execute([$fileId,$id,$invoice['version'],InvoiceDocument::filename($invoice,$invoice['party_name']),basename($path),$hash]);
        $order=FileOrder::assign($db,$fileId);
        Audit::record('invoice.pdf','invoice',$id,null,['file'=>$fileId,'sha256'=>$hash,'upload_order'=>$order]);
        return $fileId;
    }

    public static function removeUncommitted(\PDO $db,array $files): void
    {
        if (!$files) return;
        try {
            $query=$db->prepare('SELECT 1 FROM rental_files WHERE id=?');
            foreach ($files as $id=>$path) {
                $query->execute([$id]);
                if (!$query->fetchColumn() && is_file($path)) unlink($path);
            }
        } catch (\Throwable) {
            // The outcome of the original command takes precedence.
        }
    }

    public static function invoicePdfResponse(array $invoice, ?array $original, bool $download): never
    {
        if ($original) {
            Access::file($original);
            $path = self::directory().'/'.basename($original['path']);
            $bytes = file_get_contents($path);
            if ($bytes === false || !hash_equals($original['sha256'], hash('sha256', $bytes))) {
                throw new RuntimeException('Проверка целостности оригинала не пройдена.', 409);
            }
            $filename = InvoiceDocument::originalFilename(DB::pdo(), $invoice, $original);
        } else {
            $bytes = self::renderPdf(InvoiceDocument::html(DB::pdo(), $invoice));
            $filename = InvoiceDocument::filename($invoice, $invoice['party_name']);
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: '.($download ? 'attachment' : 'inline')
            .'; filename="invoice.pdf"; filename*=UTF-8\'\''.rawurlencode($filename));
        header('Content-Length: '.strlen($bytes));
        header('Cache-Control: private, no-store');
        echo $bytes;
        exit;
    }

    public static function download():never {
        Auth::requirePermission('rental.read');
        $id = (string)($_GET['id'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D', $id))throw new RuntimeException('Файл не найден.');
        $r = Controller::query('SELECT * FROM rental_files WHERE id=?', [
            $id
        ])[0] ?? throw new RuntimeException('Файл не найден.');
        Access::file($r,(int)($_GET['contract_id']??0));
        $path = self::directory()
        . '/'
        . basename($r['path']);
        if (!is_file($path) || hash_file('sha256', $path) !== $r['sha256'])throw new RuntimeException('Проверка целостности файла не пройдена.', $r['kind'] === ReturnAct::KIND?409:0);
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
