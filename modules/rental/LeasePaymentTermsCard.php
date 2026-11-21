<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{DB,View};
use PDO;
use RuntimeException;

final class LeasePaymentTermsCard
{
    public static function route(int $id, string $month = ''): string
    {
        return '/rental/payment-terms?id='.$id.($month !== '' ? '&month='.rawurlencode($month) : '');
    }

    public static function summary(array $contract): string
    {
        $id = (int)$contract['id'];
        $facts = LeasePaymentTerms::facts(DB::pdo(),$id);
        $rows = [];
        if (isset($facts['signature'])) $rows[] = ['Договор фактически подписан',Documents::dateText($facts['signature']['date'])];
        if (isset($facts['accrual'])) $rows[] = ['Начало начисления',Documents::dateText($facts['accrual']['date'])];
        return RentalUI::pairs($rows).'<div class="demo-row">'.RentalUI::button('Даты и условия оплаты',self::route($id)).'</div>';
    }

    public static function card(): string
    {
        $id = (int)($_GET['id'] ?? 0);
        $contract = Access::contract($id);
        $db = DB::pdo();
        $month = substr(Service::date((string)($_GET['month'] ?? date('Y-m')).'-01'),0,7);
        $facts = LeasePaymentTerms::facts($db,$id);
        $acts = PremisesActs::history($db,$id);
        $transfer = LeasePaymentTerms::actDate($acts,'transfer');
        $signature = $facts['signature']['date'] ?? null;
        $forms = LeasePaymentTerms::allowedForms($db,$contract);
        $form = $facts['base']['form'] ?? array_key_first($forms);
        $accrual = $facts['accrual']['date'] ?? (($facts['base']['form'] ?? '') === 'D09' ? $transfer : null);
        $statusPage = Access::statusPages($contract['status'])[0];
        $body = RentalUI::notice().RentalUI::pairs([
            ['Дата договора',Documents::dateText($contract['document_date'])],
            ['Фактическая подпись договора',Documents::dateText($signature)],
            ['Начало начисления',Documents::dateText($accrual)],
            ['Подтверждённая форма',isset($facts['base']) ? $facts['base']['form'].' · '.LeasePaymentTerms::FORMS[$facts['base']['form']] : 'Ожидает сверки'],
        ]);
        if (isset($facts['signature'])) $body .= self::download($facts['signature']['file_id'],'Подписанный договор');
        $actRows = [];
        foreach ($acts as $act) {
            $actRows[] = [View::e(PremisesActs::TITLES[$act['kind']].' · '.$act['object_name']),
                View::e(Documents::dateText($act['date'])),self::download($act['file_id'],'Скачать акт')];
        }
        $body .= $actRows ? Controller::table(['Акт','Дата подписи','Файл'],$actRows)
            : RentalUI::pairs([['Допуск / передача','Подтверждённых актов пока нет']]);
        if ($form !== null) {
            $firstDue = LeasePaymentTerms::firstDue($form,$signature,$transfer);
            if (!isset($facts['base']) && $firstDue['trigger'] !== null) {
                $firstDue['question'] = 'Сотруднику нужно подтвердить правило в подписанном договоре.';
            }
            $body .= self::heading('Первый платёж').RentalUI::pairs([
                ['Правило формы',$form.' · 5 банковских дней'],
                ['Отсчёт',$firstDue['origin']],
                ['Дата отсчёта',Documents::dateText($firstDue['trigger'])],
            ]).self::due($firstDue);
        } else {
            $body .= RentalUI::alert('Сверьте условие о сроке первого платежа', 'Для состава этого договора требуется решение сотрудника.', 'warning');
        }
        $paymentRows = [];
        foreach (LeasePaymentTerms::advances($db,$contract,$facts,$transfer) as $payment) {
            $paymentRows[] = [View::e(Documents::dateText($payment['date'])),self::money($payment['amount'])];
        }
        if ($paymentRows) $body .= self::heading($transfer ? 'Принятый аванс до передачи' : 'Принятый аванс до подтверждения передачи').Controller::table(['Дата оплаты','Сумма'],$paymentRows);
        if ($form === 'D09') $body .= self::paymentMode($facts);
        $body .= self::calculation($contract,$facts,$month,$transfer);
        if (isset($facts['return-rule'])) {
            $rule = $facts['return-rule'];
            $signature = SecurityDepositEvidence::returnSignature($db,$contract);
            $signedOn = $signature['act_signed_on'] ?? null;
            $body .= self::heading('Возврат обеспечения').RentalUI::pairs([
                ['Подписанное соглашение',$rule['number']],
                ['Правило','15 рабочих дней от подписания акта возврата'],
                ['Дата подписания акта',Documents::dateText($signedOn)],
            ]).self::download($rule['file_id'],'Скачать соглашение');
            $deadline=$signedOn ? SecurityDepositEvidence::returnDeadline(
                $signature,(string)($rule['file_sha256'] ?? ''),SecurityDeposit::savedPlan($db,$id))['deadline'] : $signature;
            $body .= self::due($deadline,'Срок возврата');
        }
        $body .= self::invoices($db,$id);
        $action = (string)($_GET['fact'] ?? '');
        $actions = '';
        if ($action !== '') {
            if (!LeasePaymentTerms::canEdit($contract,$action)) throw new RuntimeException('Действие недоступно для этой роли.',403);
            $body = RentalUI::notice().self::edit($db,$contract,$facts,$forms,$action,$month);
        } else {
            $body .= self::editingLinks($contract,$facts,$forms,$month);
        }
        return RentalUI::modal('Даты и условия оплаты · '.$contract['number'],$body,$actions,$statusPage,wide:true);
    }

