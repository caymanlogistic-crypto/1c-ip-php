<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,Auth,DB,PagePolicy,View};
use PDO;
use RuntimeException;

final class PartyLegalReview
{
    public const FILE='party-source';
    public const SUBMIT='party.review.submit';
    public const ACCEPT='party.review.accept';
    public const RETURN='party.review.return';

    public static function state(PDO $db,int $id): array
    {
        return DirectoryDetails::read($db,'party_legal_review',$id);
    }

    public static function work(array $actor): array
    {
        if (!in_array($actor['role'],['lawyer','manager','admin'],true) || PagePolicy::level('rental.parties',$actor)<1) return [];
        $editable=PagePolicy::level('rental.parties',$actor)>=2 && Auth::can(Service::commandGrant(self::SUBMIT,$actor));
        $rows=[];
        foreach (Controller::query("SELECT p.id,p.name,p.inn,JSON_UNQUOTE(JSON_EXTRACT(d.fact_json,'$.status')) status,"
            ."JSON_UNQUOTE(JSON_EXTRACT(d.fact_json,'$.reason')) reason FROM rental_facts d "
            ."JOIN parties p ON d.source_key=CAST(p.id AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci "
            ."WHERE d.namespace='directory_party_legal_review' AND d.operative=1 AND p.blocked=0 "
            ."AND JSON_UNQUOTE(JSON_EXTRACT(d.fact_json,'$.status')) IN ('pending','returned') ORDER BY p.name,p.id") as $party) {
            $pending=$party['status']==='pending';
            $owner=$pending?'lawyer':'manager';
            $rows[]=$party+[
                'owner'=>$owner,'mine'=>$editable && in_array($actor['role'],[$owner,'admin'],true),
                'stage'=>$pending?'Карточка у юриста':'Карточка на исправлении',
                'comment'=>$pending?'Сверить с исходной карточкой':$party['reason'],
            ];
        }
        return $rows;
    }

    public static function requireOnNewParty(PDO $db,int $id): void
    {
        DirectoryDetails::store($db,'party_legal_review',$id,['status'=>'draft']);
    }

    public static function signature(PDO $db,array $party): string
    {
        $fields=['id','name','inn','kpp','address','legal_address','postal_address','postal_same_as_legal',
            'contact','phone','email','account_email','additional_contact','additional_phone','document_recipient',
            'additional_document_recipient','edo','edo_id'];
        $details=DirectoryDetails::read($db,'parties',(int)$party['id']);
        unset($details['search_name']);
        $bank=$db->prepare('SELECT bank,account,bik,correspondent_account FROM party_bank_accounts WHERE party_id=? AND operational=1 ORDER BY id LIMIT 1');
        $bank->execute([$party['id']]);
        return hash('sha256',Importer::canonicalJson([array_intersect_key($party,array_flip($fields)),$details,$bank->fetch()?:null]));
    }

    public static function assertCurrent(PDO $db,int $id,bool $requireApproval=false,bool $allowNewOnlyBlock=false): void
    {
        $party=Service::get($db,'parties',$id);
        if ($party['blocked'] && (!$allowNewOnlyBlock || !LegalHandoff::blocksOnlyNew($party)))
            throw new RuntimeException('Работа с контрагентом заблокирована.',409);
        $review=self::state($db,$id);
        if (!$review && !$requireApproval) return;
        if (($review['status']??null)!=='accepted' || !hash_equals((string)($review['signature']??''),self::signature($db,$party)))
            throw new RuntimeException('Юрист должен отдельно согласовать актуальные реквизиты клиента.',409);
        $file=self::findFile($db,$id,(string)($review['file_id']??''));
        if (!$file || !hash_equals((string)($review['file_sha256']??''),$file['sha256']))
            throw new RuntimeException('Исходная карточка согласованного клиента недоступна.',409);
        Evidence::integrity($file);
    }

    public static function checkUpload(array $party): void
    {
        if ($party['blocked'] || (self::state(DB::pdo(),(int)$party['id'])['status'] ?? '')==='pending')
            throw new RuntimeException('Исходная карточка сейчас недоступна для замены.',409);
    }

