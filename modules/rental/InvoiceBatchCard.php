<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{DB,Http,View};
use RuntimeException;

final class InvoiceBatchCard
{
    public const ROUTE = '/rental/month-invoices';

    public static function page(): string
    {
        RegularInvoice::requireAccess(2);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Http::checkCsrf();
            $input = $_POST;
            unset($input['_csrf']);
            $action = (string)($input['action'] ?? '');
            if (!in_array($action,[InvoiceBatch::SAVE,InvoiceCycle::SAVE],true))
                throw new RuntimeException('Неизвестное действие.',422);
            $period = RegularInvoice::period((string)($input['period'] ?? ''));
            try {
                $result = Service::command($action,$input);
            } catch (RuntimeException $error) {
                if (($error instanceof \PDOException) || !in_array($error->getCode(),[409,422],true)) throw $error;
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'ERP-Fetch') {
                    http_response_code($error->getCode());
                    return RentalUI::alert($error->getMessage(),'','error');
                }
                $result = ['message'=>$error->getMessage()];
            }
            $_SESSION['rental_notice'] = $result['message'];
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'ERP-Fetch')
                Http::redirect(self::route($period));
            return self::preview($period,$result['invoice_ids'] ?? []);
        }

        $period = RegularInvoice::period((string)($_GET['period'] ?? date('Y-m')));
        $id = (int)($_GET['cycle'] ?? 0);
        return $id ? self::cycle($period,RegularInvoice::contract($id)) : self::preview($period);
    }

    private static function route(string $period,int $id = 0): string
    {
        return self::ROUTE.'?'.http_build_query(array_filter(['period'=>$period,'cycle'=>$id]));
    }

    private static function form(string $id,string $action,string $period): string
    {
        return '<form id="'.View::e($id).'" method="post" action="'.self::ROUTE.'">'
            .PremisesActsCard::hidden('_csrf',Http::csrf()).PremisesActsCard::hidden('action',$action)
            .PremisesActsCard::hidden('period',$period).PremisesActsCard::hidden('command_key',bin2hex(random_bytes(16)));
    }

    private static function confirm(string $label): string
    {
        return '<label class="registry-ui-checkbox"><input type="checkbox" name="confirmed" value="1" required>'
            .'<span>'.View::e($label).'</span></label>';
    }

    private static function action(string $form,string $label): string
    {
        return '<button class="btn btn-primary" type="submit" form="'.View::e($form).'">'.View::e($label).'</button>';
    }

    private static function preview(string $period,array $saved=[]): string
    {
        $db = DB::pdo();
        $body = RentalUI::notice();
        foreach ($saved as $id) $body .= RentalUI::button('Счёт Д-'.$id,'/rental/invoice?id='.$id);
        $body .= '<form method="get" action="'.self::ROUTE.'" class="registry-ui-modal-grid">'
            .Controller::input('period','Период аренды',$period,'month',true)
            .'<button class="btn btn-secondary" type="submit">Показать расчёт</button></form>';
        $form = 'month-invoices-confirm';
        $body .= self::form($form,InvoiceBatch::SAVE,$period)
            .'<div class="table-scroll registry-ui-compact-table-scroll" tabindex="0" aria-label="Предпросмотр счетов за месяц"><table class="table registry-ui-table registry-ui-data" data-column-profile="rental-contracts">'
            .'<thead><tr><th scope="col">Выбрать</th><th scope="col">Договор</th><th scope="col">Начисление</th>'
            .'<th scope="col">Сумма</th><th scope="col">Дата выпуска</th><th scope="col">Срок оплаты</th>'
            .'<th scope="col">Счёт</th><th scope="col">Причина исключения</th></tr></thead><tbody>';
        $eligible = false;
        foreach (InvoiceBatch::contracts($db) as $contract) {
            $row = InvoiceBatch::row($db,$contract,$period);
            $eligible = $eligible || isset($row['token']);
            $body .= self::rows($row,$period);
        }
        foreach (RentalServices::monthRows($db,$period) as $service) {
            try {
                $lease = Access::contract($service['lease_id']);
            } catch (RuntimeException $error) {
                if ($error instanceof \PDOException || !in_array($error->getCode(),[403,404],true)) throw $error;
                continue;
            }
            $body .= self::serviceRow($service,$lease,$period);
        }
        $body .= '</tbody></table></div>';
        if ($eligible) $body .= self::confirm('Подтверждаю сохранение выбранных счетов');
        $body .= '</form>';
        return RentalUI::modal('Сформировать за месяц',$body,$eligible ? self::action($form,'Сохранить выбранные') : '',
            'invoice-list',wide:true,extractForms:false);
    }

    private static function rows(array $row,string $period): string
    {
        $contract = $row['contract'];
        $id = (int)$contract['id'];
        $number = $contract['number'];
        $choices = isset($row['token']) ? '<label class="registry-ui-checkbox"><input type="checkbox" name="choices['.$id.']" value="'
            .View::e($row['token']).'" aria-label="'.View::e('Выбрать договор '.$number).'"></label>' : '—';
        $contractCell = RentalUI::button($number,'/rental/contract?id='.$id)
            .'<div>'.RentalUI::button('График',self::route($period,$id)).'</div>';
        $invoices = '';
        foreach ($row['existing'] as $invoice) {
            $invoices .= '<div>'.RentalUI::button($invoice['number'],'/rental/invoice?id='.$invoice['id']).'</div>';
        }
        $documents = $row['documents'] ?: [['periods'=>$row['months'],'quote'=>$row['estimate'] ?? ($row['existing'][0] ?? [])]];
        $output = '';
        foreach ($documents as $i=>$document) {
            $quote = $document['quote'];
            $periodText = implode(' / ',$document['periods']);
            $kind = $quote['kind'] ?? 'Аренда';
            if ($row['mode'] === 'quarterly') $kind .= ' · квартальный график';
            $date = $row['preparation']['date'] ?? ($row['existing'][0]['issued'] ?? null);
            $due = $quote['due'] ?? null;
            $amount = $quote['amount'] ?? null;
            $output .= '<tr data-contract-id="'.$id.'"><td>'.($i ? '—' : $choices).'</td><td>'.($i ? View::e($number) : $contractCell)
                .'</td><td>'.View::e($kind).'<div class="registry-ui-secondary">'.View::e($periodText).'</div></td>'
                .'<td class="registry-ui-number">'.($amount === null ? '—' : View::e(Documents::amountText($amount)).' ₽').'</td>'
                .'<td>'.($date ? View::e(Documents::dateText($date)) : '—').'</td><td>'.($due ? View::e(Documents::dateText($due)) : '—')
                .'</td><td>'.($invoices ?: '—').'</td><td class="registry-column-text">'.View::e($row['question'] ?? '—').'</td></tr>';
        }
        return $output;
    }

    private static function serviceRow(array $service,array $lease,string $period): string
    {
        $leaseId = (int)$lease['id'];
        $document = $service['service_id']
            ? '/rental/service?'.http_build_query(['id'=>$service['service_id'],'period'=>$period])
            : '/rental/services?contract_id='.$leaseId;
        return '<tr data-service-lease="'.$leaseId.'"><td>—</td><td>'
            .RentalUI::button($lease['number'],'/rental/contract?id='.$leaseId)
            .'<div>'.RentalUI::button($service['number'] ?? 'Услуги по договору',$document).'</div>'
            .'</td><td>'.View::e($service['kind']).'<div class="registry-ui-secondary">'.View::e($service['basis']).'</div>'
            .'</td><td class="registry-ui-number">'.($service['amount'] === null ? '—' : View::e(Documents::amountText($service['amount'])).' ₽')
            .'</td><td>—</td><td>—</td><td>—</td><td class="registry-column-text">'.View::e($service['question']).'</td></tr>';
    }

    private static function cycle(string $period,array $contract): string
    {
        $id = (int)$contract['id'];
        $fact = InvoiceCycle::read(DB::pdo(),$id,$period);
        $form = 'invoice-cycle-confirm';
        $options = [''=>'Выберите порядок','monthly'=>'Помесячный счёт','quarterly'=>'Квартальное выставление','self'=>'Самостоятельная оплата'];
        $select = '<label class="field"><span class="field-label">Порядок выставления</span><select class="field-input" name="mode" required>';
        foreach ($options as $value=>$label) {
            $select .= '<option value="'.View::e($value).'"'.(($fact['mode'] ?? '') === $value ? ' selected' : '').'>'.View::e($label).'</option>';
        }
        $body = RentalUI::pairs([['Договор',$contract['number']],['Выбранный месяц',$period],
            ['Месяцы квартала',implode(' / ',InvoiceCycle::quarter($period))]])
            .self::form($form,InvoiceCycle::SAVE,$period)
            .PremisesActsCard::hidden('contract_id',$id).PremisesActsCard::hidden('version',$contract['version'])
            .PremisesActsCard::hidden('revision',InvoiceCycle::revision($fact))
            .$select.'</select></label>'
            .Controller::input('reason','Документ или переписка, подтверждающие график',$fact['reason'] ?? '','text',true)
            .'<fieldset><legend>Для квартального выставления</legend>'
            .Controller::input('document_count','Согласованное число счетов',$fact && $fact['documents'] ? (string)count($fact['documents']) : '','number',false);
        for ($i=0; $i<3; $i++) {
            $document = $fact['documents'][$i] ?? [];
            $months = $document['periods'] ?? [];
            $body .= '<div class="registry-ui-modal-grid">'
                .Controller::input('from['.$i.']','Счёт '.($i+1).' · с месяца',$months[0] ?? '','month',false)
                .Controller::input('to['.$i.']','По месяц',$months ? end($months) : '','month',false)
                .Controller::input('due['.$i.']','Срок оплаты',$document['due'] ?? '','date',false).'</div>';
        }
        $body .= '</fieldset>'.self::confirm('Подтверждаю согласованный график').'</form>';
        return RentalUI::modal('График выставления',$body,self::action($form,'Сохранить график'),'invoice-list',wide:true,extractForms:false);
    }
}
