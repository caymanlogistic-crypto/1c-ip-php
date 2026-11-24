<?php
declare(strict_types=1);
namespace Modules\Rental;

use Modules\Integrations\TemplateCatalog;
use PDO;

/** Read-only addressed projection over the existing tasks and current text catalog. */
final class WorkflowNotices
{
    public const EPOCH='ade3db93-e169-11f1-918c-00155dadf05b';
    public const BASELINE=1008;

    /** A restored epoch has an explicit persisted boundary; never mute at current MAX(id). */
    public static function baselineFor(string $epoch,?array $restored):array
    {
        if($epoch!==self::EPOCH&&($restored['epoch']??null)===$epoch
            &&is_int($restored['event_floor']??null)&&$restored['event_floor']>=0)
            return ['epoch'=>$epoch,'event_floor'=>$restored['event_floor']];
        return ['epoch'=>self::EPOCH,'event_floor'=>self::BASELINE];
    }

    public static function projection(PDO $db,array $user,array $tasks,array $allowedPages): array
    {
        $catalog=[];
        foreach($db->query("SELECT source_key,title,body_text,version FROM integration_template_catalog WHERE channel='top'")->fetchAll(PDO::FETCH_ASSOC) as $row)$catalog[$row['source_key']]=$row;
        $epoch=(string)$db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn();
        $restored=null;
        if($epoch!==self::EPOCH){
            $raw=$db->query("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_runtime' AND source_key='active'")->fetchColumn();
            $restored=$raw===false?null:json_decode($raw,true,32,JSON_THROW_ON_ERROR);
        }
        $baseline=self::baselineFor($epoch,$restored);
        $events=[];
        $best=self::selectTasks($tasks,$user,$allowedPages);
        foreach($best as $task){
            if(!in_array($task['entity'],['contract','document'],true))continue;
            $event=WorkflowEvents::latest($db,$task['entity'],(int)$task['id']);
            if(!$event||$event['target_role']!==$user['role'])continue;
            $events[]=self::task($task,$event,$catalog);
        }
        $sender=null;$command=$_SESSION['rental_top_command']??null;
        // Retain the one existing pending command until overwrite/logout. Rendering or an aborted
        // registry response is not proof of client consumption; client fingerprints deduplicate it.
        if(is_string($command)){
            $q=$db->prepare("SELECT id,source_json,created_at FROM rental_events WHERE JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.epoch'))=(SELECT epoch FROM rental_runtime_context WHERE id=1) AND JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.command_key'))=? ORDER BY id DESC LIMIT 1");
            $q->execute([$command]);$row=$q->fetch(PDO::FETCH_ASSOC);$event=$row?WorkflowEvents::decode($row):null;
            if($event&&in_array($event['entity_kind'],['contract','document'],true)&&(int)$event['actor_id']===(int)$user['id']){
                $table=$event['entity_kind']==='contract'?'contracts':'rental_documents';
                $q=$db->prepare('SELECT status FROM '.$table.' WHERE id=?');$q->execute([$event['entity_id']]);$status=$q->fetchColumn();
                $commandPage=substr(\App\PagePolicy::commandPage($event['action']),7);
                if(in_array($commandPage,$allowedPages,true)&&is_string($status)&&self::visibleContext($event['entity_kind'],$status,$allowedPages))
                    $sender=self::sender($event,$catalog);
            }
        }
        return ['schema'=>1,'project'=>WorkflowEvents::PROJECT,'surface'=>'php-rental','identity'=>(string)$user['id'],
            'epoch'=>$epoch,'bootstrap_epoch'=>$baseline['epoch'],'bootstrap_event_id'=>$baseline['event_floor'],
            'catalog'=>$catalog,'events'=>$events,'sender'=>$sender];
    }

    /** Filter before event/catalog DTO construction; aggregate counts grant no record access. */
    public static function selectTasks(array $tasks,array $user,array $allowedPages): array
    {
        $best=[];$rank=['red'=>0,'yellow'=>1,'blue'=>2,'green'=>3];
        foreach($tasks as $task){
            if($task['role']!==$user['role']||!in_array($task['page'],$allowedPages,true))continue;
            $key=$task['page'].':'.$task['entity'].':'.$task['id'];
            if(!isset($best[$key])||$rank[$task['severity']]<$rank[$best[$key]['severity']])$best[$key]=$task;
        }
        return array_values($best);
    }

    public static function visibleContext(string $kind,string $status,array $allowedPages): bool
    {
        $pages=$kind==='document'&&in_array($status,['signed','applied'],true)?['contracts-active']:Access::statusPages($status);
        return (bool)array_intersect($pages,$allowedPages);
    }

