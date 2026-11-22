<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth, DB, View};
use RuntimeException;

final class InitialInvoiceCard
{
    public static function route(int $id): string
    {
        return '/rental/initial-invoice?id='.$id;
    }

    public static function entry(array $contract): string
    {
        if (!InitialInvoice::available($contract) && !InitialInvoice::issuedFor(DB::pdo(),(int)$contract['id'])) return '';
        return RentalUI::button('Выставить первый счёт',self::route((int)$contract['id']));
    }

    public static function card(): string
    {
        $id = (int)($_GET['id'] ?? 0);
        $contract = Access::contract($id);
        if (!InitialInvoice::available($contract) && !InitialInvoice::issuedFor(DB::pdo(),$id))
            throw new RuntimeException('Первый счёт недоступен на этом этапе.',409);
        $page = Access::statusPages($contract['status'])[0];
        $url = self::route($id);
        $issued = (string)($_GET['issued'] ?? max(date('Y-m-d'),$contract['document_date'] ?? ''));
        try {
            Service::date($issued);
        } catch (RuntimeException $error) {
            throw new RuntimeException($error->getMessage(),422);
        }
        $body = RentalUI::notice().RentalUI::pairs([
            ['Договор №',$contract['number']],
            ['Дата договора',Documents::dateText($contract['document_date'])],
        ]);
        $section = (string)($_GET['fact'] ?? '');
        if ($section !== '') {
            $action = match ($section) {
                'terms'=>InitialInvoice::TERMS,
                'due'=>InitialInvoice::DUE,
                default=>throw new RuntimeException('Действие не найдено.',404),
            };
            if (!InitialInvoice::can($contract,$action)) throw new RuntimeException('Недостаточно прав для подтверждения.',403);
            return RentalUI::modal('Первый счёт · договор № '.$contract['number'],
                $body.self::factForm($contract,$action), '',$page);
        }
        $plan = InitialInvoice::preview(DB::pdo(),$contract,$issued);
        if ($plan['existing'] || $plan['receipt']) {
            $body .= self::history($plan);
        } else {
            $body .= '<form class="registry-ui-modal-grid" method="get" action="/rental/initial-invoice">'
                .PremisesActsCard::hidden('id',$id).Controller::input('issued','Дата первого счёта',$issued,'date')
                .'<button type="submit" class="btn btn-secondary">Показать расчёт</button></form>';
            $body .= self::preview($plan);
            if (InitialInvoice::can($contract,InitialInvoice::TERMS)) {
                $body .= RentalUI::button('Подтвердить условия начисления',$url.'&fact=terms');
            }
            if (InitialInvoice::can($contract,InitialInvoice::DUE)) {
                $body .= RentalUI::button('Подтвердить индивидуальный срок',$url.'&fact=due');
            }
            $canIssue = InitialInvoice::can($contract,InitialInvoice::ISSUE);
            if ($canIssue) {
                try {
                    InitialInvoice::assertIssueParty(DB::pdo(),$contract,Auth::user()['role']);
                } catch (RuntimeException $error) {
                    if ($error instanceof \PDOException) throw $error;
                    $body .= RentalUI::alert('Выпуск первого счёта пока недоступен',$error->getMessage(),'warning');
                    $canIssue = false;
                }
            }
            if ($plan['rows'] && $canIssue) {
                $pending = in_array(null,array_column($plan['rows'],'due'),true);
                $label = $pending ? 'Подтверждаю состав начислений и регистрацию строк с неподтверждённым сроком'
                    : 'Подтверждаю дату и состав начислений';
                $body .= Controller::form(InitialInvoice::ISSUE,$id,(int)$contract['version'],
                    PremisesActsCard::hidden('issued',$issued).PremisesActsCard::hidden('plan_token',$plan['token'])
                    .self::check($label),'Зарегистрировать первый счёт');
            }
        }
        if (InitialInvoice::available($contract)) $body .= HandoverQuestionCard::section($contract);
        return RentalUI::modal('Первый счёт · договор № '.$contract['number'],$body,'',$page,wide:true,extractForms:false);
    }

    private static function check(string $label): string
    {
        return '<label class="registry-ui-checkbox"><input type="checkbox" name="confirmed" value="1" required><span>'
            .View::e($label).'</span></label>';
    }

