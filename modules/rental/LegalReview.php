<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{DB, Http, View};

final class LegalReview
{
    public static function assigned(array $contract): bool
    {
        return $contract['status']==='legal'
            || ($contract['status']==='returned' && $contract['return_to']==='lawyer');
    }

    public static function file(array $contract): ?array
    {
        $db=DB::pdo();
        $q=$db->prepare("SELECT COUNT(*) FROM rental_files WHERE entity_type='contract' AND entity_id=? AND kind='lawyer-file' AND version=?");
        $q->execute([$contract['id'],$contract['version']]);
        return $q->fetchColumn()?Evidence::latest($db,'contract',(int)$contract['id'],'lawyer-file',(int)$contract['version']):null;
    }

    public static function context(array $contract): string
    {
        return RentalUI::pairs([
            ['Период аренды',RegistryState::date($contract['start_date']).' — '.RegistryState::date($contract['end_date'])],
            ['Аренда с НДС',Controller::money($contract['amount']).' / месяц'],
        ]).'<div class="demo-row">'
            .RentalUI::button('Контрагент','/rental/party?id='.$contract['party_id'])
            .RentalUI::button('Условия договора','/rental/conditions?id='.$contract['id'])
            .'</div>'
            .PartyLegalReview::presentation(Service::get(DB::pdo(),'parties',(int)$contract['party_id']))
            .ContractReviewFiles::presentation($contract)
            .ContractRevision::presentation($contract)
            .($contract['status']==='returned'?RentalUI::alert('Замечания бухгалтера',(string)$contract['reason'],'error'):'');
    }

    public static function fileFields(?array $file): string
    {
        if (!$file) return RentalUI::alert('Нужен актуальный файл','Сформируйте Word или загрузите готовый DOC, DOCX или PDF. Затем откройте проверку юристом повторно.','warning');
        $pack=WordPack::evidence(DB::pdo(),$file['id']);
        return RentalUI::pairs([['Файл для согласования',$file['filename']]])
            .($pack?WordPack::links($pack):'')
            .'<div class="demo-row"><a class="btn btn-secondary" href="/rental/download?id='.View::e($file['id']).'">Скачать проверяемый файл</a></div>'
            .'<input type="hidden" name="legal_file_id" value="'.View::e($file['id']).'">'
            .'<label class="registry-ui-checkbox"><input type="checkbox" name="document_confirmed" value="1" required><span>'.($pack?'Все документы пакета проверены':'Актуальный файл договора проверен').'</span></label>';
    }

    public static function upload(array $contract): string
    {
        return '<form class="registry-ui-modal-grid" enctype="multipart/form-data" method="post" action="/rental/upload">'
            .'<input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'">'
            .'<input type="hidden" name="id" value="'.$contract['id'].'">'
            .'<input type="hidden" name="version" value="'.$contract['version'].'">'
            .'<input type="hidden" name="entity_type" value="contract">'
            .'<input type="hidden" name="kind" value="lawyer-file">'
            .'<label class="field"><span class="field-label">Актуальный DOC, DOCX или PDF</span><input type="file" name="file" accept=".doc,.docx,.pdf" required></label>'
            .'<div class="demo-row"><button class="btn btn-secondary">Загрузить файл</button></div></form>';
    }
}
