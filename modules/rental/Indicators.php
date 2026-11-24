<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\DB;
final class Indicators {
    public static function documentTasks(array $document,bool $resubmitted=false,bool $sendError=false): array
    {
        if($document['status']==='signed')return !empty($document['applied'])?[]:array_map(
            static fn($role)=>['role'=>$role,'severity'=>'blue','text'=>'Подписано, ожидает применения'],['manager','director']);
        if (!in_array($document['status'],['legal','accounting','returned','approved','sent'],true)) return [];
        $raw=json_decode($document['source_json'] ?? '{}',true) ?: [];
        $title=$raw['type'] ?? ($document['kind']==='termination'?'Соглашение о досрочном расторжении':'Допсоглашение о пролонгации');
        $target=RegistryState::owner($document);
        $severity=$document['status']==='returned' || $sendError?'red':($document['status']==='legal' && $resubmitted?'blue':'yellow');
        $tasks=[['role'=>$target,'severity'=>$severity,'text'=>$title.(!empty($document['reason'])?': '.$document['reason']:'')]];
        $observers=['manager','director'];
        if (in_array($document['status'],['accounting','approved','returned'],true)) $observers=array_merge($observers,['lawyer','accountant']);
        foreach (array_unique($observers) as $role) if ($role!==$target) $tasks[]=['role'=>$role,'severity'=>'blue','text'=>$title.' · '.RegistryState::stage($document)];
        return $tasks;
    }