    private static function factForm(array $contract,string $action): string
    {
        if ($action === InitialInvoice::TERMS) {
            $forms = LeasePaymentTerms::allowedForms(DB::pdo(),$contract);
            if (!$forms) return RentalUI::alert('Нужна сверка предмета договора','Тип договора не позволяет выбрать правило.','warning');
            $fields = '<label class="field"><span class="field-label">Согласованная форма</span><select name="lease_form" required>'
                .'<option value="">Выберите</option>';
            foreach ($forms as $form=>$label) $fields .= '<option value="'.View::e($form).'">'.View::e($form.' · '.$label).'</option>';
            $fields .= '</select></label>'
                .Controller::input('multiple','Обеспечение в месячных ставках (0 — без обеспечения)',1,'number');
            $fields .= Controller::input('reason','Согласованный пункт договора о первом начислении и обеспечении');
            $check = 'Подтверждаю порядок 15/16 и выбранное обеспечение для этой редакции';
        } else {
            $fields = '<label class="field"><span class="field-label">Начисление</span><select name="due_part" required>'
                .'<option value="">Выберите</option>';
            foreach (InitialInvoice::DUE_PARTS as $part=>$label) $fields .= '<option value="'.$part.'">'.View::e($label).'</option>';
            $fields .= '</select></label>'.Controller::input('due','Согласованный срок оплаты','','date')
                .Controller::input('reason','Документ или пункт об индивидуальном сроке');
            $check = 'Срок подтверждён для выбранного начисления по этому договору';
        }
        return Controller::form($action,(int)$contract['id'],(int)$contract['version'],$fields.self::check($check),'Сохранить подтверждение');
    }

    private static function preview(array $plan): string
    {
        $body = '';
        foreach ($plan['questions'] as $question) $body .= RentalUI::alert($question,'','warning');
        if (!$plan['rows']) return $body;
        $body .= RentalUI::pairs([
            ['Основание',$plan['signed'] ? 'Подтверждённые условия и начало начисления' : 'Согласованные плановые условия проекта'],
            ['Начало расчёта',Documents::dateText($plan['from'])],
            ['Кратность обеспечения',(string)$plan['condition']['multiple']],
        ]);
        $rows = [];
        foreach ($plan['rows'] as $row) {
            $period = $row['kind'] === 'Обеспечительный платёж' ? 'Обеспечение'
                : Documents::dateText($row['from']).' — '.Documents::dateText($row['to']);
            $due = $row['due'] ? Documents::dateText($row['due']) : 'Требует подтверждения';
            $rows[] = [View::e($row['kind']),View::e($period),Controller::money($row['amount']),View::e($due)];
        }
        $body .= Controller::table(['Начисление','Период','Сумма с НДС','Срок оплаты'],$rows);
        foreach (array_unique(array_column($plan['rows'],'due_basis')) as $basis) {
            $body .= RentalUI::alert($basis);
        }
        return $body;
    }

    private static function history(array $plan): string
    {
        $body = RentalUI::alert('Есть ранее зарегистрированные первоначальные счета');
        foreach ($plan['receipt']['questions'] ?? [] as $question) $body .= RentalUI::alert($question,'','warning');
        if (isset($plan['receipt']['created_at'])) {
            $created = new \DateTimeImmutable($plan['receipt']['created_at']);
            $body .= RentalUI::pairs([
                ['Дата первого счёта',Documents::dateText($plan['receipt']['issued'])],
                ['Зарегистрирован',$created->format('d.m.Y H:i')],
                ['Начало расчёта при выпуске',Documents::dateText($plan['receipt']['from'])],
            ]);
        }
        $rows = [];
        foreach ($plan['existing'] as $invoice) {
            $rows[] = [RentalUI::button($invoice['number'],'/rental/invoice?id='.(int)$invoice['id']),
                View::e($invoice['kind']),View::e($invoice['period']),Controller::money($invoice['amount']),
                Controller::money($invoice['paid']),View::e($invoice['due'] ? Documents::dateText($invoice['due']) : 'Требует подтверждения')];
        }
        return $body.Controller::table(['Счёт','Начисление','Период','Сумма','Оплачено','Срок оплаты'],$rows);
    }
}
