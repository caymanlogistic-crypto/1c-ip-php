<?php
declare(strict_types=1);
namespace Modules\Integrations;

/** Seals each received page before following its opaque continuation. Explicit resume reuses bytes. */
final class Collector
{
    public function __construct(private \PDO $db,private PrivateStore $store) {}
    public function collect(string $runId,string $account,callable $fetch): array {
        $q=$this->db->prepare('SELECT * FROM integration_capture_pages WHERE run_id=? AND account_key=? ORDER BY page_number');$q->execute([$runId,$account]);$pages=$q->fetchAll(\PDO::FETCH_ASSOC);$rows=[];$cursor=null;$seen=[];$total=null;$number=0;
        foreach($pages as $page){if((int)$page['page_number']!==++$number||Support::json($cursor)!==Support::json(json_decode($page['cursor_json']??'null',true)))throw new \RuntimeException('CAPTURE_CHAIN_INVALID');$bytes=$this->store->read($page['raw_path'],$page['raw_sha256']);$data=Support::decode($bytes);$this->validate($data,$total,$seen,$rows);$cursor=$data['next']??null;if($cursor===null)return ['items'=>$rows,'total'=>$total,'reused_pages'=>$number,'pages'=>$number];}
        $reused=$number;
        do{$q=$this->db->prepare('SELECT status FROM integration_runs WHERE id=?');$q->execute([$runId]);if($q->fetchColumn()!=='pending')throw new \RuntimeException('RUN_NOT_CLAIMED');$data=$fetch($cursor);if(!is_array($data))throw new \RuntimeException('SOURCE_SCHEMA_CHANGED');$copyRows=$rows;$copySeen=$seen;$copyTotal=$total;$this->validate($data,$copyTotal,$copySeen,$copyRows);$bytes=Support::json($data);$path=$this->store->put('capture-pages',$bytes);$next=$data['next']??null;
            $this->db->prepare('INSERT INTO integration_capture_pages(id,run_id,account_key,page_number,cursor_json,next_cursor_json,total_count,item_count,raw_path,raw_sha256) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([Support::uuid(),$runId,$account,++$number,Support::json($cursor),Support::json($next),$data['total']??null,count($data['items']),$path,hash('sha256',$bytes)]);$rows=$copyRows;$seen=$copySeen;$total=$copyTotal;$cursor=$next;
        }while($cursor!==null);
        return ['items'=>$rows,'total'=>$total,'reused_pages'=>$reused,'pages'=>$number];
    }
    private function validate(array $data,?int &$total,array &$seen,array &$rows): void {
        $items=$data['items']??null;$next=$data['next']??null;$reported=$data['total']??null;
        if(!is_array($items)||!array_is_list($items)||count($rows)+count($items)>100000||$reported!==null&&(!is_int($reported)||$reported<0||$reported>100000)||$total!==null&&$reported!==null&&$total!==$reported)throw new \RuntimeException('SOURCE_TOTAL_CHANGED');
        if($reported!==null)$total=$reported;
        foreach($items as $row){if(!is_array($row))throw new \RuntimeException('SOURCE_SCHEMA_CHANGED');Support::business($row);$rows[]=$row;}
        if($next!==null){if(!is_int($next)&&!is_string($next)||is_string($next)&&strlen($next)>2048)throw new \RuntimeException('INVALID_CURSOR');$key=Support::json($next);if(isset($seen[$key]))throw new \RuntimeException('REPEATED_CURSOR');$seen[$key]=true;}
        elseif($total!==null&&count($rows)!==$total)throw new \RuntimeException('INCOMPLETE_SOURCE_COUNT');
    }
}
