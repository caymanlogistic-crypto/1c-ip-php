<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, PagePolicy};
use PDO;
use RuntimeException;

final class OnecRequest
{
    public const PAGE = 'onec-work';
    public const SUBMIT = 'onec-request.submit';
    public const APPROVE = 'onec-request.approve';
    public const RETURN = 'onec-request.return';
    public const ACTIONS = [self::SUBMIT,self::APPROVE,self::RETURN];
    public const URL = '/rental/onec-work';
    private const FACT = 'onec_request';

    public static function route(int $id): string
    {
        return self::URL.'?id='.$id;
    }

    public static function available(array $contract): bool
    {
        return !empty($contract['party_id']) && empty($contract['signed']) && InitialInvoice::available($contract)
            && in_array($contract['status'],['legal','returned','accounting','approved','sent'],true);
    }

    public static function access(array $actor,int $level=1): void
    {
        Auth::requirePermission('rental.read');
        if (!in_array($actor['role'],['manager','accountant','admin'],true)
            || PagePolicy::level('rental.'.self::PAGE,$actor)<$level)
            throw new RuntimeException('Недостаточно прав для раздела «Работа с 1С».',403);
    }

    public static function read(PDO $db,int $id): array
    {
        return DirectoryDetails::read($db,self::FACT,$id);
    }

    public static function visible(array $actor,array $request): bool
    {
        return match ($actor['role']) {
            'admin'=>true,
            'manager'=>(int)$actor['id']===(int)($request['manager_id']??0),
            'accountant'=>(int)$actor['id']===(int)($request['reviewer_id']??0),
            default=>false,
        };
    }