    private static function heading(string $label): string
    {
        return '<span class="registry-ui-modal-summary">'.View::e($label).'</span>';
    }

    private static function download(string $id,string $label): string
    {
        return '<a class="btn btn-secondary" href="/rental/download?id='.rawurlencode($id).'">'.View::e($label).'</a>';
    }

    private static function money(string $value): string
    {
        return View::e(Documents::amountText($value).' ₽');
    }

    private static function due(array $due, string $label = 'Оплатить до'): string
    {
        if (isset($due['question'])) return RentalUI::alert($due['question'],'','warning');
        return RentalUI::pairs([[$label,Documents::dateText($due['date'])],
            ...(isset($due['source']) ? [['Календарь',$due['source']]] : [])]);
    }

    private static function paymentMode(array $facts): string
    {
        if (isset($facts['decision'])) {
            $decision = $facts['decision'];
            return RentalUI::pairs([['Порядок оплаты',$decision['decision'] === 'self' ? 'Самостоятельная оплата' : 'Индивидуальный порядок со счётом'],
                ['Основание',$decision['reason']]]);
        }
        if (isset($facts['exception'])) return RentalUI::alert('Бухгалтеру нужно решить, требуется ли счёт по этому договору',$facts['exception']['reason'],'warning');
        return isset($facts['base']) ? RentalUI::pairs([['Порядок оплаты','Самостоятельно, без выставления счёта']]) : '';
    }

