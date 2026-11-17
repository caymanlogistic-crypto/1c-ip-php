<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Audit,Auth,DB,StorageBudget};
use PDO;
use RuntimeException;

/** An explicit, private commercial snapshot. Never an SQL upload or a whole-database restore. */
final class ZeroPoint
{
    public const PROJECT='f6e21e3c-d974-4604-82fb-333da66559d2';
    public const TABLES=['cadastre','rental_objects','parties','party_bank_accounts','contracts',
        'rental_billing_parts','contract_objects','contract_terms','contract_part_terms','rental_documents',
        'document_terms','document_part_terms','invoices','invoice_objects','invoice_coverage','invoice_part_scope',
        'payments','payment_allocations','reservations','rental_object_holds','rental_object_periods',
        'rental_reviews','rental_contract_versions','rental_document_versions','rental_invoice_versions',
        'rental_payment_versions','rental_approvals','rental_packet_confirmations','rental_field_overrides',
        'rental_source_bindings'];
    private const STATE_KEYS=['reservations','documents','journal','workflowMessages','workflowOutbox',
        'alertKeys','invoiceSignals','operationGroups','ownerContractDrafts','p5LeaseExamples'];

    public static function requireAdmin(): array
    {
        $user=Auth::user();
        if (!$user) throw new RuntimeException('Требуется вход.',401);
        if (($user['role_id'] ?? '')!=='admin' || !Auth::can('identity.permissions'))
            throw new RuntimeException('Нулевая точка доступна только администратору.',403);
        return $user;
    }

    public static function directory(): string { return APP_ROOT.'/.local/zero-point'; }
    public static function json(mixed $value): string { return Importer::canonicalJson($value); }
    public static function digest(array $value): string { return hash('sha256',self::json($value)); }
    public static function eventFloor(PDO $db): int
    {
        $json=$db->query("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_runtime' AND source_key='active'")->fetchColumn();
        return (int)(json_decode($json ?: '{}',true)['event_floor'] ?? 0);
    }

    public static function write(string $path,array $value): string
    {
        $bytes=self::json($value);StorageBudget::target($path,strlen($bytes));
        if (!is_dir(dirname($path)) && !mkdir(dirname($path),0700,true)) throw new RuntimeException('Не удалось создать каталог резерва.');
        if (is_file($path) && !is_link($path) && hash_equals(hash('sha256',$bytes),hash_file('sha256',$path))) return hash('sha256',$bytes);
        $stream=fopen($path,'xb');if (!$stream) throw new RuntimeException('Файл уже существует или недоступен.');
        try {
            $offset=0;while ($offset<strlen($bytes)) {
                $written=fwrite($stream,substr($bytes,$offset));
                if ($written===false || $written===0) throw new RuntimeException('Не удалось записать резерв.');
                $offset+=$written;
            }
            if (!fflush($stream) || function_exists('fsync') && !fsync($stream)) throw new RuntimeException('Резерв не сохранён на диск.');
        } finally { fclose($stream); }
        @chmod($path,0600);
        if (!hash_equals(hash('sha256',$bytes),hash_file('sha256',$path))) throw new RuntimeException('Контроль записи резерва не пройден.');
        return hash('sha256',$bytes);
    }