    private static function findFile(PDO $db,int $id,?string $fileId=null,?int $version=null): ?array
    {
        $sql="SELECT * FROM rental_files WHERE entity_type='party' AND entity_id=? AND kind=?";
        $arguments=[$id,self::FILE];
        if ($fileId!==null) {
            $sql.=' AND id=?';
            $arguments[]=$fileId;
        } else {
            $sql.=' AND version=?';
            $arguments[]=$version;
        }
        $statement=$db->prepare($sql.' ORDER BY upload_order DESC LIMIT 1');
        $statement->execute($arguments);
        return $statement->fetch()?:null;
    }

    public static function decision(PDO $db,array $actor,string $action,array $input): array
    {
        Service::roles($action===self::SUBMIT?['manager']:['lawyer']);
        $id=(int)($input['id'] ?? 0);
        $party=Service::get($db,'parties',$id);
        Service::version($party,$input);
        if ($party['blocked']) throw new RuntimeException('Работа с контрагентом заблокирована.',409);
        $before=self::state($db,$id);
        $signature=self::signature($db,$party);
        $pending=($before['status'] ?? '')==='pending';
        if ($action===self::SUBMIT) {
            if ($pending) throw new RuntimeException('Карточка уже у юриста.',409);
            $file=self::findFile($db,$id,null,(int)$party['version']);
            if (!$file || ($input['file_id'] ?? '')!==$file['id'])
                throw new RuntimeException('Приложите исходную карточку к актуальным реквизитам.',409);
            Evidence::integrity($file);
            $after=['status'=>'pending','signature'=>$signature,'file_id'=>$file['id'],'file_sha256'=>$file['sha256']];
            $text='Карточка передана юристу';
        } else {
            if (!$pending) throw new RuntimeException('Карточка сейчас не у юриста.',409);
            if ($action===self::ACCEPT) {
                $file=self::findFile($db,$id,$before['file_id']);
                if (!$file || !hash_equals($before['file_sha256'],$file['sha256']) || !hash_equals($before['signature'],$signature)
                    || ($input['file_id'] ?? '')!==$file['id'] || empty($input['verified']))
                    throw new RuntimeException('Проверьте актуальные реквизиты и исходную карточку. При изменениях верните карточку менеджеру.',409);
                Evidence::integrity($file);
                $after=array_replace($before,['status'=>'accepted','deposit_note'=>DirectoryDetails::text($input,'deposit_note',500)]);
                $text='Клиент согласован';
            } else {
                $reason=DirectoryDetails::text($input,'reason',1000);
                if ($reason==='') throw new RuntimeException('Укажите причину возврата.',422);
                $after=array_replace($before,['status'=>'returned','reason'=>$reason]);
                $text='Карточка возвращена менеджеру · '.$reason;
            }
        }
        $after['actor_name']=$actor['name'];
        $after['decided_at']=date('Y-m-d H:i:s');
        DirectoryDetails::store($db,'party_legal_review',$id,$after);
        $db->prepare('UPDATE parties SET version=version+1 WHERE id=?')->execute([$id]);
        Audit::record($action,'party',$id,$before,$after);
        Service::event($db,$actor,$action,'Клиент #'.$id.' · '.$text);
        return ['id'=>$id,'message'=>$text];
    }

    public static function presentation(array $party): string
    {
        $db=DB::pdo();$id=(int)$party['id'];$review=self::state($db,$id);
        $status=$party['blocked']?'Работа запрещена':match ($review['status'] ?? '') {
            'pending'=>'У юриста',
            'returned'=>'На исправлении у менеджера',
            'accepted'=>hash_equals($review['signature'],self::signature($db,$party))?'Клиент согласован':'Реквизиты изменились · нужна повторная проверка',
            'draft'=>'Готовится менеджером',
            default=>'Отдельная проверка не начата'
        };
        $body='<span class="registry-ui-modal-summary">Проверка клиента юристом</span>'.RentalUI::pairs([
            ['Решение',$status],['Участник',$review['actor_name'] ?? '—'],
            ['Причина возврата',$review['reason'] ?? '—'],['Индивидуальное обеспечение',$review['deposit_note'] ?? '—'],
        ]);
        $file=isset($review['file_id'])?self::findFile($db,$id,$review['file_id']):null;
        if ($file) $body.=self::link($file);
        return $body;
    }