    public static function task(array $task,array $event,array $catalog): array
    {
        $variables=self::variables($event)+['section'=>$task['page'],'why'=>$task['text'],'text'=>$task['text']];
        // Task severity is authoritative; a text template cannot recolor a business cause.
        $notice=self::notice('task:'.$event['event_id'].':'.$task['key'],(int)$event['event_id'],$task['severity'],'task-received',$variables,
            [(int)$event['entity_version'],$task['legal_sla']['state']??null],$catalog);
        if ($task['page']!=='approvals') return $notice;

        $message=match ($event['event_kind']) {
            'submit'=>'Согласовать и подготовить пакет',
            'resubmit'=>'Проверьте внесённые исправления и согласуйте документ.',
            'legalApprove'=>'Проверьте и подтвердите комплект.',
            'lawyerReturn'=>'Доработайте документ по замечанию юриста.',
            'accountantReturn'=>'Доработайте документ по замечанию бухгалтерии.',
            'package'=>'Бухгалтерия согласовала пакет. Ожидается отправка клиенту.',
            'handed'=>'Документы переданы клиенту. Получите подписи или оплату.',
            default=>null,
        };
        if ($message===null) return $notice;

        $sla=$task['legal_sla']??[];
        if (($sla['state']??null)==='UNKNOWN') $message=rtrim($message,'.').'. Срок проверки не подтверждён.';
        if (($sla['working_seconds']??0)>=21600) $message=rtrim($message,'.').'. Истекли 6 рабочих часов юридической проверки.';
        return self::approvalLayout($notice,$event,$message);
    }

    public static function sender(array $event,array $catalog): ?array
    {
        $key=match($event['event_kind']){
            'submit','resubmit'=>$event['entity_kind']==='document'?'agreement-submitted':'document-submitted',
            'legalApprove'=>'document-approved','lawyerReturn','accountantReturn'=>'document-returned',
            'package'=>'packet-confirmed',default=>null};
        if ($key===null) return null;
        $notice=self::notice('sender:'.$event['event_id'],(int)$event['event_id'],'green',$key,
            self::variables($event)+['section'=>'Согласования','why'=>$event['reason'],'text'=>in_array($event['event_kind'],['submit','resubmit'],true)?$event['party_name']:$event['text']],
            [(int)$event['entity_version']],$catalog);
        $message=match ($event['event_kind']) {
            'submit'=>'Направлено юристу на проверку.',
            'resubmit'=>'Повторно направлено юристу на проверку.',
            'legalApprove'=>'Согласовано юристом и передано бухгалтеру.',
            'lawyerReturn'=>'Возвращено менеджеру на доработку.',
            'accountantReturn'=>'Возвращено юристу на доработку.',
            'package'=>'Пакет подтверждён бухгалтерией.',
        };
        return self::approvalLayout($notice,$event,$message);
    }

    private static function approvalLayout(array $notice,array $event,string $message): array
    {
        $kind=$event['entity_kind']==='document'?'ДС':'Договор';
        $number=self::displayNumber($event['record_number'],$kind);
        $record=$kind.($number===''?'':' №'.$number);
        if ($kind==='ДС') {
            $contract=self::displayNumber($event['contract_number']??'','Договор');
            if ($contract!=='') $record.=' к договору №'.$contract;
        }
        $party=trim(preg_replace('/\s+/u',' ',$event['party_name']));
        $reason=in_array($event['event_kind'],['lawyerReturn','accountantReturn'],true)?trim($event['reason']??''):'';

        // Older tabs have already stored fingerprints that contain the former catalog-rendered text.
        $notice['dedupe_text']=$notice['text'];
        $notice['title']=$record.($party===''?'':'. '.$party);
        $notice['text']=$message.($reason===''?'':' '.$reason);
        return $notice;
    }

    private static function displayNumber(string $number,string $prefix): string
    {
        return preg_replace('/^(?:'.preg_quote($prefix,'/').'(?=\s|№|\d|$)\s*)?№?\s*/ui','',trim($number));
    }

    private static function variables(array $event): array
    {
        return ['record'=>($event['entity_kind']==='document'?'Документ':'Договор').' №'.$event['record_number'],
            'actor'=>$event['actor_name'],'number'=>$event['record_number'],'party'=>$event['party_name'],
            'title'=>'Задача по документу','details'=>'','template'=>''];
    }

    private static function notice(string $id,int $eventId,string $severity,string $key,array $variables,array $version,array $catalog): array
    {
        $entry=$catalog[$key]??null;
        $render=$entry?TemplateCatalog::preview('top',$entry,$variables):null;
        return ['id'=>$id,'event_id'=>$eventId,'severity'=>$severity,'where'=>$variables['section'],
            'text'=>$render['body']??$variables['text'],'title'=>$render['title']??$variables['record'],
            'version'=>[$version,$entry?(int)$entry['version']:null],'event_key'=>$key,'variables'=>$variables,
            'catalog_state'=>$entry?'KNOWN':'UNKNOWN'];
    }
}
