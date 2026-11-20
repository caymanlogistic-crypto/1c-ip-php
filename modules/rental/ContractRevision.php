<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,Auth,DB,Http,View};
use PDO;
use RuntimeException;

final class ContractRevision
{
    public const ACTION='contract.revise';
    public const KINDS=[
        'tenant-amendments'=>'Правки арендатора',
        'third-party-draft'=>'Сторонний документ',
        'disagreement-protocol'=>'Протокол разногласий',
    ];

    public static function available(array $contract): bool
    {
        return empty($contract['signed']) && in_array($contract['status'],['legal','accounting','approved','sent'],true);
    }

    public static function requireOpen(PDO $db,array $contract): void
    {
        if (!self::available($contract)) throw new RuntimeException('Новая редакция доступна для договора до подписания.',409);
        $query=$db->prepare("SELECT 1 FROM rental_delivery_intents WHERE entity_type='contract' AND entity_id=? AND status IN ('claimed','unknown') LIMIT 1");
        $query->execute([$contract['id']]);
        if ($query->fetchColumn()) throw new RuntimeException('Сначала уточните результат предыдущей отправки.',409);
    }

    private static function staged(PDO $db,array $contract): array
    {
        $query=$db->prepare("SELECT id,kind,filename,sha256,path FROM rental_files WHERE entity_type='contract' AND entity_id=? "
            ."AND version=? AND kind IN ('tenant-amendments','third-party-draft','disagreement-protocol') ORDER BY upload_order DESC");
        $query->execute([$contract['id'],$contract['version']]);
        $files=[];
        foreach ($query->fetchAll() as $file) $files[$file['kind']]??=$file;
        return $files;
    }

    public static function submit(PDO $db,array $actor,array $contract,array $input): array
    {
        Service::roles(['manager']);
        Access::contract((int)$contract['id'],2);
        self::requireOpen($db,$contract);
        $reason=DirectoryDetails::text($input,'reason',1000);
        if ($reason==='') throw new RuntimeException('Укажите причину пересогласования.',422);
        $files=self::staged($db,$contract);
        $ids=array_column($files,'id','kind');
        if (!$ids || !is_array($input['revision_files'] ?? null) || $ids!=$input['revision_files'])
            throw new RuntimeException('Приложите материалы к актуальной версии и откройте форму заново.',409);
        foreach ($files as $file) Evidence::integrity($file);

        $id=(int)$contract['id'];
        $before=self::state($db,$id);
        ContractHistory::remember($db,$contract,$actor,(string)$input['command_key'],$before);
        $sources=[];
        foreach ($files as $file) $sources[]=array_intersect_key($file,array_flip(['id','kind','filename','sha256']));
        $after=[
            'from_version'=>(int)$contract['version'],
            'version'=>(int)$contract['version']+1,
            'reason'=>$reason,'sources'=>$sources,'actor_name'=>$actor['name'],
        ];
        DirectoryDetails::store($db,'contract_revision',$id,$after);
        $db->prepare("DELETE FROM rental_approvals WHERE entity_type='contract' AND entity_id=?")->execute([$id]);
        $db->prepare("UPDATE contracts SET status='legal',return_to=NULL,reason=NULL,version=version+1 WHERE id=?")->execute([$id]);
        Audit::record(self::ACTION,'contract',$id,$before,$after);
        Service::event($db,$actor,self::ACTION,'Договор №'.$contract['number'].' · новая редакция у юриста · '.$reason,$id,[
            'kind'=>'contract-revision-handoff','from_version'=>$after['from_version'],'version'=>$after['version'],
            'epoch'=>(string)$db->query('SELECT epoch FROM rental_runtime_context WHERE id=1')->fetchColumn(),
            'next_role'=>'lawyer','command_key'=>$input['command_key'],
        ]);
        return ['id'=>$id,'status'=>'legal','message'=>'Новая редакция передана юристу.'];
    }

    public static function legalHandoff(PDO $db,int $id,?array $ordinary): ?array
    {
        $q=$db->prepare("SELECT id,created_at FROM rental_events WHERE contract_id=? AND action=? "
            ."AND JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.kind'))='contract-revision-handoff' "
            ."AND JSON_UNQUOTE(JSON_EXTRACT(source_json,'$.epoch'))=(SELECT epoch FROM rental_runtime_context WHERE id=1) ORDER BY id DESC LIMIT 1");
        $q->execute([$id,self::ACTION]);
        $revision=$q->fetch();
        return $revision && (int)$revision['id']>(int)($ordinary['event_id'] ?? 0)
            ? ['event_id'=>(int)$revision['id'],'created_at'=>$revision['created_at'],'resubmitted'=>true] : $ordinary;
    }

    public static function state(PDO $db,int $id): array
    {
        return DirectoryDetails::read($db,'contract_revision',$id);
    }

    public static function presentation(array $contract): string
    {
        $revision=self::state(DB::pdo(),(int)$contract['id']);
        if (!$revision) return '';
        $next=match($contract['status']) {
            'legal'=>'Юрист проверяет редакцию',
            'accounting'=>'Бухгалтер проверяет пакет и реквизиты',
            'approved'=>'Подготовить передачу согласованного пакета',
            'returned'=>$contract['return_to']==='lawyer'?'Юрист разбирает замечания':'Менеджер исправляет замечания',
            'sent'=>'Подтвердить подписание и оплату',
            default=>'Проверка редакции завершена',
        };
        $body='<span class="registry-ui-modal-summary">Новая редакция договора</span>'
            .RentalUI::pairs([
                ['Редакция', (string)$revision['version']], ['Причина', $revision['reason']],
                ['Передал', $revision['actor_name']], ['Следующее действие',$next],
            ]);
        foreach ($revision['sources'] as $file) $body.=self::link($file);
        return $body;
    }

    private static function link(array $file): string
    {
        return '<p><a class="btn btn-secondary" href="/rental/download?id='.View::e($file['id']).'">'
            .View::e(self::KINDS[$file['kind']].' · '.$file['filename']).'</a></p>';
    }

    public static function form(array $contract): string
    {
        Service::roles(['manager']);
        Auth::requirePermission('rental.write');
        Access::contract((int)$contract['id'],2);
        $db=DB::pdo();
        self::requireOpen($db,$contract);
        $files=self::staged($db,$contract);
        $context=RentalUI::pairs([['Договор',$contract['number']],['Текущая версия',(string)$contract['version']]]);
        $fields=DirectoryUi::input('reason','Причина пересогласования','', 'text',true);
        $upload='<form class="registry-ui-modal-grid" enctype="multipart/form-data" method="post" action="/rental/upload">'
            .'<input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'">'
            .'<input type="hidden" name="entity_type" value="contract">'
            .'<input type="hidden" name="id" value="'.(int)$contract['id'].'">'
            .'<input type="hidden" name="version" value="'.(int)$contract['version'].'">'
            .DirectoryUi::select('kind','Вид документа',self::KINDS,array_key_first(self::KINDS),true)
            .'<label class="field"><span class="field-label">Файл Word или PDF</span><input type="file" name="file" accept=".doc,.docx,.pdf" required></label>'
            .'<button class="btn btn-secondary">Загрузить файл</button></form>';
        foreach ($files as $kind=>$file) $fields.=self::link($file)
            .'<input type="hidden" name="revision_files['.$kind.']" value="'.View::e($file['id']).'">';
        $form=$actions='';
        if ($files) [$form,$actions]=DirectoryUi::form(self::ACTION,$contract,$fields,'Передать редакцию юристу');
        return RentalUI::modal('Пересогласовать договор',$context.$upload.$form,$actions);
    }
}
