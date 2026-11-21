<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{DB,View};
use RuntimeException;

final class RegularInvoiceCard
{
    public static function route(int $id = 0, string $period = '', string $issued = '', int $payment = 0): string
    {
        $query = array_filter(['contract_id'=>$id,'period'=>$period,'issued'=>$issued,'payment_id'=>$payment]);
        return '/rental/create-invoice'.($query ? '?'.http_build_query($query) : '');
    }

    public static function card(): string
    {
        RegularInvoice::requireAccess(2);
        $db = DB::pdo();
        $id = (int)($_GET['contract_id'] ?? 0);
        $issued = (string)($_GET['issued'] ?? date('Y-m-d'));
        $period = (string)($_GET['period'] ?? date('Y-m'));
        $payment = (int)($_GET['payment_id'] ?? 0);
        $body = RentalUI::notice();
        $contract = $id ? RegularInvoice::contract($id) : null;
        if ($contract && ($_GET['edit_delivery'] ?? '') === '1') {
            return RentalUI::modal('Доставка счета за аренду',$body.self::deliveryForm($contract,$period,$issued,$payment),'','invoice-list');
        }
        $body .= '<form method="get" action="/rental/create-invoice" class="registry-ui-modal-grid">'
            .self::contractSelect($id)
            .Controller::input('period','Период аренды',$period,'month')
            .Controller::input('issued','Дата документа',$issued,'date');
        if ($contract) {
            $options = [0=>'Без ссылки на приход'];
            foreach (RegularInvoice::paymentChoices($db,$contract) as $row) {
                $options[$row['id']] = Documents::dateText($row['payment_date']).' · '.Documents::amountText($row['amount']).' ₽';
            }
            if (count($options) > 1) $body .= self::select('payment_id','Ранее полученный платёж',$options,(string)$payment,false);
        }
        $body .= '<button class="btn btn-secondary" type="submit">Показать расчёт</button></form>';
        if (!$contract) return RentalUI::modal('Создать счёт',$body,'','invoice-list');
        try {
            $quote = RegularInvoice::preview($db,$contract,$period,$issued,$payment);
        } catch (RuntimeException $error) {
            if ($error instanceof \PDOException || $error->getCode() === 403) throw $error;
            return RentalUI::modal('Создать счёт',$body.RentalUI::alert($error->getMessage(),'','warning'),'','invoice-list');
        }
        $body .= self::calculation($contract,$quote);
        $body .= self::delivery($quote['delivery']);
        $body .= '<div class="demo-row">'.RentalUI::button('Настроить доставку счёта',
            self::route($id,$period,$issued,$payment).'&edit_delivery=1').'</div>';
        if (isset($quote['token'])) {
            $fields = PremisesActsCard::hidden('contract_id',$id)
                .PremisesActsCard::hidden('period',$period).PremisesActsCard::hidden('issued',$issued)
                .PremisesActsCard::hidden('payment_id',$payment).PremisesActsCard::hidden('plan_token',$quote['token']);
            if (isset($quote['payment'])) {
                $fields .= self::check('earlier_payment_confirmed','Подтверждаю основание для даты документа до оплаты')
                    .Controller::input('payment_reason','Документ или основание для оформления после оплаты');
            }
            $body .= self::saveForm(RegularInvoice::CREATE,(int)$contract['version'],
                $fields.self::check('confirmed','Подтверждаю реквизиты счёта'),'Сохранить счёт');
        }
        return RentalUI::modal('Создать счёт',$body,'','invoice-list',wide:true,extractForms:false);
    }

