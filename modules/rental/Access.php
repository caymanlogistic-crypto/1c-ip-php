<?php
declare(strict_types=1);
namespace Modules\Rental;
use App\{Auth,DB};
use RuntimeException;
final class Access
{
    public static function pages(array $keys,int $level=1):void{Auth::requirePermission('rental.read');$u=Auth::user();if(empty($u['grants_configured'])||$u['role']==='admin')return;foreach($keys as $key)if(!empty($u['workflow_role'])&&\App\PagePolicy::level('rental.'.$key,$u)>=$level)return;throw new RuntimeException('Недостаточно прав для контекста записи.',403);}
    public static function statusPages(string $status):array{return match($status){
        'draft'=>['contracts'],'active'=>['contracts-active'],'reconciling'=>['contracts-active','reconciliation'],
        'ended','closed'=>['contracts-completed','reconciliation'],
        'legal','accounting','returned','approved','sent','cancelled'=>['approvals'],
        default=>throw new RuntimeException('Неизвестный контекст состояния документа.',403),
    };}
    public static function contract(int $id,int $level=1):array
    {
        Auth::requirePermission('rental.read');
        $q=DB::pdo()->prepare('SELECT id,status,source_json FROM contracts WHERE id=?');$q->execute([$id]);
        $record=$q->fetch() ?: throw new RuntimeException('Договор не найден.',404);
        if(ContractDraft::deleted($record))throw new RuntimeException('Договор не найден.',404);
        $pages=self::statusPages($record['status']);
        $user=Auth::user();
        $summary=$level===1 && in_array($user['role'],['director','admin'],true)
            && !in_array($record['status'],['closed','cancelled'],true)
            && \App\PagePolicy::level('rental.summary',$user)>=1;
        if ($summary) $pages[]='summary';
        self::pages($pages,$level);
        if ($record['status']==='draft' && !in_array($user['role'],['admin','manager'],true) && !$summary) {
            throw new RuntimeException('Черновик доступен менеджеру по аренде.',403);
        }
        $q=DB::pdo()->prepare('SELECT * FROM contracts WHERE id=?');$q->execute([$id]);
        return $q->fetch() ?: throw new RuntimeException('Договор не найден.',404);
    }
    public static function document(int $id,int $level=1):array
    {
        Auth::requirePermission('rental.read');
        $q=DB::pdo()->prepare('SELECT id,status,source_json FROM rental_documents WHERE id=?');$q->execute([$id]);
        $record=$q->fetch() ?: throw new RuntimeException('Документ не найден.',404);
        if(WorkspaceReset::hidden($record))throw new RuntimeException('Документ не найден.',404);
        if (!in_array($record['status'],['draft','legal','accounting','returned','approved','sent','signed','applied','cancelled'],true)) {
            throw new RuntimeException('Неизвестный контекст состояния документа.',403);
        }
        self::pages(in_array($record['status'],['signed','applied'],true)?['contracts-active']:self::statusPages($record['status']),$level);
        if ($record['status']==='draft') Service::roles(['manager']);
        $q=DB::pdo()->prepare('SELECT * FROM rental_documents WHERE id=?');$q->execute([$id]);
        return $q->fetch() ?: throw new RuntimeException('Документ не найден.',404);
    }
    public static function finance(int $level=1):void{self::pages(['invoices','invoice-list'],$level);}
    public static function file(array $f,int $contractId=0):void
    {
        if ($f['kind']===ExpiryNotice::FILE) {ExpiryNotice::access((int)$f['entity_id']);return;}
        if ($f['kind']===DebtFollowUp::FILE) self::finance();
        if ($f['entity_type']==='object' && $f['kind']===PremisesInventory::PHOTO) {
            if ($contractId) {
                self::contract($contractId);
                if (!PremisesActs::includesPhoto(DB::pdo(),$contractId,$f)) {
                    throw new RuntimeException('Фотография не относится к подписанной описи этого договора.',404);
                }
            } else self::pages(['objects']);
            return;
        }
        Auth::requirePermission('rental.read');$id=(int)$f['entity_id'];$p=DB::pdo();switch($f['entity_type']){case 'contract':self::contract($id);return;case 'document':$q=$p->prepare('SELECT contract_id FROM rental_documents WHERE id=?');$q->execute([$id]);$contract=$q->fetchColumn();if(!$contract)throw new RuntimeException('Документ не найден.',404);self::document($id);return;case 'invoice':self::finance();$q=$p->prepare('SELECT contract_id FROM invoices WHERE id=?');$q->execute([$id]);$contract=$q->fetchColumn();if(!$contract)throw new RuntimeException('Счёт не найден.',404);return;case 'payment':self::finance();$q=$p->prepare('SELECT DISTINCT i.contract_id FROM payment_allocations a JOIN invoices i ON i.id=a.invoice_id WHERE a.payment_id=?');$q->execute([$id]);$contracts=$q->fetchAll(\PDO::FETCH_COLUMN);if(!$contracts){Auth::requirePermission('finance.write');Service::roles(['accountant']);return;}return;case 'template':self::pages(['templates']);Service::roles(['lawyer','director']);return;case 'party':self::pages(['parties']);return;default:throw new RuntimeException('Неизвестный контекст файла.',403);}
    }
}