    private static function scope(?array $pages):?array {
        if ($pages !== null)return $pages;
        $u = \App\Auth::user();
        if (!$u || empty($u['grants_configured']) || $u['role'] === 'admin')return null;
        if (empty($u['workflow_role']))return [
        ];
        $q = DB::pdo()->prepare("SELECT page_key FROM role_page_grants WHERE role_id=? AND level>=1 AND page_key LIKE 'rental.%'");
        $q->execute([
            $u['role_id']
        ]);
        return array_map(fn($key) => substr($key, 7), $q->fetchAll(\PDO::FETCH_COLUMN));
    }
    public static function calendar():array {
        return SourceCalendar::registered()?->metadata() ?? [
            'admission' => 'UNAVAILABLE',
            'production_eligible' => false
        ];
    }
    public static function working(string $date, array $calendar = [
    ]):bool {
        $reference = SourceCalendar::registered() ?? throw new \RuntimeException('CALENDAR_REFERENCE_UNAVAILABLE');
        return $reference->isWorkingDate($date);
    }
    public static function workdays(string $from, string $to, array $calendar = [
    ]):int {
        $reference = SourceCalendar::registered() ?? throw new \RuntimeException('CALENDAR_REFERENCE_UNAVAILABLE');
        return $reference->workdays($from, $to);
    }
    public static function tasks(?string $today = null, ?string $time = null, ?array $allowedPages = null, ?ObjectReadModel $objects = null):array {
        if (!\App\Auth::can('rental.read'))return [
        ];
        $allowedPages = self::scope($allowedPages);
        $today??=date('Y-m-d');
        $time??=date('H:i:s');
        $calendar = [
        ];
        $tasks = [
        ];
        $allowedPages = $allowedPages === null?null:array_map(fn($key) => str_starts_with($key, 'rental.')?substr($key, 7):$key, $allowedPages);
        $aggregate = $allowedPages === null || (bool)array_intersect($allowedPages, [
            'errors',
            'operations',
            'summary',
            'journal'
        ]);
        $allow = fn(string $page) => $aggregate || in_array($page, $allowedPages ?? [
        ], true);
        $contractWhere = [
        ];
        if ($allow('approvals'))$contractWhere[] = "status IN ('legal','accounting','returned','approved','sent')";
        if ($allow('contracts-active'))$contractWhere[] = "status IN ('active','reconciling')";
        if ($allow('contracts-completed'))$contractWhere[] = "status IN ('ended','closed')";
        if ($allow('reconciliation'))$contractWhere[] = "status IN ('reconciling','ended')";
        $legalCalendar=null;$legalCalendarRead=false;
        $legalInfo=static function(string $kind,array $record)use(&$legalCalendar,&$legalCalendarRead,$today,$time):array {
            if($record['status']!=='legal')return ['state'=>'NOT_APPLICABLE'];
            if(!$legalCalendarRead){
                try{$legalCalendar=SourceCalendar::registered();}
                catch(\RuntimeException $error){if($error instanceof \PDOException)throw $error;}
                $legalCalendarRead=true;
            }
            $db=DB::pdo();$id=(int)$record['id'];
            $handoff=WorkflowEvents::latest($db,$kind,$id,true);
            if ($kind==='contract') $handoff=ContractRevision::legalHandoff($db,$id,$handoff);
            return LegalSla::evaluate($record,$handoff,$today.' '.$time.(strlen($time)===5?':00':''),$legalCalendar,$kind);
        };
        $add = function(string $entity, int $id, string $role, string $severity, string $page, string $text, ?int $contract = null, array $metadata=[])use(&$tasks) {
            $tasks[] = [
                'key' => $entity
                . ':'
                . $id
                . ':'
                . $role
                . ':'
                . $page,
                'entity' => $entity,
                'id' => $id,
                'role' => $role,
                'severity' => $severity,
                'page' => $page,
                'text' => $text,
                'contract_id' => $contract,
                'url' => '/rental/'
                . match($entity){'invoice'=>'invoice','document'=>'document','object'=>'object','reservation'=>'booking','party'=>'party',default=>'contract'}
                . '?id='
                . $id
            ]+$metadata;
        };
        if ($contractWhere)foreach (Controller::query('SELECT * FROM contracts WHERE '
        . implode(' OR ', $contractWhere)
        . ' ORDER BY id') as $c) {
            if(ContractDraft::deleted($c))continue;
            $id = (int)$c['id'];
            $raw = json_decode($c['source_json'], true);
            $managed = empty($raw['imported']);
            $status = $c['status'];
            if (RegistryState::managed($c) && in_array($status, [
                'legal',
                'accounting',
                'returned',
                'approved',
                'sent'
            ], true)) {
                $target = RegistryState::owner($c);
                $sla=$legalInfo('contract',$c);
                $severity=$status==='returned'?'red':($sla['severity']??'yellow');
                $message=$status==='returned'?'Исправить замечания: '.$c['reason']:($status==='sent'?'Получить подписи или оплату':'Согласовать и подготовить пакет');
                if(($sla['state']??null)==='UNKNOWN')$message.=' · Срок проверки не подтверждён';
                if(($sla['working_seconds']??0)>=21600)$message.=' · Истекли 6 рабочих часов юридической проверки';
                $add('contract',$id,$target,$severity,'approvals',$message,$id,['version'=>(int)$c['version'],'legal_sla'=>$sla]);
                foreach (['manager','lawyer','accountant','director'] as $role) {
                    if ($role !== $target) $add('contract', $id, $role, 'blue', 'approvals', 'На этапе «'
                        . RegistryState::stage($c).'»', );
                }
            }
            if ($status === 'active') {
                $q = DB::pdo()->prepare("SELECT COUNT(*) FROM rental_documents d WHERE d.contract_id=? AND d.kind='renewal' AND d.applied=0 AND d.status<>'cancelled' AND d.end_date>? AND (d.status IN ('sent','signed') OR EXISTS(SELECT 1 FROM reservations r WHERE r.document_id=d.id AND r.status='active'))");
                $q->execute([
                    $id,
                    $c['end_date']
                ]);
                if (!$q->fetchColumn() && $c['end_date']) {
                    $severity = LeaseRegistry::expiry($c['end_date'],$today);
                    if ($severity)$add('contract', $id, 'manager', $severity, 'contracts-active', 'Окончание '
                    . $c['end_date']
                    . ': выберите пролонгацию или завершение', $id);
                }
                if ($managed && !$c['signed']) {
                    $add('contract', $id, 'manager', 'yellow', 'contracts-active', 'Получить подписи обеих сторон', $id);
                    $add('contract', $id, 'director', 'blue', 'contracts-active', 'Ожидается подписанный экземпляр', $id);
                }
            }
            if (in_array($status, [
                'reconciling',
                'ended'
            ], true) && $c['finish_date']) {
                $noReturn=OccupiedBilling::noPhysicalReturn(DB::pdo(),$c);
                $text='Финальная сверка: нужны актуальные финансовая и юридическая проверки'
                    .($noReturn?'':' и акт возврата');
                foreach (['manager','lawyer','accountant','director'] as $role) {
                    $add('contract',$id,$role,'red','reconciliation',$text,$id);
                }
            }
        }
        if ($allow('invoices') || $allow('invoice-list') || $allow('payments')) {
        $invoiceRows=Controller::query("SELECT i.*,c.source_json contract_source FROM invoices i JOIN contracts c ON c.id=i.contract_id WHERE i.status<>'revoked' ORDER BY i.id");
        $reviews=InvoiceCalculation::reviewFacts(DB::pdo(),array_column(array_filter($invoiceRows,static fn($r)=>!empty($r['manual_review'])),'id'));
        $bankNow = BankPaymentRefresh::now(DB::pdo());
        foreach ($invoiceRows as $i) {
            $i = PaymentReview::attach(DB::pdo(),$i,$bankNow);
            $i['_calculation_review']=$reviews[(int)$i['id']] ?? null;
            $raw = json_decode($i['source_json'], true);
            $i['_delivery']=InvoiceDelivery::presentation(DB::pdo(),(int)$i['id']);
            $sendError=InvoiceFacts::view($i)['send_error'];
            $contractSource = json_decode($i['contract_source'],true) ?: [];
            if (($sendError && !empty($contractSource['sendError']))
                || ($i['manual_review'] && !empty($contractSource['financialReview']))) continue;
            $error = $i['manual_review'] || !empty($raw['issueError']) || !empty($raw['mismatch']) || $sendError;
            $severity = $error?'red':'';
            if (!$error) $severity = InvoiceFacts::overdueSeverity($i);
            $owners = !empty($raw['issueError']) ? ['accountant']
                : (!empty($raw['mismatch']) ? ['manager','accountant','director'] : ['manager','accountant']);
            $view=InvoiceFacts::view($i,$severity);
            if ($severity) foreach ($owners as $role) {
                $add('invoice',(int)$i['id'],$role,$severity,'invoices',$view['state'].': '.$view['explanation'],(int)$i['contract_id']);
            }
        }
        }
        // Signed documents drill down through contracts-active, not the aggregate registry grant.
        // Check the actual user's READ scope before selecting these otherwise private records.
        $user=\App\Auth::user();
        $signedVisible=$user&&in_array($user['role'],['manager','director'],true)
            &&($allowedPages===null||in_array('contracts-active',$allowedPages,true))
            &&(empty($user['grants_configured'])||(!empty($user['workflow_role'])&&\App\PagePolicy::level('rental.contracts-active',$user)>=1));
        if($signedVisible)foreach(Controller::query("SELECT * FROM rental_documents WHERE status='signed' AND applied=0") as $d){
            if($d['status']!=='signed'||!empty($d['applied']))continue;
            foreach(self::documentTasks($d) as $task)$add('document',(int)$d['id'],$task['role'],$task['severity'],'contracts-active',$task['text'],(int)$d['contract_id'],
                ['version'=>(int)$d['version'],'legal_sla'=>['state'=>'NOT_APPLICABLE']]);
        }
        if ($allow('approvals'))foreach (Controller::query("SELECT * FROM rental_documents WHERE status IN ('legal','accounting','returned','approved','sent')") as $d) {
            $raw=json_decode($d['source_json'],true) ?: [];
            $resubmitted=!empty($raw['resubmittedFromManager']);
            if ($d['status']==='legal' && !$resubmitted) {
                $prior=Controller::query('SELECT snapshot_json FROM rental_document_versions WHERE document_id=? ORDER BY version DESC LIMIT 1',[$d['id']])[0]['snapshot_json'] ?? null;
                $old=$prior?json_decode($prior,true):null;
                $resubmitted=$old && $old['status']==='returned' && $old['return_to']==='manager';
            }
            $delivery=Controller::query("SELECT status FROM rental_delivery_intents WHERE entity_type='document' AND entity_id=? AND operation='addendum-packet' ORDER BY CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(payload_json,'$.delivery_order')),'0') AS UNSIGNED) DESC LIMIT 1",[$d['id']])[0]['status'] ?? null;
            $sla=$legalInfo('document',$d);
            foreach (self::documentTasks($d,$resubmitted,!empty($raw['sendError']) || in_array($delivery,['blocked','failed','unknown'],true)) as $task) {
                if($d['status']==='legal'&&$task['role']==='lawyer'){
                    // Existing immediate red errors retain precedence over a legal timer.
                    if($task['severity']!=='red'&&($sla['reason']??null)!=='LEGAL_HANDOFF_NOT_RECORDED')$task['severity']=$sla['severity']??$task['severity'];
                    if(($sla['state']??null)==='UNKNOWN')$task['text'].=' · Срок проверки не подтверждён';
                    if(($sla['working_seconds']??0)>=21600)$task['text'].=' · Истекли 6 рабочих часов юридической проверки';
                }
                $add('document',(int)$d['id'],$task['role'],$task['severity'],'approvals',$task['text'],(int)$d['contract_id'],['version'=>(int)$d['version'],'legal_sla'=>$sla]);
            }
        }
        if ($allow('approvals') && ($actor=\App\Auth::user())) foreach (PartyLegalReview::work($actor) as $party) {
            if ($party['mine']) $add('party',(int)$party['id'],$party['owner'],'yellow','approvals',$party['comment']);
        }
        if ($allow('objects')) foreach (ObjectFacts::idleSignals($today,$objects) as $signal) {
            $add('object',$signal['id'],$signal['role'],$signal['severity'],'objects',$signal['text']);
        }
        return $tasks;
    }
    public static function counts(?array $user, ?array $allowedPages = null, ?array $computedTasks = null, ?ObjectReadModel $objects = null):array {
        $pages = [
        ];
        foreach (Controller::pages() as $page)$pages[$page] = [
            0,
            0,
            0,
            0
        ];
        if (!$user || !\App\Auth::can('rental.read'))return $pages;
        $allowedPages = self::scope($allowedPages);
        $role = $user['role'];
        $index = [
            'red' => 0,
            'yellow' => 1,
            'green' => 2,
            'blue' => 3
        ];
        $best = [
        ];
        $all = $computedTasks ?? self::tasks(null, null, $allowedPages);
        foreach ($all as $t) {
            if ($t['role'] !== $role && $role !== 'admin')continue;
            $key = $t['page']
            . ':'
            . $t['entity']
            . ':'
            . $t['id'];
            $rank = [
                'red' => 0,
                'yellow' => 1,
                'blue' => 2,
                'green' => 3
            ];
            if (!isset($best[$key]) || $rank[$t['severity']]<$rank[$best[$key]['severity']])$best[$key] = $t;
        }
        foreach ($best as $t) {
            $pages[$t['page']][$index[$t['severity']]]++;
            if ($t['page'] === 'invoices') {
                $pages['payments'][$index[$t['severity']]]++;
                $pages['invoice-list'][$index[$t['severity']]]++;
            }
        }
        if (($allowedPages === null || in_array('contracts', $allowedPages, true) || in_array('rental.contracts', $allowedPages, true)) && in_array($role, [
            'manager',
            'director',
            'admin'
        ], true))$pages['contracts'][3] = (int)DB::pdo()->query("SELECT COUNT(*) FROM reservations r WHERE status='active' AND document_id IS NULL AND contract_id IS NULL AND until_date>=CURRENT_DATE AND ".WorkspaceReset::visibleSql('r'))->fetchColumn();
        if (in_array($role, [
            'director',
            'admin'
        ], true)) {
            $ids = [
            ];
            foreach ($all as $t)if ($t['severity'] === 'red' && ($t['entity'] !== 'object' || $t['role'] === 'manager'))$ids[$t['key']] = true;
            $pages['errors'][0] = count($ids);
        }
        if ($allowedPages===null || in_array('objects',$allowedPages,true) || in_array('rental.objects',$allowedPages,true)) $pages['objects']=ObjectFacts::counts($objects);
        return $pages;
    }
}
