<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Audit,DB};
use PDO;
use RuntimeException;

/** Reversible removal from current work. Business rows and delivery evidence are retained. */
final class WorkspaceReset
{
    private const WORK=['draft','legal','accounting','returned','approved','sent','cancelled'];
    public static function hidden(array $row): bool { return isset(json_decode($row['source_json'],true)['workspace_cleared']); }
    public static function visibleSql(string $alias): string
    {
        if(!preg_match('/^[a-z_]+$/iD',$alias))throw new RuntimeException('Invalid workspace alias');
        return "JSON_CONTAINS_PATH($alias.source_json,'one','$.workspace_cleared')=0";
    }
    private static function state(PDO $p): array
    {
        $state=ZeroPoint::capture($p,$p->inTransaction());unset($state['context']);
        // These are not changed, but new signatures or transport activity invalidate a preview/return.
        foreach(['rental_files','rental_delivery_intents','integration_native_intents','integration_native_receipts','messaging_attempts','messaging_receipts'] as $table) {
            $rows=$p->query('SELECT * FROM '.$table.($p->inTransaction()?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC);
            usort($rows,static fn($a,$b)=>strcmp(ZeroPoint::json($a),ZeroPoint::json($b)));$state['protected'][$table]=$rows;
        }
        return $state;
    }
    private static function exists(PDO $p,string $sql,array $args): bool { $q=$p->prepare($sql);$q->execute($args);return (bool)$q->fetchColumn(); }
    private static function reason(PDO $p,string $kind,array $row): ?string
    {
        $id=(int)$row['id'];$raw=json_decode($row['source_json'],true) ?: [];
        if(!empty($raw['imported']) || !empty($raw['zeroPointScheduled']))return 'Исходный подтверждённый договор';
        if(!empty($row['signed']) || !empty($row['signed_ours']) || !empty($row['signed_theirs']) || !empty($row['applied']))return 'Есть подпись или действующие условия';
        if(self::exists($p,"SELECT 1 FROM rental_files WHERE entity_type=? AND entity_id=? AND kind IN ('signed-contract','signed-addendum','signed-return-act','removed-return-act') LIMIT 1",[$kind,$id]))return 'Есть подписанный файл';
        if(self::exists($p,"SELECT 1 FROM rental_delivery_intents WHERE entity_type=? AND entity_id=? AND status NOT IN ('sent','failed','blocked','cancelled') LIMIT 1",[$kind,$id]))return 'Требуется сверить незавершённую отправку';
        if($kind==='contract') {
            if(self::exists($p,'SELECT 1 FROM invoices WHERE contract_id=? LIMIT 1',[$id]))return 'Есть финансовые документы';
            if(self::exists($p,'SELECT 1 FROM rental_object_holds WHERE contract_id=? LIMIT 1',[$id]))return 'Есть история возврата помещений';
        }
        return null;
    }
    public static function preview(): array
    {
        ZeroPoint::requireAdmin();$p=DB::pdo();$state=self::state($p);$changes=[];$blocked=[];$selected=[];
        $contracts=$p->query('SELECT * FROM contracts ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $documents=$p->query('SELECT * FROM rental_documents ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        foreach($contracts as $row) {
            if(!in_array($row['status'],self::WORK,true) || ContractDraft::deleted($row))continue;
            $reason=self::reason($p,'contract',$row);
            foreach($documents as $d)if((int)$d['contract_id']===(int)$row['id'] && !self::hidden($d)) {
                $reason ??= !in_array($d['status'],self::WORK,true)?'Есть подписанное допсоглашение':self::reason($p,'document',$d);
            }
            if($reason){$blocked[]=['label'=>'Договор №'.$row['number'],'reason'=>$reason];continue;}
            $changes[]=['table'=>'contracts','before'=>$row,'label'=>'Договор №'.$row['number']];$selected[(int)$row['id']]=true;
        }
        $docs=[];
        foreach($documents as $row) {
            if(!in_array($row['status'],self::WORK,true) || self::hidden($row))continue;
            $reason=self::reason($p,'document',$row);
            // A document of a rejected parent is not removed independently from that parent.
            foreach($contracts as $c)if((int)$c['id']===(int)$row['contract_id'] && in_array($c['status'],self::WORK,true) && !isset($selected[(int)$c['id']]))$reason ??='Сначала требуется проверить основной договор';
            if($reason){$blocked[]=['label'=>'ДС №'.$row['number'],'reason'=>$reason];continue;}
            $changes[]=['table'=>'rental_documents','before'=>$row,'label'=>'ДС №'.$row['number']];$docs[(int)$row['id']]=true;
        }
        foreach($p->query("SELECT * FROM reservations WHERE status='active' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if(($row['contract_id']===null && $row['document_id']===null) || isset($selected[(int)$row['contract_id']]) || isset($docs[(int)$row['document_id']]))
                $changes[]=['table'=>'reservations','before'=>$row,'label'=>'Бронь #'.$row['id']];
        }
        return ['digest'=>ZeroPoint::digest($state),'changes'=>$changes,'blocked'=>$blocked];
    }
    public static function run(array $plan,string $key,string $confirmation): array
    {
        ZeroPoint::requireAdmin();if($confirmation!=='ОЧИСТИТЬ')throw new RuntimeException('Введите ОЧИСТИТЬ для подтверждения.',422);
        return Service::command('workspace.reset',['command_key'=>$key,'plan'=>$plan]);
    }
    public static function execute(PDO $p,array $actor,string $action,array $in): array
    {
        ZeroPoint::requireAdmin();$key=(string)$in['command_key'];
        if($action==='workspace.undo')return self::undo($p,$actor,$in);
        if($action==='workspace.remove-test-object')return self::removeTestObject($p,$actor,$key);
        $plan=$in['plan'] ?? [];$fresh=self::preview();
        if(!hash_equals(ZeroPoint::digest($fresh),ZeroPoint::digest($plan)))throw new RuntimeException('Рабочие данные изменились. Повторите предварительную проверку.',409);
        if(!$fresh['changes'])throw new RuntimeException('Нет рабочих данных для очистки.',409);
        $path=ZeroPoint::directory().'/workspace/'.$key.'.json';
        $sha=ZeroPoint::write($path,['created_at'=>date(DATE_ATOM),'changes'=>$fresh['changes'],'state'=>self::state($p)]);
        foreach($fresh['changes'] as $change) {
            $row=$change['before'];$raw=json_decode($row['source_json'],true) ?: [];
            $raw['workspace_cleared']=['key'=>$key,'at'=>date(DATE_ATOM),'actor_id'=>(int)$actor['id']];
            $p->prepare('UPDATE '.$change['table']." SET status='cancelled',source_json=?,version=version+1 WHERE id=?")->execute([Importer::json($raw),$row['id']]);
        }
        $fact=['backup_sha256'=>$sha,'after_digest'=>ZeroPoint::digest(self::state($p)),'count'=>count($fresh['changes']),'created_at'=>date(DATE_ATOM)];
        $p->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('workspace_reset',?,?,1)")->execute([$key,Importer::json($fact)]);
        Service::event($p,$actor,$action,'Очищены рабочие записи: '.count($fresh['changes']));
        Audit::record($action,'workspace',null,null,['key'=>$key,'count'=>$fact['count'],'backup_sha256'=>$sha]);
        return ['id'=>0,'message'=>'Рабочие данные очищены. Резерв сохранён.','operation'=>$key,'count'=>$fact['count']];
    }
    private static function undo(PDO $p,array $actor,array $in): array
    {
        $target=(string)($in['target'] ?? '');
        if(!preg_match('/^[a-f0-9]{32}$/D',$target))throw new RuntimeException('Неизвестная операция.',404);
        $q=$p->prepare("SELECT fact_json FROM rental_facts WHERE namespace='workspace_reset' AND source_key=? FOR UPDATE");$q->execute([$target]);$fact=json_decode($q->fetchColumn() ?: '{}',true);
        if(!$fact || !empty($fact['undone']))throw new RuntimeException('Возврат недоступен.',409);
        if(!hash_equals($fact['after_digest'],ZeroPoint::digest(self::state($p))))throw new RuntimeException('После очистки данные изменились. Автоматический возврат остановлен, резерв сохранён для сверки.',409);
        $path=ZeroPoint::directory().'/workspace/'.$target.'.json';
        if(!is_file($path) || !hash_equals($fact['backup_sha256'],hash_file('sha256',$path)))throw new RuntimeException('Резерв не прошёл проверку.',409);
        $backup=Importer::decodeExact(file_get_contents($path));
        foreach($backup['changes'] as $change) {
            $row=$change['before'];
            if(!in_array($change['table'],['contracts','rental_documents','reservations','rental_objects'],true))throw new RuntimeException('Неизвестная таблица резерва.');
            if($change['table']==='rental_objects')$p->prepare('UPDATE rental_objects SET source_json=?,version=version+1 WHERE id=?')->execute([$row['source_json'],$row['id']]);
            else $p->prepare('UPDATE '.$change['table'].' SET status=?,source_json=?,version=version+1 WHERE id=?')->execute([$row['status'],$row['source_json'],$row['id']]);
        }
        $fact['undone']=date(DATE_ATOM);$p->prepare("UPDATE rental_facts SET fact_json=? WHERE namespace='workspace_reset' AND source_key=?")->execute([Importer::json($fact),$target]);
        Service::event($p,$actor,'workspace.undo','Возвращены рабочие данные из резерва '.$target);
        Audit::record('workspace.undo','workspace',null,null,['target'=>$target]);
        return ['id'=>0,'message'=>'Рабочие данные возвращены.'];
    }
    private static function removeTestObject(PDO $p,array $actor,string $key): array
    {
        if(PHP_SAPI!=='cli')throw new RuntimeException('Разовая операция доступна только оператору.',403);
        $row=Service::get($p,'rental_objects',9000001);
        if($row['name']!=='ТЕСТ MAX 18.11 — активность' || (int)$row['active']!==0 || (string)$row['floor']!=='2' || bccomp($row['area'],'0.01',4)!==0)throw new RuntimeException('Тестовый объект не совпал.',409);
        if(self::hidden($row))return ['id'=>9000001,'message'=>'Тестовый объект уже убран.'];
        if(self::exists($p,"SELECT 1 FROM contract_objects co JOIN contracts c ON c.id=co.contract_id WHERE co.object_id=? AND c.status<>'cancelled' LIMIT 1",[$row['id']])
            || self::exists($p,"SELECT 1 FROM reservations WHERE object_id=? AND status='active' LIMIT 1",[$row['id']])
            || self::exists($p,'SELECT 1 FROM invoice_objects WHERE object_id=? LIMIT 1',[$row['id']])
            || self::exists($p,"SELECT 1 FROM document_terms dt JOIN rental_documents d ON d.id=dt.document_id WHERE dt.object_id=? AND d.status<>'cancelled' LIMIT 1",[$row['id']])
            || self::exists($p,'SELECT 1 FROM rental_object_holds WHERE object_id=? LIMIT 1',[$row['id']]))throw new RuntimeException('У тестового объекта есть рабочие связи.',409);
        $change=['table'=>'rental_objects','before'=>$row,'label'=>$row['name']];
        $sha=ZeroPoint::write(ZeroPoint::directory().'/workspace/'.$key.'.json',['created_at'=>date(DATE_ATOM),'changes'=>[$change],'state'=>self::state($p)]);
        $raw=json_decode($row['source_json'],true) ?: [];$raw['workspace_cleared']=['key'=>$key,'at'=>date(DATE_ATOM),'actor_id'=>(int)$actor['id']];
        $p->prepare('UPDATE rental_objects SET source_json=?,version=version+1 WHERE id=?')->execute([Importer::json($raw),$row['id']]);
        $fact=['backup_sha256'=>$sha,'after_digest'=>ZeroPoint::digest(self::state($p)),'count'=>1,'created_at'=>date(DATE_ATOM)];
        $p->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('workspace_reset',?,?,1)")->execute([$key,Importer::json($fact)]);
        Audit::record('workspace.remove-test-object','rental_object',$row['id'],$row,['workspace_cleared'=>$raw['workspace_cleared']]);
        Service::event($p,$actor,'workspace.remove-test-object','Убран тестовый объект '.$row['name']);
        return ['id'=>(int)$row['id'],'message'=>'Тестовый объект убран из реестра.','operation'=>$key];
    }
}