    private static function calculation(array $contract,array $facts,string $month,?string $transfer): string
    {
        $id = (int)$contract['id'];
        $body = self::heading('Расчёт условий за '.$month);
        $body .= '<div class="demo-row">';
        $date = new \DateTimeImmutable($month.'-01');
        foreach (['-1 month'=>'Предыдущий месяц','+1 month'=>'Следующий месяц'] as $shift=>$label) {
            $period = $date->modify($shift)->format('Y-m');
            $body .= RentalUI::button($label,self::route($id,$period));
        }
        $body .= '</div>';
        $changes = ($facts['base']['form'] ?? '') === 'D09'
            ? LeasePaymentTerms::documents(DB::pdo(),$id,'renewal') : [];
        $result = LeasePaymentTerms::period($contract,$facts,$month,$transfer,$changes);
        $rates = [];
        foreach ($facts as $key=>$rate) {
            if (!str_starts_with($key,'rate:')) continue;
            $rates[] = [View::e($rate['number']),View::e(Documents::dateText($rate['signed'])),
                View::e(Documents::dateText($rate['rate_from'])),isset($rate['net']) ? self::money($rate['net']).' без НДС' : self::money($rate['gross']),
                self::download($rate['file_id'],'Скачать подписанный ДС')];
        }
        if ($rates) $body .= Controller::table(['ДС','Подписан','Ставка действует с','Месячная ставка','Файл'],$rates);
        if (isset($result['question'])) return $body.RentalUI::alert($result['question'],'','warning');
        $lines = [];
        foreach ($result['segments'] as $segment) {
            $interval = Documents::dateText($segment['from']).' — '.Documents::dateText($segment['to']);
            $lines[] = [View::e($interval),View::e($segment['source']['number'] ?? 'Договор'),
                self::money($segment['monthly']).($segment['net'] === null ? '' : ' без НДС'),
                $segment['net'] === null ? '—' : self::money($segment['net']),
                $segment['vat'] === null ? '—' : self::money($segment['vat']),self::money($segment['gross'])];
        }
        $body .= Controller::table(['Даты','Основание','Ставка в месяц','Сумма без НДС','НДС','К начислению'],$lines);
        $totals = [[isset($result['payment_question']) ? 'Итог предпросмотра' : 'К перечислению',
            Documents::amountText($result['gross']).' ₽']];
        if ($result['vat'] !== null) $totals[] = ['В том числе НДС',Documents::amountText($result['vat']).' ₽'];
        if (isset($result['days'])) $totals[] = ['Первый месяц',$result['days'].' календ. дн. начисления'];
        if (isset($result['purpose'])) $totals[] = ['Назначение платежа',$result['purpose']];
        $body .= RentalUI::pairs($totals);
        if (isset($result['payment_question'])) $body .= RentalUI::alert($result['payment_question'],'','warning');
        return $body.(isset($result['due']) && !isset($result['due']['origin']) ? self::due($result['due']) : '');
    }

    private static function invoices(PDO $db,int $id): string
    {
        $query = $db->prepare("SELECT number,period,amount,due FROM invoices WHERE contract_id=? AND status='active' ORDER BY issued DESC,id DESC");
        $query->execute([$id]);
        $rows = [];
        foreach ($query as $invoice) {
            $rows[] = [View::e($invoice['number']),View::e($invoice['period']),
                $invoice['amount'] === null ? '—' : self::money($invoice['amount']),
                View::e(Documents::dateText($invoice['due']))];
        }
        return $rows ? self::heading('Ранее выставленные счета').Controller::table(['Счёт','Период','Сумма','Срок'],$rows) : '';
    }

    private static function editingLinks(array $contract,array $facts,array $forms,string $month): string
    {
        $links = [];
        $options = [
            'signature'=>[!empty($contract['signed']) && !isset($facts['signature']),'Подтвердить дату подписи'],
            'base'=>[!empty($contract['signed']) && $forms && !isset($facts['base']),'Подтвердить условия договора'],
            'accrual'=>[!empty($contract['signed']) && !isset($facts['accrual']),'Подтвердить начало начисления'],
            'rate'=>[($facts['base']['form'] ?? '') === 'D09','Учесть парковочный ДС с НДС 5 %'],
            'prior-rate'=>[($facts['base']['form'] ?? '') === 'D09','Учесть прежнюю ставку по подписанному ДС'],
            'exception'=>[isset($forms['D09']) && !isset($facts['exception']),'Уточнить необходимость счёта'],
            'decision'=>[isset($facts['exception']) && !isset($facts['decision']),'Сохранить решение по счёту'],
            'advance'=>[true,'Связать принятый аванс'],
            'return-rule'=>[!isset($facts['return-rule']) && in_array($contract['status'],['reconciling','ended','closed'],true),'Подтвердить условие возврата обеспечения'],
        ];
        foreach ($options as $kind=>[$available,$label]) {
            if ($available && LeasePaymentTerms::canEdit($contract,$kind)) {
                $links[] = RentalUI::button($label,self::route((int)$contract['id'],$month).'&fact='.$kind);
            }
        }
        return $links ? '<div class="demo-row">'.implode('',$links).'</div>' : '';
    }

