<?php
declare(strict_types=1);
namespace Modules\Integrations;

final class TemplateCatalog
{
    public const MESSAGE_VARS=['text','number','party','lawyer','manager','accountant','director','admin','version','reason','invoiceNumbers','recipientNames','actor','documentNumber','effective','context','documentLabel','invoiceSummary','effectiveSummary','managerEmail','recordUrl'];
    public const RESERVATION_VARS=[
        'reservationCreated'=>['reservationStart','reservationCommentLine','reservationCommentPrefix','reservationComment'],
        'reservationExtended'=>['previousDeadline','reservationStart'],
        'reservationCancelled'=>[],
        'reservationReminder'=>[],
        'reservationExpired'=>[],
    ];

    public static function messageVariables(string $channel,string $key): array
    {
        if ($channel!=='max') return self::MESSAGE_VARS;
        $reservation=self::RESERVATION_VARS[$key] ?? [];
        if (in_array($key,['booking','bookingCreated','bookingUpdated','bookingCancelled'],true)) {
            $reservation=['reservationStart','previousDeadline'];
        }
        $contract = match ($key) {
            ContractSecurityMax::KEY => [ContractSecurityMax::TOKEN],
            AccountantPackageMax::KEY => [AccountantPackageMax::TOKEN],
            default => [],
        };
        return array_merge(self::MESSAGE_VARS,ContractSubmitMax::VARIABLES,['reservationTypeLabel','reservationAreaLabel'],$reservation,$contract);
    }

