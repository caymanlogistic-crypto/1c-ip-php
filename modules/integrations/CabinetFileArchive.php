<?php
declare(strict_types=1);

namespace Modules\Integrations;

/** Acquires missing files through the existing per-version attempt fence. */
final class CabinetFileArchive
{
    public function __construct(private \PDO $db,private PrivateStore $store) {}

    public function run(string $provider,string $from,string $to,float $deadline): array
    {
        if (!CabinetSyncAdmission::allows($this->db,$provider,'sync')) {
            throw new \RuntimeException('EXTERNAL_ACCESS_NOT_AUTHORIZED');
        }
        $kinds=match($provider) {
            'diadoc'=>['original','pdf'],
            'mosvodokanal'=>['original'],
            'mosenergo','pik'=>['pdf'],
        };
        $started=(new \DateTimeImmutable())->format('Y-m-d H:i:s.u');
        $stats=['required'=>0,'saved'=>0,'new_files'=>0,'blocked'=>0];
        $service=new FileService($this->db,$this->store);
        $docs=$this->db->prepare('SELECT id,version,raw_path FROM external_documents
            WHERE provider=? AND document_date BETWEEN ? AND ? ORDER BY document_date DESC,id');
        $check=$this->db->prepare("SELECT a.id,a.document_id,a.status,a.actor_id,a.request_key,a.result_code,a.receipt_path FROM external_fetch_attempts a LEFT JOIN external_files f ON f.id=a.file_id
            WHERE a.document_id=? AND a.document_version=? AND a.operation=?
              AND (a.status IN ('pending','uncertain') OR (a.status='success' AND f.id IS NOT NULL))");
        $docs->execute([$provider,$from,$to]);
        foreach($docs->fetchAll(\PDO::FETCH_ASSOC) as $document) {
            foreach($kinds as $kind) {
                $stats['required']++;
                $check->execute([$document['id'],$document['version'],$kind]);
                $attempts=$check->fetchAll(\PDO::FETCH_ASSOC);
                if(in_array('success',array_column($attempts,'status'),true)) {
                    $stats['saved']++;
                    continue;
                }
                $blocked=array_filter($attempts,static fn(array $attempt):bool=>!$service->canReconcile($provider,$kind,$attempt));
                if($blocked || !str_starts_with($document['raw_path'],'records/')) {
                    $stats['blocked']++;
                    continue;
                }
                if(hrtime(true)/1e9 >= $deadline) continue;
                try {
                    $result=$service->fetch($document['id'],$kind,0,Support::uuid(),true);
                    if($result['status']==='success') {
                        $stats['new_files']++;
                        $stats['saved']++;
                    } else {
                        $stats['blocked']++;
                    }
                } catch(\Throwable) {
                    $stats['blocked']++;
                }
            }
        }
        $id=Support::uuid();
        $context=['trigger'=>'hourly','from'=>$from,'to'=>$to];
        $status=$stats['saved']===$stats['required']?'success':'partial';
        $this->db->prepare("INSERT INTO integration_runs(id,provider,operation,request_key,actor_id,revision,fingerprint,status,
            result_code,coverage_json,created_at,finished_at) VALUES(?,?,'sync-files',?,0,?,?,?, ?,?,?,NOW(6))")
            ->execute([$id,$provider,$id,(int)Config::get($this->db,$provider)['revision'],
                hash('sha256',Support::json($context)),$status,$status==='success'?'CABINET_FILES_SAVED':'CABINET_FILES_PARTIAL',
                Support::json(['command_context'=>$context,'files'=>$stats]),$started]);
        return ['id'=>$id,'status'=>$status]+$stats;
    }
}