    public static function reviewers(PDO $db): array
    {
        $users=$db->query("SELECT u.id,u.name,u.username,u.role role_id,r.grants_configured,rp.kind role,
            COUNT(*) OVER (PARTITION BY u.name) name_count FROM users u JOIN roles r ON r.id=u.role
            JOIN role_profiles rp ON rp.role_id=u.role WHERE u.active=1 AND rp.kind='accountant' ORDER BY u.name,u.id")->fetchAll();
        $choices=[];
        foreach ($users as $user) {
            if (Auth::canFor($user,'finance.write') && Auth::canFor($user,'rental.read')
                && PagePolicy::level('rental.'.self::PAGE,$user)>=2
                && PagePolicy::level('rental.approvals',$user)>=1) {
                $choices[(int)$user['id']]=$user['name'].((int)$user['name_count']>1?' ('.$user['username'].')':'');
            }
        }
        return $choices;
    }

    public static function snapshot(PDO $db,array $contract,string $issued): array
    {
        if (!self::available($contract)) throw new RuntimeException('Заявка на первый счёт доступна по неподписанному проекту в работе.',409);
        $id=(int)$contract['id'];
        $party=Service::get($db,'parties',(int)$contract['party_id']);
        $review=PartyLegalReview::state($db,(int)$party['id']);
        if (($review['status']??null)!=='accepted')
            throw new RuntimeException('Юрист должен согласовать актуальную карточку клиента.',409);
        PartyLegalReview::assertCurrent($db,(int)$party['id']);
        $file=LegalReview::file($contract);
        if (!$file && in_array($contract['status'],['accounting','approved','sent'],true)) {
            $approval=$db->prepare("SELECT f.*,a.baseline_hash approval_hash FROM rental_approvals a
                JOIN rental_files f ON f.id=a.file_id AND f.entity_type=a.entity_type AND f.entity_id=a.entity_id
                WHERE a.entity_type='contract' AND a.entity_id=? AND a.role='lawyer'");
            $approval->execute([$id]);
            $approved=$approval->fetch();
            if ($approved && hash_equals($approved['approval_hash'],Evidence::approvalSignature($db,$id,$approved['id']))
                && Evidence::latest($db,'contract',$id,$approved['kind'],(int)$approved['version'])['id']===$approved['id']) {
                $file=$approved;
            }
        }
        if (!$file) throw new RuntimeException('Приложите актуальный Word или PDF проекта договора.',409);
        Evidence::integrity($file);
        $pack=WordPack::evidence($db,$file['id']);
        $fileFields=array_flip(['id','sha256','filename']);
        $documents=$pack['files']??[array_intersect_key($file,$fileFields)];
        $statement=$db->prepare('SELECT id,sha256,filename FROM rental_files WHERE id=?');
        $statement->execute([$review['file_id']]);
        $documents[]=$statement->fetch()?:throw new RuntimeException('Исходная карточка согласованного клиента недоступна.',409);
        $plan=InitialInvoice::preview($db,$contract,$issued);
        if (!$plan['rows'] || !empty($plan['questions']))
            throw new RuntimeException($plan['questions'][0]??'Первоначальные счета уже зарегистрированы или расчёт пока недоступен.',409);
        $fields=array_flip(['kind','amount','from','to','due','due_basis']);
        $rows=array_map(static fn(array $row): array=>array_intersect_key($row,$fields),$plan['rows']);
        return ['contract_id'=>$id,'contract_version'=>(int)$contract['version'],'number'=>$contract['number'],
            'party_id'=>(int)$party['id'],'party_name'=>$party['name'],'party_inn'=>$party['inn'],
            'party_signature'=>$review['signature'],'issued'=>$issued,'documents'=>$documents,'invoices'=>$rows,'plan_token'=>$plan['token']];
    }

    public static function hash(array $snapshot): string
    {
        return hash('sha256',Importer::canonicalJson($snapshot));
    }

    public static function execute(PDO $db,array $actor,string $action,array $input): array
    {
        self::access($actor,2);
        $contract=Access::contract((int)($input['id']??0));
        $id=(int)$contract['id'];
        $old=self::read($db,$id);
        Service::version($old?:['version'=>0],$input);
        if ($action===self::SUBMIT) {
            if ($actor['role']!=='manager' || $old && !self::visible($actor,$old))
                throw new RuntimeException('Заявку передаёт менеджер, который её подготовил.',403);
            if ($old && $old['state']!=='returned') {
                try {
                    $current=self::snapshot($db,$contract,$old['snapshot']['issued']);
                } catch (RuntimeException $error) {
                    if ($error->getCode()!==409) throw $error;
                    $current=null;
                }
                if ($current && hash_equals($old['snapshot_sha256'],self::hash($current))) {
                    throw new RuntimeException('Заявка по этому комплекту уже есть.',409);
                }
            }
            $reviewer=(int)($input['reviewer_id']??0);
            $reviewers=self::reviewers($db);
            if (!isset($reviewers[$reviewer])) throw new RuntimeException('Выберите бухгалтера с доступом к согласованию.',422);
            $issued=Service::date((string)($input['issued']??''));
            $snapshot=self::snapshot($db,$contract,$issued);
            if (!hash_equals(self::hash($snapshot),(string)($input['snapshot_sha256']??'')))
                throw new RuntimeException('Комплект изменился. Откройте заявку заново.',409);
            $new=['state'=>'pending','manager_id'=>(int)$actor['id'],'manager_name'=>$actor['name'],
                'reviewer_id'=>$reviewer,'reviewer_name'=>$reviewers[$reviewer],
                'snapshot'=>$snapshot,'snapshot_sha256'=>self::hash($snapshot),'submitted_at'=>date(DATE_ATOM)];
            $message='Заявка передана бухгалтеру на согласование.';
        } else {
            if ($actor['role']!=='accountant' || !self::visible($actor,$old))
                throw new RuntimeException('Решение принимает назначенный бухгалтер.',403);
            if (($old['state']??null)!=='pending') throw new RuntimeException('По заявке уже принято решение.',409);
            $new=$old;
            if ($action===self::APPROVE) {
                if (($input['checked']??null)!=='1') throw new RuntimeException('Подтвердите проверку документов и расчёта.',422);
                $snapshot=self::snapshot($db,$contract,$old['snapshot']['issued']);
                if (!hash_equals($old['snapshot_sha256'],self::hash($snapshot)))
                    throw new RuntimeException('Комплект изменился. Верните заявку менеджеру для повторной подачи.',409);
                $new['state']='approved';
                $message='Бухгалтер разрешил передачу в 1С.';
            } else {
                $reason=DirectoryDetails::text($input,'reason',600);
                if ($reason==='') throw new RuntimeException('Укажите причину возврата.',422);
                $new['state']='returned';
                $new['reason']=$reason;
                $message='Заявка возвращена менеджеру.';
            }
            $new['decided_at']=date(DATE_ATOM);
        }
        $new['version']=(int)($old['version']??0)+1;
        DirectoryDetails::store($db,self::FACT,$id,$new);
        Service::event($db,$actor,$action,$message,$id);
        return ['id'=>$id,'request_version'=>$new['version'],'message'=>$message];
    }
}