    public const TOP_VARS=['title','text','record','section','why','actor','number','party','template','details'];
    public function __construct(private \PDO $db,private PrivateStore $store) {}
    /** Render a particular immutable event revision into a delivery DTO; never resolve recipients here. */
    public function event(string $channel,string $key,array $values): array {
        return self::renderEvent($this->db,$channel,$key,$values);
    }
    public static function renderEvent(\PDO $db,string $channel,string $key,array $values,bool $legacyDirectPackage=false): array {
        if(!in_array($channel,['email','max'],true)||!preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$key))throw new \InvalidArgumentException('CATALOG_EVENT_INVALID');
        if(array_diff(array_keys($values),self::messageVariables($channel,$key)))throw new \InvalidArgumentException('UNKNOWN_TEMPLATE_FIELD');
        foreach($values as $value)if(!is_string($value))throw new \InvalidArgumentException('TEMPLATE_VALUE_STRING_REQUIRED');
        $q=$db->prepare('SELECT * FROM integration_template_catalog WHERE channel=? AND source_key=?');$q->execute([$channel,$key]);$entry=$q->fetch(\PDO::FETCH_ASSOC);if(!$entry)throw new \RuntimeException('CATALOG_ENTRY_NOT_FOUND');
        if ($channel==='max' && $key===AccountantPackageMax::KEY && AccountantPackageMax::matchesTemplate($entry)
            && empty($values[AccountantPackageMax::TOKEN])) {
            if (!$legacyDirectPackage) throw new \RuntimeException('ACCOUNTANT_PACKAGE_CONFIRMATION_REQUIRED');
            $history=$db->prepare('SELECT format,subject,body_text FROM integration_template_history WHERE channel=? AND source_key=? AND version=?');
            $history->execute([$channel,$key,AccountantPackageMax::LEGACY_VERSION]);
            $legacy=$history->fetch(\PDO::FETCH_ASSOC);
            $approved=Support::decode(file_get_contents(__DIR__.'/defaults/contract-max-templates.json'))[$key];
            if (!$legacy || $legacy['format']!==$approved['format'] || $legacy['body_text']!==$approved['body'] || $legacy['subject']!=='') {
                throw new \RuntimeException('CONTRACT_RELAY_TEMPLATE_CHANGED',409);
            }
            $entry=array_replace($entry,$legacy,['version'=>AccountantPackageMax::LEGACY_VERSION]);
        }
        $render=self::preview($channel,$entry,$values);
        $binding=['channel'=>$channel,'source_key'=>$key,'version'=>(int)$entry['version'],'source_sha256'=>$entry['source_sha256'],'recipients_policy'=>$entry['recipients_policy'],'attachment_policy'=>$entry['attachment_policy'],'format'=>$render['format']];
        $binding['render_sha256']=hash('sha256',Support::canonical(['subject'=>$render['subject'],'body'=>$render['body'],'values'=>$values]));
        return $render+['binding'=>$binding,'values'=>$values];
    }
    public function verifyEvent(array $binding,array $values,string $subject,string $body): void {
        $current=$this->event((string)($binding['channel']??''),(string)($binding['source_key']??''),$values);
        if(!hash_equals(Support::canonical($current['binding']),Support::canonical($binding))||$subject!==$current['subject']||$body!==$current['body'])throw new \RuntimeException('CATALOG_EVENT_REVISION_CONFLICT');
    }
    public static function validateText(string $text,array $variables,int $limit,bool $singleLine=false): void {if(trim($text)===''||mb_strlen($text)>$limit||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f]/',$text)||$singleLine&&preg_match('/[\r\n]/',$text))throw new \InvalidArgumentException('TEMPLATE_TEXT_LIMIT');preg_match_all('/\{([^{}]*)\}/u',$text,$matches);foreach($matches[1] as $name)if(!in_array($name,$variables,true))throw new \InvalidArgumentException('UNKNOWN_TEMPLATE_FIELD');if(preg_match('/[{}]/',preg_replace('/\{[^{}]+\}/u','',$text)))throw new \InvalidArgumentException('BROKEN_TEMPLATE_BRACES');}
    public static function safeUrl(string $url): bool {return !preg_match('/[\s<>"\'\\\\{}]/u',$url)&&preg_match('~^(?:https?://[^/\s]+|mailto:[^\s]+)~i',$url)===1;}
    public static function validateFormat(string $format,string $text,array $variables=self::MESSAGE_VARS): void {if(!in_array($format,['plain','markdown','html'],true))throw new \InvalidArgumentException('UNKNOWN_TEMPLATE_FORMAT');self::validateText($text,$variables,4000);if($format==='markdown'){preg_match_all('/\]\(([^)]*)\)/u',$text,$m);foreach($m[1] as $url)if(!self::safeUrl($url))throw new \InvalidArgumentException('UNSAFE_TEMPLATE_LINK');}if($format==='html'){$rest=preg_replace('~</?(?:b|strong|i|em|s|del|u|ins|code|pre|mark|blockquote|h[1-6])\s*>~i','',$text);$rest=preg_replace_callback('~<a\s+href=("([^"]*)"|\'([^\']*)\')\s*>~i',static function($m){if(!self::safeUrl($m[2]?:$m[3]))throw new \InvalidArgumentException('UNSAFE_TEMPLATE_LINK');return '';},$rest);$rest=preg_replace('~</a\s*>~i','',$rest);if(preg_match('~<[^>]*>|<\s*[a-z!/]~i',$rest))throw new \InvalidArgumentException('UNSAFE_TEMPLATE_HTML');}}
    public function importState(string $bytes): array {$source=Support::decode($bytes);$domain=$source['domain']??$source;$hash=hash('sha256',$bytes);$q=$this->db->prepare('SELECT 1 FROM rental_imports WHERE state_hash=?');$q->execute([$hash]);if(!$q->fetchColumn())throw new \RuntimeException('STATE_IMPORT_ATTESTATION_REQUIRED');$path=$this->store->put('catalog-imports',$bytes);$counts=[];$this->db->beginTransaction();try{foreach(['max'=>'maxTemplates','email'=>'emailTemplates'] as $channel=>$key){$entries=$domain[$key]??null;if(!is_array($entries))throw new \RuntimeException('CATALOG_SOURCE_MISSING');$counts[$channel]=0;foreach($entries as $sourceKey=>$record){if(!is_array($record))throw new \RuntimeException('CATALOG_SOURCE_INVALID');$this->importRecord($channel,(string)$sourceKey,$record,$hash,$path,'state.2026-10-31');$counts[$channel]++;}}$top=Support::decode(file_get_contents(__DIR__.'/defaults/top-catalog.json'));$overrides=$domain['topToastTemplates']??[];$counts['top']=0;foreach($top['entries'] as $record){$key=$record['id'];$record=array_replace($record,$overrides[$key]??[]);$this->importRecord('top',$key,$record,$hash,$path,$top['source_commit']);$counts['top']++;}$this->db->commit();return ['source_sha256'=>$hash,'counts'=>$counts];}catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}}
    public function installFirstInvoice(): void
    {
        $bytes=file_get_contents(__DIR__.'/defaults/first-invoice-max.json');
        $entry=Support::decode($bytes);
        self::validateFormat($entry['format'],$entry['body'],self::messageVariables('max','firstInvoice'));
        $this->importRecord('max','firstInvoice',$entry,hash('sha256',$bytes),'bundled','first-invoice-v1');
    }

    public function installHandoverQuestion(): void {
        $bytes = file_get_contents(__DIR__.'/defaults/handover-question-max.json');
        $entry = Support::decode($bytes);
        self::validateFormat($entry['format'], $entry['body']);
        $this->importRecord('max', \Modules\Rental\HandoverQuestion::EVENT, $entry, hash('sha256', $bytes), 'bundled', 'handover-question-v1');
    }
    public function installStaffHandoff(): void
    {
        $bytes = file_get_contents(__DIR__.'/defaults/staff-handoff-max.json');
        $entry = Support::decode($bytes);
        self::validateFormat($entry['format'], $entry['body'],self::messageVariables('max','staffHandoff'));
        $this->importRecord('max', 'staffHandoff', $entry, hash('sha256', $bytes), 'bundled', 'staff-handoff-v1');
    }

    public function installApprovedMaxLayout(bool $apply): array
    {
        $staffBytes=file_get_contents(__DIR__.'/defaults/staff-handoff-max.json');
        $entries=['staffHandoff'=>Support::decode($staffBytes)];
        foreach (['contract-max-templates.json','reservation-max-templates.json','retained-max-templates.json'] as $file) {
            $entries+=Support::decode(file_get_contents(__DIR__.'/defaults/'.$file));
        }
        ksort($entries);
        $this->db->beginTransaction();
        try {
            $rows=$this->db->query("SELECT * FROM integration_template_catalog WHERE channel='max' ORDER BY source_key FOR UPDATE")
                ->fetchAll(\PDO::FETCH_ASSOC);
            $current=array_column($rows,null,'source_key');
            if (count($entries)!==34 || array_diff_key($entries,$current) || array_diff_key($current,$entries)) {
                throw new \RuntimeException('APPROVED_MAX_CATALOG_MISMATCH');
            }
            $update=$this->db->prepare("UPDATE integration_template_catalog
                SET body_text=?, format=?, source_sha256=?, version=version+1, actor_label=?, changed_at=?
                WHERE channel='max' AND source_key=?");
            $result=[];
            foreach ($entries as $key=>$entry) {
                $old=$current[$key];
                if (($key===ContractSecurityMax::KEY && ContractSecurityMax::matchesTemplate($old))
                    || ($key===AccountantPackageMax::KEY && AccountantPackageMax::matchesTemplate($old))) {
                    $result[$key]=['changed'=>false,'version'=>(int)$old['version']];
                    continue;
                }
                self::validateFormat($entry['format'],$entry['body'],self::messageVariables('max',$key));
                $sha=$key==='staffHandoff'?hash('sha256',$staffBytes):$old['source_sha256'];
                $changed=$old['format']!==$entry['format'] || $old['body_text']!==$entry['body'] || $old['source_sha256']!==$sha;
                if ($apply && $changed) {
                    $this->rememberVersion($old);
                    $update->execute([$entry['body'],$entry['format'],$sha,'По поручению владельца: оформление MAX',date(DATE_ATOM),$key]);
                }
                $result[$key]=['changed'=>$changed,'version'=>(int)$old['version']+(int)($apply && $changed)];
            }
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }
    public function installReservationNotices(bool $apply=true): array
    {
        $definitions=Support::decode(file_get_contents(__DIR__.'/defaults/reservation-max-templates.json'));
        $result=[];
        $this->db->beginTransaction();
        try {
            $select=$this->db->prepare("SELECT * FROM integration_template_catalog WHERE channel='max' AND source_key=? FOR UPDATE");
            $update=$this->db->prepare("UPDATE integration_template_catalog SET body_text=?,trigger_text=?,format=?,version=version+1,
                actor_label=?,changed_at=?,source_sha256=?,source_path=?,source_version='reservation-notices-v2'
                WHERE channel='max' AND source_key=?");
            foreach (\Modules\Rental\BookingNotices::TEMPLATES as [$key,$title]) {
                $definition=$definitions[$key];
                $entry=['id'=>$key,'name'=>$title,'format'=>$definition['format'],'body'=>$definition['body'],'version'=>1,'trigger'=>$definition['trigger']];
                self::validateFormat($entry['format'],$entry['body'],self::messageVariables('max',$key));
                $sha=hash('sha256',Support::canonical($entry));
                $select->execute([$key]);
                $old=$select->fetch(\PDO::FETCH_ASSOC);
                if (!$old) {
                    if ($apply) $this->importRecord('max',$key,$entry,$sha,'bundled','reservation-notices-v2');
                    $result[$key]=['status'=>$apply?'installed':'missing','version'=>$apply?1:null];
                } elseif ($this->untouchedReservationDefault($old,$key,$title)) {
                    if ($apply) {
                        $this->rememberVersion($old);
                        $original=$this->store->put('catalog-records',Support::json($entry));
                        $update->execute([$entry['body'],$entry['trigger'],$entry['format'],'Каталог системы',date(DATE_ATOM),$sha,$original,$key]);
                    }
                    $result[$key]=['status'=>$apply?'upgraded':'can_upgrade','version'=>$apply?2:1];
                } else {
                    $result[$key]=['status'=>'preserved','version'=>(int)$old['version']];
                }
            }
            $this->db->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $error;
        }
    }

    private function untouchedReservationDefault(array $old,string $key,string $title): bool
    {
        $body=$title.". Заявка №{number}. Помещение: {context}. Клиент: {party}. Срок: {effective}. Ответственный: {manager}. {reason}";
        $legacy=['id'=>$key,'name'=>$title,'format'=>'plain','body'=>$body,'version'=>1];
        return (int)$old['version']===1 && $old['source_version']==='reservation-notices-v1'
            && hash_equals(hash('sha256',Support::canonical($legacy)),$old['source_sha256'])
            && $old['source_id']===$key && $old['source_path']==='catalog-records/'.hash('sha256',Support::json($legacy))
            && $old['body_text']===$body && $old['format']==='plain' && $old['name']===$title
            && $old['trigger_text']==='' && $old['subject']==='' && $old['title']===''
            && $old['recipients_policy']===null && $old['attachment_policy']===null && $old['tone']===null
            && $old['actor_label']===null && $old['changed_at']===null;
    }

    private function rememberVersion(array $old): void
    {
        $path=$this->store->put('catalog-history',Support::json($old));
        $this->db->prepare('INSERT INTO integration_template_history(channel,source_key,version,format,subject,body_text,title,actor_label,changed_at,original_path) VALUES(?,?,?,?,?,?,?,?,?,?)')
            ->execute([$old['channel'],$old['source_key'],$old['version'],$old['format'],$old['subject'],$old['body_text'],$old['title'],$old['actor_label'],$old['changed_at'],$path]);
    }

    private function importRecord(string $channel,string $key,array $record,string $sha,string $sourcePath,string $sourceVersion): void {
        if(!preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$key)||!is_int($record['version']??1)||($record['version']??1)<1)throw new \RuntimeException('CATALOG_ID_VERSION_INVALID');Support::business($record);$q=$this->db->prepare('SELECT source_sha256 FROM integration_template_catalog WHERE channel=? AND source_key=?');$q->execute([$channel,$key]);$old=$q->fetchColumn();if($old!==false){if($old!==$sha)throw new \RuntimeException('CATALOG_RECONCILIATION_REQUIRED');return;}$original=$this->store->put('catalog-records',Support::json($record));$this->db->prepare('INSERT INTO integration_template_catalog(channel,source_key,source_id,name,trigger_text,format,subject,body_text,title,tone,recipients_policy,attachment_policy,version,actor_label,changed_at,source_sha256,source_path,source_version) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$channel,$key,(string)($record['id']??$key),(string)($record['name']??$key),(string)($record['trigger']??$record['when']??''),$channel==='top'?'plain':($record['format']??'plain'),$record['subject']??'',$record['body']??$record['message']??'',$record['title']??'',$record['tone']??null,$record['recipientsPolicy']??null,$record['attachmentPolicy']??null,$record['version']??1,$record['actor']??null,$record['changedAt']??null,$sha,$original,$sourceVersion]);
        // Source history remains immutable and individually addressable; it is never applied twice.
        foreach($record['previousVersions']??[] as $version){if(!is_array($version)||!is_int($version['version']??null))throw new \RuntimeException('CATALOG_HISTORY_INVALID');$original=$this->store->put('catalog-history',Support::json($version));$this->db->prepare('INSERT INTO integration_template_history(channel,source_key,version,format,subject,body_text,title,actor_label,changed_at,original_path) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$channel,$key,$version['version'],$version['format']??($record['format']??'plain'),$version['subject']??'',$version['body']??$version['message']??'',$version['title']??'',$version['actor']??null,$version['changedAt']??null,$original]);}
    }
    public function save(string $channel,string $key,int $expected,array $input,string $actor): array {
        if(!in_array($channel,['email','max','top'],true))throw new \InvalidArgumentException('UNKNOWN_TEMPLATE_CHANNEL');$this->db->beginTransaction();try{$q=$this->db->prepare('SELECT * FROM integration_template_catalog WHERE channel=? AND source_key=? FOR UPDATE');$q->execute([$channel,$key]);$old=$q->fetch(\PDO::FETCH_ASSOC);if(!$old)throw new \RuntimeException('CATALOG_ENTRY_NOT_FOUND');if((int)$old['version']!==$expected)throw new \RuntimeException('REVISION_CONFLICT');$format=$channel==='max'?(string)($input['format']??'plain'):'plain';$body=str_replace(["\r\n","\r"],"\n",(string)($input['body']??''));$subject=$channel==='email'?(string)($input['subject']??''):'';$title=$channel==='top'?(string)($input['title']??''):'';
            if($channel==='top'){if(array_diff(array_keys($input),['title','body']))throw new \InvalidArgumentException('TEMPLATE_TEXT_ONLY');self::validateText($title,self::TOP_VARS,80,true);self::validateText($body,self::TOP_VARS,600);}else {if(array_diff(array_keys($input),$channel==='max'?['format','body']:['subject','body']))throw new \InvalidArgumentException('TEMPLATE_TEXT_ONLY');self::validateFormat($format,$body,self::messageVariables($channel,$key));if($channel==='email')self::validateText($subject,self::MESSAGE_VARS,200,true);}
            if($format===$old['format']&&$subject===$old['subject']&&$body===str_replace(["\r\n","\r"],"\n",$old['body_text'])&&$title===$old['title']){$this->db->commit();return ['channel'=>$channel,'key'=>$key,'version'=>$expected,'unchanged'=>true];}
            $this->rememberVersion($old);$this->db->prepare('UPDATE integration_template_catalog SET format=?,subject=?,body_text=?,title=?,version=version+1,actor_label=?,changed_at=? WHERE channel=? AND source_key=?')->execute([$format,$subject,$body,$title,$actor,date(DATE_ATOM),$channel,$key]);$this->db->commit();return ['channel'=>$channel,'key'=>$key,'version'=>$expected+1];
        }catch(\Throwable $e){if($this->db->inTransaction())$this->db->rollBack();throw $e;}
    }
    public static function preview(string $channel,array $entry,array $values=[]): array {
        $reservationHtml=$channel==='max' && ($entry['format'] ?? '')==='html'
            && isset(self::RESERVATION_VARS[$entry['source_key'] ?? '']);
        if ($reservationHtml) {
            foreach (['manager','actor'] as $person) {
                $words=preg_split('/\s+/u',trim($values[$person] ?? ''),2);
                $values[$person]=$words[0] ?? '';
            }
            $prefix="\nКомментарий · ";
            $line=$values['reservationCommentLine'] ?? '';
            $comment=str_starts_with($line,$prefix)?substr($line,strlen($prefix)):'';
            $values['reservationCommentPrefix']=$comment===''?'':$prefix;
            $values['reservationComment']=$comment;
        }
        $base=$channel==='top'?['title'=>'Изменения сохранены','text'=>'Документ направлен на проверку.','record'=>'Договор №173','section'=>'Согласования','why'=>'Требуется юридическая проверка.','actor'=>'Исполнитель','number'=>'2','party'=>'Контрагент','template'=>$entry['name']??'Шаблон','details'=>'Помещение 204']:array_fill_keys(self::MESSAGE_VARS,'');
        $base=array_replace($base,$values);
        $format=$entry['format'] ?? 'plain';
        $escape=match($channel==='max'?$format:'plain') {
            'html'=>static fn(string $value): string=>htmlspecialchars($value,ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8'),
            'markdown'=>static fn(string $value): string=>preg_replace('/([\\\\`*_{}\[\]()#+.!|>~^<+=-])/u','\\\\$1',$value),
            default=>static fn(string $value): string=>$value,
        };
        $max=$channel==='max';
        $replace=static fn(string $text): string=>preg_replace_callback('/\{([^{}]+)\}/u',static function(array $match) use($base,$escape,$format,$max): string {
            $key=$match[1];
            $value=is_scalar($base[$key] ?? null)?(string)$base[$key]:'';
            if (!$max) return $escape($value);
            return match ($key) {
                'contractObjectLines' => ContractSubmitMax::objectLines($value,$format,$escape),
                ContractSecurityMax::TOKEN => ContractSecurityMax::render($value,$format,$escape),
                AccountantPackageMax::TOKEN => AccountantPackageMax::render($value,$format,$escape),
                default => $escape($value),
            };
        },$text);
        $body=$replace($entry['body_text']);
        if ($reservationHtml) $body=str_replace('<b></b>','',$body);
        $title=$replace($entry['title'] ?? '');
        $subject=$replace($entry['subject'] ?? '');
        if ($channel==='top') {
            if($title===''||$body===''||mb_strlen($title)>120||mb_strlen($body)>1000) throw new \InvalidArgumentException('EXPANDED_TEMPLATE_LIMIT');
        } elseif (mb_strlen($body)>4000||mb_strlen($subject)>200||preg_match('/[\r\n]/',$subject)) {
            throw new \InvalidArgumentException('EXPANDED_TEMPLATE_LIMIT');
        }
        return compact('title','subject','body','format');
    }
}