    public static function schema(PDO $db): array
    {
        $schema=[];
        foreach (self::TABLES as $table) {
            $ddl=$db->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
            $schema[$table]=hash('sha256',preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl));
        }
        return $schema;
    }

    private static function factWhere(): string
    {
        return "namespace IN ('zero_point_data','invoice_signal','calculation_review','native_invoice_targets','billing_part_owner','billing_part_terms') OR (namespace='state' AND source_key IN ('".implode("','",self::STATE_KEYS)."'))";
    }

    public static function capture(PDO $db,bool $lock=false): array
    {
        $tables=[];
        foreach (self::TABLES as $table) {
            $rows=$db->query('SELECT * FROM `'.$table.'`'.($lock?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC);
            usort($rows,static fn($a,$b)=>strcmp(self::json($a),self::json($b)));$tables[$table]=$rows;
        }
        $facts=$db->query('SELECT namespace,source_key,fact_json,operative FROM rental_facts WHERE '.self::factWhere().($lock?' FOR UPDATE':''))->fetchAll(PDO::FETCH_ASSOC);
        usort($facts,static fn($a,$b)=>strcmp($a['namespace'].':'.$a['source_key'],$b['namespace'].':'.$b['source_key']));
        $context=$db->query('SELECT epoch,revision FROM rental_runtime_context WHERE id=1'.($lock?' FOR UPDATE':''))->fetch(PDO::FETCH_ASSOC);
        return ['tables'=>$tables,'facts'=>$facts,'context'=>$context];
    }

    public static function load(string $target='baseline'): array
    {
        if ($target==='baseline') {
            $index=self::directory().'/installed.json';
            if (!is_file($index)) throw new RuntimeException('Нулевая точка ещё не подготовлена.',409);
            $pin=json_decode(file_get_contents($index),true,512,JSON_THROW_ON_ERROR);
        } else {
            if (!preg_match('/^[a-f0-9]{32}$/D',$target)) throw new RuntimeException('Неизвестный резерв.',404);
            $receipt=self::directory().'/backups/'.$target.'.receipt.json';
            if (!is_file($receipt)) throw new RuntimeException('Резерв не найден.',404);
            $pin=json_decode(file_get_contents($receipt),true,512,JSON_THROW_ON_ERROR);
        }
        $sha=$pin['sha256'] ?? '';
        if (!preg_match('/^[a-f0-9]{64}$/D',$sha)) throw new RuntimeException('Повреждена квитанция нулевой точки.',409);
        $path=self::directory().'/'.$sha.'.json';
        if (!is_file($path) || is_link($path) || !hash_equals($sha,hash_file('sha256',$path)))
            throw new RuntimeException('Контрольная сумма нулевой точки не совпала.',409);
        $document=Importer::decodeExact(file_get_contents($path));
        if (($document['project_id'] ?? '')!==self::PROJECT || ($document['schema'] ?? 0)!==1
            || !in_array($document['kind'] ?? '',['COMMERCIAL_ZERO_POINT','COMMERCIAL_BEFORE_RESTORE'],true)
            || !self::tableSet($document['data']['tables'] ?? []))
            throw new RuntimeException('Неподдерживаемый состав нулевой точки.',409);
        Service::date($document['as_of'] ?? '');
        return $document+['sha256'=>$sha,'target'=>$target];
    }

    public static function install(array $document): array
    {
        if (PHP_SAPI!=='cli') throw new RuntimeException('Установка точки выполняется локальной командой.',403);
        if (is_file(self::directory().'/installed.json')) throw new RuntimeException('Существующая точка не перезаписывается.',409);
        // Validate without modifying any business table.
        self::validate(DB::pdo(),$document);
        $sha=self::digest($document);self::write(self::directory().'/'.$sha.'.json',$document);
        self::write(self::directory().'/installed.json',['sha256'=>$sha,'as_of'=>$document['as_of'],'project_id'=>self::PROJECT]);
        return ['sha256'=>$sha,'as_of'=>$document['as_of']];
    }

    public static function validate(PDO $db,array $document): void
    {
        if (($document['project_id'] ?? '')!==self::PROJECT || ($document['schema'] ?? 0)!==1
            || !in_array($document['kind'] ?? '',['COMMERCIAL_ZERO_POINT','COMMERCIAL_BEFORE_RESTORE'],true)
            || !self::tableSet($document['data']['tables'] ?? [])
            || self::json(self::schema($db))!==self::json($document['table_schema'] ?? []))
            throw new RuntimeException('Состав или схема БД отличаются от проверенной точки.',409);
        Service::date((string)($document['as_of'] ?? ''));
        foreach ($document['data']['tables'] as $table=>$rows) {
            $columns=$db->query('SHOW COLUMNS FROM `'.$table.'`')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($rows as $row) if (!is_array($row) || array_diff(array_keys($row),$columns))
                throw new RuntimeException('Неверные поля снимка '.$table,409);
        }
        foreach ($document['data']['facts'] ?? [] as $fact) {
            if (!in_array($fact['namespace'] ?? '',['zero_point_data','invoice_signal','calculation_review','native_invoice_targets','billing_part_owner','billing_part_terms'],true)
                && !(($fact['namespace'] ?? '')==='state' && in_array($fact['source_key'] ?? '',self::STATE_KEYS,true)))
                throw new RuntimeException('Снимок затрагивает системные настройки.',409);
        }
        $current=[];
        foreach ($db->query('SELECT namespace,collection,source_id,entity_type,relation_kind,entity_id FROM rental_source_bindings')->fetchAll(PDO::FETCH_ASSOC) as $binding) {
            $id=$binding['entity_id'];unset($binding['entity_id']);$current[self::json($binding)]=(string)$id;
        }
        foreach ($document['data']['tables']['rental_source_bindings'] as $binding) {
            $id=(string)$binding['entity_id'];$identity=array_intersect_key($binding,array_flip(['namespace','collection','source_id','entity_type','relation_kind']));
            $key=self::json($identity);
            if (isset($current[$key]) && $current[$key]!==$id) throw new RuntimeException('Исходные ID в целевой БД изменились. Требуется новое сопоставление.',409);
        }
    }

    private static function tableSet(array $tables): bool
    {
        return count($tables)===count(self::TABLES) && !array_diff(array_keys($tables),self::TABLES);
    }

    /** Determine parent-first order from actual FKs; unknown dependent tables close the operation. */
    private static function order(PDO $db): array
    {
        $relations=$db->query('SELECT TABLE_NAME,REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL')->fetchAll(PDO::FETCH_ASSOC);
        $parents=array_fill_keys(self::TABLES,[]);
        foreach ($relations as $relation) {
            [$child,$parent]=[$relation['TABLE_NAME'],$relation['REFERENCED_TABLE_NAME']];
            if (isset($parents[$parent]) && !isset($parents[$child])) throw new RuntimeException('Обнаружена новая зависимая таблица: '.$child,409);
            if (isset($parents[$child],$parents[$parent]) && $child!==$parent) $parents[$child][$parent]=true;
        }
        $ordered=[];
        while ($parents) {
            $ready=array_keys(array_filter($parents,static fn($p)=>!array_diff(array_keys($p),$ordered)));
            if (!$ready) throw new RuntimeException('Циклические зависимости снимка.',409);
            foreach ($ready as $name) { $ordered[]=$name;unset($parents[$name]); }
        }
        return $ordered;
    }

    public static function preview(string $target='baseline'): array
    {
        self::requireAdmin();$document=self::load($target);$db=DB::pdo();self::validate($db,$document);self::order($db);
        $live=DB::transaction(static fn(PDO $p)=>self::capture($p));
        return ['target'=>$target,'sha256'=>$document['sha256'],'as_of'=>$document['as_of'],
            'current_sha256'=>self::digest($live),'current_counts'=>array_map('count',$live['tables']),
            'target_counts'=>array_map('count',$document['data']['tables']),'limitations'=>$document['limitations'] ?? []];
    }

    public static function restore(array $input): array
    {
        $user=self::requireAdmin();$target=(string)($input['target'] ?? 'baseline');$document=self::load($target);
        if (($input['confirmation'] ?? '')!=='ВОССТАНОВИТЬ') throw new RuntimeException('Введите ВОССТАНОВИТЬ для подтверждения.',422);
        $key=(string)($input['command_key'] ?? '');
        if (!preg_match('/^[a-f0-9]{32}$/D',$key)) throw new RuntimeException('Не указан идентификатор операции.',422);
        $db=DB::pdo();if ($db->inTransaction()) throw new RuntimeException('Восстановление требует отдельной транзакции.',409);
        self::validate($db,$document);$order=self::order($db);
        $result=DB::transaction(function(PDO $p)use($input,$document,$user,$key,$target,$order) {
            $p->query('SELECT id FROM rental_number_guard WHERE id=1 FOR UPDATE')->fetchColumn();
            $query=$p->prepare("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_operation' AND source_key=? FOR UPDATE");$query->execute([$key]);
            if ($old=$query->fetchColumn()) {
                $prior=json_decode($old,true,512,JSON_THROW_ON_ERROR);
                if ($prior['actor_id']!==(int)$user['id'] || $prior['target_sha256']!==$document['sha256']) throw new RuntimeException('Идентификатор операции уже использован.',409);
                return $prior+['replayed'=>true];
            }
            // External operations and their receipts are never reset or replayed.
            foreach (['rental_delivery_intents','integration_native_intents','messaging_attempts'] as $table) {
                $column=$table==='integration_native_intents'?'state':'status';
                $pending=(int)$p->query("SELECT COUNT(*) FROM `$table` WHERE `$column` IN ('pending','claimed','prepared','dispatching','posting','sending','reserved')")->fetchColumn();
                if ($pending) throw new RuntimeException('Есть незавершённые внешние операции. Сначала требуется сверка: '.$table,409);
            }
            $live=self::capture($p,true);
            if (!hash_equals((string)($input['current_sha256'] ?? ''),self::digest($live))
                || !hash_equals((string)($input['sha256'] ?? ''),$document['sha256']))
                throw new RuntimeException('Данные изменились после предварительной проверки. Проверьте повторно.',409);
            $backup=['schema'=>1,'kind'=>'COMMERCIAL_BEFORE_RESTORE','project_id'=>self::PROJECT,
                'as_of'=>date('Y-m-d'),'created_at'=>date(DATE_ATOM),'table_schema'=>self::schema($p),'data'=>$live,
                'limitations'=>['Возврат данных не отменяет внешние отправки. Согласования проверяются заново.']];
            $backupSha=self::digest($backup);$backupPath=self::directory().'/'.$backupSha.'.json';
            self::write($backupPath,$backup);
            self::write(self::directory().'/backups/'.$key.'.receipt.json',[
                'sha256'=>$backupSha,'operation'=>$key,'created_at'=>$backup['created_at'],
                'target_sha256'=>$document['sha256'],'counts'=>array_map('count',$live['tables'])]);
            // Remember every discarded numeric identity before replacing the commercial graph.
            foreach ($live['tables'] as $table=>$rows) {
                $max=0;foreach ($rows as $row) if (isset($row['id']) && ctype_digit((string)$row['id'])) $max=max($max,(int)$row['id']);
                if (!$max) continue;
                $q=$p->prepare("SELECT fact_json FROM rental_facts WHERE namespace='zero_point_sequence' AND source_key=? FOR UPDATE");$q->execute([$table]);$prior=json_decode($q->fetchColumn() ?: '{}',true);
                $p->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('zero_point_sequence',?,?,0) ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)")
                    ->execute([$table,self::json(['max_id'=>max($max,(int)($prior['max_id'] ?? 0))])]);
            }
            $maxNumber=\App\BusinessIds::numberFloor($p);
            foreach ($live['tables']['contracts'] as $contract) if (preg_match('/^([0-9]+)/',$contract['number'],$match)) $maxNumber=max($maxNumber,(int)$match[1]);
            $p->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('zero_point_sequence','contract_number',?,0) ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)")->execute([self::json(['max_id'=>$maxNumber])]);
            foreach (array_reverse($order) as $table) {
                // A versioned part refers to its own predecessor; remove leaf versions first.
                $p->exec('DELETE FROM `'.$table.'`'.($table==='rental_billing_parts'?' ORDER BY version DESC':''));
            }
            foreach ($order as $table) {
                $rows=$document['data']['tables'][$table];
                if ($table==='rental_billing_parts') usort($rows,static fn($a,$b)=>$a['version']<=>$b['version']);
                $versions=[];foreach ($live['tables'][$table] as $old) if (isset($old['id'],$old['version'])) $versions[(string)$old['id']]=(int)$old['version'];
                foreach ($rows as $row) {
                    if (isset($row['id'],$row['version']) && $table!=='rental_billing_parts')
                        $row['version']=max((int)$row['version'],$versions[(string)$row['id']] ?? 0)+1;
                    if (in_array($table,['contracts','rental_documents'],true)) {
                        foreach (['legal_review','finance_review'] as $field) if (array_key_exists($field,$row)) $row[$field]=0;
                    }
                    $columns=array_keys($row);
                    $p->prepare('INSERT INTO `'.$table.'` (`'.implode('`,`',$columns).'`) VALUES ('.implode(',',array_fill(0,count($columns),'?')).')')->execute(array_values($row));
                }
            }
            $p->exec('DELETE FROM rental_facts WHERE '.self::factWhere());
            foreach ($document['data']['facts'] ?? [] as $fact)
                $p->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,?)')
                    ->execute([$fact['namespace'],$fact['source_key'],$fact['fact_json'],$fact['operative']]);
            self::assertMoney($p);
            $epoch=substr(bin2hex(random_bytes(16)),0,8).'-'.substr(bin2hex(random_bytes(16)),0,4).'-4'.substr(bin2hex(random_bytes(16)),0,3).'-a'.substr(bin2hex(random_bytes(16)),0,3).'-'.substr(bin2hex(random_bytes(16)),0,12);
            $p->prepare('UPDATE rental_runtime_context SET epoch=?,revision=revision+1,source_pair_sha256=? WHERE id=1')->execute([$epoch,$document['sha256']]);
            $eventFloor=(int)$p->query('SELECT COALESCE(MAX(id),0) FROM rental_events')->fetchColumn();
            $p->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('zero_point_runtime','active',?,0) ON DUPLICATE KEY UPDATE fact_json=VALUES(fact_json)")
                ->execute([self::json(['epoch'=>$epoch,'event_floor'=>$eventFloor,'baseline_sha256'=>$document['sha256']])]);
            $result=['operation'=>$key,'actor_id'=>(int)$user['id'],'target'=>$target,'target_sha256'=>$document['sha256'],
                'as_of'=>$document['as_of'],'backup_sha256'=>$backupSha,'epoch'=>$epoch,'completed_at'=>date(DATE_ATOM)];
            $p->prepare("INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES('zero_point_operation',?,?,0)")->execute([$key,self::json($result)]);
            Audit::record('zero-point.restore','zero_point',$key,null,$result);
            return $result;
        });
        unset($_SESSION['rental_top_command'],$_SESSION['rental_notice']);
        return $result;
    }

    public static function assertMoney(PDO $db): void
    {
        $bad=(int)$db->query('SELECT COUNT(*) FROM invoices i LEFT JOIN (SELECT invoice_id,SUM(amount) allocated FROM payment_allocations GROUP BY invoice_id) a ON a.invoice_id=i.id WHERE i.amount<0 OR i.paid<0 OR i.paid>i.amount OR COALESCE(a.allocated,0)>i.amount OR i.paid<>COALESCE(a.allocated,0)')->fetchColumn();
        $bad+=(int)$db->query('SELECT COUNT(*) FROM payments p LEFT JOIN (SELECT payment_id,SUM(amount) allocated FROM payment_allocations GROUP BY payment_id) a ON a.payment_id=p.id WHERE p.amount<0 OR COALESCE(a.allocated,0)>p.amount')->fetchColumn();
        $bad+=(int)$db->query('SELECT COUNT(*) FROM payment_allocations WHERE amount<=0')->fetchColumn();
        if ($bad) throw new RuntimeException('Нарушено соответствие счетов, оплат и распределений.',409);
    }
}
