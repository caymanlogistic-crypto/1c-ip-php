<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth,DB,Http,View};
use RuntimeException;

final class DebtFollowUpCard
{
    public static function route(int $id=0): string
    {
        return '/rental/payment-review'.($id?'?id='.$id:'');
    }

    public static function page(): string
    {
        Access::finance();
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) return self::windows();
        $db = DB::pdo();
        $invoice = Controller::query('SELECT * FROM invoices WHERE id=?',[$id])[0] ?? throw new RuntimeException('Счёт не найден.',404);
        if (!DebtFollowUp::canView($invoice)) throw new RuntimeException('Недостаточно прав для разговора по договору.',403);
        $contract = Access::contract((int)$invoice['contract_id']);
        $invoice = PaymentReview::attach($db,$invoice);
        $eligible = DebtFollowUp::eligible($invoice);
        if (isset($_GET['sample'])) {
            if (!$eligible) throw new RuntimeException('Образец доступен из карточки подтверждённого долга.',403);
            self::downloadSample($db,(int)$_GET['sample']);
        }
        $view = InvoiceFacts::view($invoice);
        $body = RentalUI::notice().RentalUI::alert($view['state'],$view['explanation']).RentalUI::pairs([
            ['Срок по счёту',Documents::dateText($invoice['due'])],
            [$view['overdue'] ? 'Долг' : 'Остаток по учёту',Controller::money($view['remaining'])],
        ]).'<div class="demo-row">'.RentalUI::button('Договор № '.$contract['number'],'/rental/contract?id='.$contract['id'])
            .RentalUI::button('Счёт № '.$invoice['number'],'/rental/invoice?id='.$id).'</div>';
        $files = DebtFollowUp::files($db,$id);
        foreach (DebtFollowUp::notes($db,$id) as $note) {
            $body .= RentalUI::pairs([
                ['Разговор',$note['conversation']],['Причина',$note['cause']],['Сотрудник',$note['actor']],
                ['Записано',(new \DateTimeImmutable($note['recorded_at']))->format('d.m.Y H:i')],
            ]);
            if (isset($files[$note['file_id']])) $body .= self::file($files[$note['file_id']]);
        }
        $canWrite = in_array(Auth::user()['role'],['manager','admin'],true) && Auth::can('rental.write');
        if ($canWrite) {
            try {Access::contract((int)$contract['id'],2);}
            catch (RuntimeException $error) {if ($error->getCode()!==403) throw $error; $canWrite=false;}
        }
        if ($eligible) {
            $body .= '<h3 class="registry-ui-modal-summary">Мягкое напоминание</h3>'
                .'<p class="registry-ui-secondary">Свяжитесь с ответственным по договору и уточните причину и ожидаемую дату оплаты.</p>'
                .self::samples($db,$id);
        }
        if (!$canWrite || !$eligible)
            return RentalUI::modal('Разговор об оплате',$body,'','invoice-list');
        $body .= '<form method="post" action="/rental/upload" enctype="multipart/form-data">'
            .PremisesActsCard::hidden('_csrf',Http::csrf()).PremisesActsCard::hidden('kind',DebtFollowUp::FILE)
            .PremisesActsCard::hidden('id',$contract['id']).PremisesActsCard::hidden('version',$contract['version'])
            .PremisesActsCard::hidden('invoice_id',$id)
            .'<label class="field"><span class="field-label">Гарантийное письмо клиента</span>'
            .'<input class="field-input" name="file" type="file" accept=".pdf,.doc,.docx" required></label>'
            .'<button class="btn btn-secondary" type="submit">Загрузить письмо</button></form>';
        $fields = PremisesActsCard::hidden('invoice_id',$id).PremisesActsCard::hidden('invoice_version',$invoice['version'])
            .'<label class="field"><span class="field-label">Результат разговора</span>'
            .'<textarea class="field-input" name="conversation" required maxlength="1200"></textarea></label>'
            .Controller::input('cause','Причина неоплаты','','text',true);
        if ($files) {
            $fields .= '<label class="field"><span class="field-label">Приложить письмо к разговору</span><select name="file_id" class="field-input"><option value="">Без письма</option>';
            foreach ($files as $file) $fields .= '<option value="'.View::e($file['id']).'">'.View::e($file['filename']).'</option>';
            $fields .= '</select></label>';
        }
        $body .= Controller::form(DebtFollowUp::SAVE,(int)$contract['id'],(int)$contract['version'],$fields,'Сохранить разговор');
        return RentalUI::modal('Разговор об оплате',$body,'','invoice-list',extractForms:false);
    }

    private static function samples(\PDO $db,int $invoice): string
    {
        $samples=TemplateAssets::approvedSamples($db,TemplateAssets::DEBT_SAMPLE);
        if (!$samples) return '<p class="registry-ui-secondary">Образец D28 появится после утверждения Word-файла в разделе «Шаблоны документов».</p>';
        $links=[];
        foreach ($samples as $sample) {
            $links[]='<a class="btn btn-secondary" href="'.View::e(self::route($invoice).'&sample='.$sample['id']).'" download>'
                .View::e('Скачать D28 · '.$sample['name'].' · версия '.$sample['version']).'</a>';
        }
        return '<div class="demo-row">'.implode('',$links).'</div>';
    }

    private static function downloadSample(\PDO $db,int $id): never
    {
        $sample=TemplateAssets::approvedSamples($db,TemplateAssets::DEBT_SAMPLE)[$id]
            ?? throw new RuntimeException('Утверждённый образец D28 не найден.',404);
        $asset=TemplateAssets::file($db,$id,$sample['word_file_id']);
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="D28.docx"; filename*=UTF-8\'\''.rawurlencode($asset['filename']));
        header('Cache-Control: private, no-store');
        header('X-Content-Type-Options: nosniff');
        readfile($asset['path']);
        exit;
    }

    private static function file(array $record): string
    {
        return '<div class="demo-row">'.Controller::link('/rental/download?id='.$record['id'],'Гарантийное письмо · '.$record['filename']).'</div>';
    }

    private static function windows(): string
    {
        Service::roles(['accountant']);
        $now = BankPaymentRefresh::now(DB::pdo());
        $day = $now->format('Y-m-d');
        $body = RentalUI::pairs([['Окно сверки',Documents::dateText($day).' · 11:00 МСК']]);
        $q = DB::pdo()->prepare("SELECT fact_json FROM rental_facts WHERE namespace=? AND operative=1 AND source_key LIKE ? ORDER BY id");
        $q->execute([BankPaymentRefresh::WINDOWS,'%:'.$day]);
        $rows = [];
        foreach ($q->fetchAll(\PDO::FETCH_COLUMN) as $raw) {
            $window = Importer::decodeExact($raw);
            $state = match ($window['state']) {'complete'=>'Полная выписка','unavailable'=>'1С недоступна',default=>'Выписка неполная'};
            $links = [];
            foreach ($window['payment_ids'] as $id) $links[] = Controller::link('/rental/payment?id='.$id,'Оплата № '.$id);
            $rows[] = [View::e('•••• '.$window['account_tail']),View::e($state),View::e($window['captured_at']),
                $links?implode(' · ',$links):'—'];
        }
        $body .= $rows ? Controller::table(['Счёт получателя','Последняя попытка','Получено','Поступления'],$rows)
            : RentalUI::alert('Свежая выписка не получена','За это окно нет подтверждённых данных.','warning');
        $attempts = DB::pdo()->prepare("SELECT fact_json FROM rental_facts WHERE namespace=? AND operative=1
            AND JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.date'))=? ORDER BY id DESC");
        $attempts->execute([BankPaymentRefresh::HISTORY,$day]);
        $history = [];
        foreach ($attempts->fetchAll(\PDO::FETCH_COLUMN) as $raw) {
            $item = Importer::decodeExact($raw);
            $state = match ($item['state']) {'complete'=>'Полная выписка','unavailable'=>'1С недоступна',default=>'Выписка неполная'};
            $links = [];
            foreach ($item['payment_ids'] as $id) $links[] = RentalUI::button('Оплата № '.$id,'/rental/payment?id='.$id);
            $history[] = [View::e('•••• '.$item['account_tail']),View::e($state),View::e($item['captured_at']),
                $links ? implode(' ',$links) : '—'];
        }
        if ($history) $body .= '<h3 class="registry-ui-modal-summary">История попыток</h3>'
            .Controller::table(['Счёт получателя','Исход','Дата и время','Поступления'],$history);
        return RentalUI::modal('Сверка банковских оплат',$body,'','invoice-list');
    }
}