    private static function edit(PDO $db,array $contract,array $facts,array $forms,string $kind,string $month): string
    {
        $id = (int)$contract['id'];
        $date = static fn(string $label): string => Controller::input('fact_date',$label,'','date');
        $reason = static fn(string $label): string => Controller::input('reason',$label);
        $file = '';
        if (in_array($kind,['signature','base','accrual'],true)) {
            $files = [];
            foreach (LeasePaymentTerms::contractFiles($db,$contract) as $row) $files[$row['id']] = $row['filename'];
            if (!$files) return RentalUI::alert('Нужен доступный файл с подписями обеих сторон.','','warning');
            $file = self::select('file_id','Подписанный договор',$files);
        }
        $document = '';
        if (in_array($kind,['rate','prior-rate','return-rule'],true)) {
            $choices = [];
            foreach (LeasePaymentTerms::documents($db,$id,$kind === 'return-rule' ? 'termination' : 'renewal') as $did=>$row) {
                if (!isset($facts['rate:'.$did])) $choices[$did] = 'ДС № '.$row['number'];
            }
            if (!$choices) return RentalUI::alert('Нужен подписанный ДС с подтверждаемым условием.','','warning');
            $document = self::select('document_id','Подписанный ДС',$choices);
        }
        $fields = match ($kind) {
            'signature'=>$file.$date('Фактическая дата подписания договора'),
            'base'=>$file.self::select('lease_form','Форма подтверждённых условий',$forms)
                .Controller::input('amount','Месячная ставка с налогом, ₽'),
            'accrual'=>$file.$date('Начало начисления').$reason('Пункт или основание в подписанном договоре'),
            'rate','prior-rate'=>$document.$date('Фактическая дата подписания ДС')
                .Controller::input('rate_from','Новая ставка с','','date')
                .Controller::input('amount',$kind === 'rate' ? 'Ставка по ДС без НДС, ₽' : 'Итоговая месячная ставка по ДС, ₽'),
            'return-rule'=>$document.$date('Фактическая дата подписания ДС'),
            'exception'=>$reason('Что нужно выяснить о выставлении счёта'),
            'decision'=>self::select('decision','Порядок оплаты',['self'=>'Самостоятельно','invoice'=>'По индивидуальной договорённости о счёте'])
                .$reason('Подтверждённое основание решения'),
            'advance'=>Controller::input('payment_id','Номер записи принятого платежа','','number')
                .$reason('Назначение платежа или основание связи с договором'),
            default=>throw new RuntimeException('Неизвестный факт оплаты.',404),
        };
        $confirmation = match ($kind) {
            'return-rule'=>'В подписанном ДС подтверждены 15 рабочих дней от акта возврата',
            'rate'=>'ДС подтверждает НДС 5 % поверх ставки, срок до 25-го предыдущего месяца и пять банковских дней от акта для первого периода',
            default=>'Сверил сведения с исходным основанием',
        };
        return Controller::form(LeasePaymentTerms::SAVE,$id,(int)$contract['version'],
            PremisesActsCard::hidden('fact',$kind).PremisesActsCard::hidden('month',$month).$fields
            .'<label class="registry-ui-checkbox"><input type="checkbox" name="verified" value="1" required><span>'
            .View::e($confirmation).'</span></label>','Сохранить');
    }

    private static function select(string $name,string $label,array $options): string
    {
        $html = '<label class="field"><span class="field-label">'.View::e($label).'</span>'
            .'<select name="'.View::e($name).'" required><option value="">Выберите</option>';
        foreach ($options as $key=>$text) $html .= '<option value="'.View::e((string)$key).'">'.View::e($text).'</option>';
        return $html.'</select></label>';
    }
}