    private static function contractSelect(int $selected): string
    {
        $contracts = Controller::query("SELECT c.id,c.number,p.name FROM contracts c JOIN parties p ON p.id=c.party_id
            WHERE c.status IN ('active','reconciling') AND ".ContractDraft::visibleSql()." ORDER BY c.number,c.id");
        $options = [''=>'Выберите договор'];
        foreach ($contracts as $contract) $options[$contract['id']] = $contract['number'].' · '.$contract['name'];
        return self::select('contract_id','Договор',$options,(string)$selected);
    }

    private static function select(string $name,string $label,array $options,string $selected,bool $required=true): string
    {
        $html = '<label class="field"><span class="field-label">'.View::e($label).'</span>'
            .'<select name="'.View::e($name).'"'.($required?' required':'').'>';
        foreach ($options as $key=>$value) {
            $html .= '<option value="'.View::e((string)$key).'"'.((string)$key===$selected?' selected':'').'>'.View::e($value).'</option>';
        }
        return $html.'</select></label>';
    }

    private static function check(string $name,string $label): string
    {
        return '<label class="registry-ui-checkbox"><input type="checkbox" name="'.View::e($name).'" value="1" required><span>'
            .View::e($label).'</span></label>';
    }

    private static function saveForm(string $action,int $version,string $fields,string $label): string
    {
        return '<form action="/rental/finance-save" method="post">'
            .PremisesActsCard::hidden('_csrf',\App\Http::csrf()).PremisesActsCard::hidden('action',$action)
            .PremisesActsCard::hidden('version',$version).PremisesActsCard::hidden('command_key',bin2hex(random_bytes(16)))
            .$fields.'<button type="submit" class="btn btn-primary">'.View::e($label).'</button></form>';
    }

    private static function deliveryForm(array $contract,string $period,string $issued,int $payment): string
    {
        $party = Controller::query('SELECT * FROM parties WHERE id=?',[$contract['party_id']])[0];
        $policy = InvoiceDeliveryPreference::read(DB::pdo(),$party);
        $rule = $policy['rule'];
        $id = (int)$contract['id'];
        $fields = PremisesActsCard::hidden('contract_id',$id)
            .PremisesActsCard::hidden('period',$period).PremisesActsCard::hidden('issued',$issued)
            .PremisesActsCard::hidden('payment_id',$payment)
            .PremisesActsCard::hidden('rule_revision',hash('sha256',Importer::canonicalJson($rule)))
            .self::select('mode','Способ доставки',[''=>'Выберите']+InvoiceDeliveryPreference::CHANNELS,$policy['mode'])
            .Controller::input('edo','Получатель в ЭДО',$rule['edo'] ?? '','text',false)
            .Controller::input('reason','Основание, включая согласие клиента на дубль',$rule['reason'] ?? '')
            .self::check('confirmed','Подтверждаю настройку для счетов за аренду этого клиента');
        return RentalUI::pairs([['Контрагент',$party['name']],['Получатели из карточки клиента',implode(', ',RecipientContext::clients($party)) ?: 'Требуют сверки']])
            .self::saveForm(InvoiceDeliveryPreference::SAVE,(int)$contract['version'],$fields,'Сохранить настройку');
    }

    private static function calculation(array $contract,array $quote): string
    {
        $body = '';
        if ($quote['existing'] || isset($quote['previously_issued'])) {
            $body .= RentalUI::alert('За период уже есть счёт');
            foreach ($quote['existing'] as $invoice) {
                $body .= RentalUI::button($invoice['number'],'/rental/invoice?id='.$invoice['id']);
            }
            if (!$quote['existing']) $body .= RentalUI::button('Открыть ранее оформленный счёт','/rental/invoice?id='.$quote['previously_issued']);
        }
        if (isset($quote['source'])) {
            $src = $quote['source'];
            $body .= RentalUI::pairs([
                ['Номер счёта',$quote['number'] ?? 'Ожидает сверки'],
                ['Назначение платежа',$quote['purpose']],
                ['Сумма с НДС',Documents::amountText($quote['amount']).' ₽'],
                ['Срок по договору',$quote['due'] ? Documents::dateText($quote['due']) : 'Требует подтверждения'],
                ['Источник ставки',$src['label']],
                ['Месячная ставка',Documents::amountText($src['rate']).' ₽'.($src['net_rate'] ? ' без НДС':' с НДС')],
                ['Покрытие',Documents::dateText($src['from']).' — '.Documents::dateText($src['to'])],
            ]).self::fileLink($src['file_id']);
        }
        foreach ($quote['questions'] as $question) $body .= RentalUI::alert($question,'','warning');
        if (!empty($quote['initial_only'])) $body .= InitialInvoiceCard::entry($contract);
        if (isset($quote['payment'])) {
            $body .= self::payment($quote['payment']);
        }
        return $body;
    }

    private static function fileLink(string $id): string
    {
        return '<a class="btn btn-secondary" href="/rental/download?id='.rawurlencode($id).'">Открыть подписанное основание</a>';
    }

    public static function payment(array $payment): string
    {
        return RentalUI::pairs([
            ['Ранее полученный платёж',Documents::dateText($payment['date']).' · '.Documents::amountText($payment['amount']).' ₽'],
            ...isset($payment['reason']) ? [['Основание даты документа',$payment['reason']]] : [],
        ]).RentalUI::button('Открыть карточку прихода','/rental/payment?id='.$payment['id']);
    }

    public static function delivery(array $policy): string
    {
        $body = RentalUI::pairs([
            ['Доставка счёта за аренду',InvoiceDeliveryPreference::CHANNELS[$policy['mode']] ?? 'Требует подтверждения'],
        ]);
        if ($policy['email']) $body .= RentalUI::pairs([['Получатели по почте',implode(', ',$policy['email'])]]);
        if (!empty($policy['manager_copy'])) $body .= RentalUI::pairs([['Копия менеджеру',$policy['manager_copy']]]);
        if ($policy['edo'] !== '') $body .= RentalUI::pairs([['Получатель в ЭДО',$policy['edo']]]);
        foreach ($policy['questions'] as $question) $body .= RentalUI::alert($question,'','warning');
        return $body;
    }

    public static function recorded(array $source,array $invoice,?array $intent): string
    {
        $at = new \DateTimeImmutable($source['created_at']);
        $body = RentalUI::pairs([
            ['Фактически создан',$at->format('d.m.Y H:i:s P')],
            ['Источник суммы',$source['rate']['label']],
            ['Состояние доставки',InvoiceDeliveryPreference::status($invoice,$intent)],
        ]).self::fileLink($source['rate']['file_id']);
        if ($source['payment']) {
            $body .= self::payment($source['payment'])
                .RentalUI::alert('Приход указан как основание даты документа. Распределение выполняется отдельно.');
        }
        return $body.self::delivery($source['delivery']);
    }
}