    public static function evidenceLink(array $party): string
    {
        $db=DB::pdo();
        $review=self::state($db,(int)$party['id']);
        $file=isset($review['file_id'])?self::findFile($db,(int)$party['id'],$review['file_id']):null;
        return $file?'<p><a href="/rental/download?id='.View::e($file['id']).'" title="'
            .View::e($file['filename']).'">Скачать прикреплённые файлы</a></p>':'';
    }

    private static function link(array $file): string
    {
        return '<p><a class="btn btn-secondary" href="/rental/download?id='.View::e($file['id']).'">Исходная карточка · '.View::e($file['filename']).'</a></p>';
    }

    public static function form(): string
    {
        Access::pages(['parties'],2);
        $party=Service::get(DB::pdo(),'parties',(int)($_GET['id'] ?? 0));
        if ($party['blocked']) throw new RuntimeException('Работа с контрагентом заблокирована.',409);
        $action=(string)($_GET['action'] ?? self::SUBMIT);
        if (!in_array($action,[self::SUBMIT,self::ACCEPT,self::RETURN],true)) throw new RuntimeException('Действие не найдено.',404);
        PagePolicy::requireCommand($action);
        $actor=Service::roles($action===self::SUBMIT?['manager']:['lawyer']);
        Auth::requirePermission(Service::commandGrant($action,$actor));
        $id=(int)$party['id'];$db=DB::pdo();$review=self::state($db,$id);
        $fields=self::presentation($party);
        $extra='';
        if ($action===self::SUBMIT) {
            self::checkUpload($party);
            $file=self::findFile($db,$id,null,(int)$party['version']);
            $extra=AuxController::uploadForm($party,self::FILE,'Исходная карточка клиента','party');
            if (!$file) return RentalUI::modal('Передать карточку юристу',$fields.$extra,'','parties');
            $fields.=self::link($file).'<input type="hidden" name="file_id" value="'.View::e($file['id']).'">';
            $title='Передать карточку юристу';
        } else {
            if (($review['status'] ?? '')!=='pending') throw new RuntimeException('Карточка сейчас не у юриста.',409);
            if ($action===self::ACCEPT) {
                $fields.='<input type="hidden" name="file_id" value="'.View::e($review['file_id']).'">'
                    .DirectoryUi::checkbox('verified','Исходная карточка и реквизиты сверены',false)
                    .DirectoryUi::input('deposit_note','Индивидуальное обеспечение, если согласовано');
                $title='Согласовать клиента';
            } else {
                $fields.=DirectoryUi::input('reason','Причина возврата','', 'text',true);
                $title='Вернуть карточку менеджеру';
            }
        }
        [$form,$buttons]=DirectoryUi::form($action,$party,$fields,$title);
        return RentalUI::modal($title,$form.$extra,$buttons,'parties');
    }

    public static function actions(array $party): string
    {
        if ($party['blocked'] || PagePolicy::level('rental.parties',Auth::user())<2) return '';
        $status=self::state(DB::pdo(),(int)$party['id'])['status'] ?? '';
        $url='/rental/party-review?id='.$party['id'].'&action=';
        $actor=Auth::user();
        $role=$actor['role'];
        if (!Auth::can(Service::commandGrant(self::SUBMIT,$actor))) return '';
        if ($status==='pending' && in_array($role,['lawyer','admin'],true))
            return RentalUI::button('Согласовать клиента',$url.self::ACCEPT,true)
                .RentalUI::button('Вернуть карточку',$url.self::RETURN);
        if ($status!=='pending' && in_array($role,['manager','admin'],true))
            return RentalUI::button('Передать карточку юристу',$url.self::SUBMIT);
        return '';
    }
}
