<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{DB,Http,View};
use RuntimeException;

final class UpdCard
{
    public const ROUTE='/rental/upd';
    private const FORM='upd-confirm';

    public static function page(): string
    {
        $post=($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST';
        RegularInvoice::requireAccess($post ? 2 : 1);
        if ($post) {
            Http::checkCsrf();
            $input=$_POST;
            unset($input['_csrf']);
            $month=UpdRegister::month($input['month'] ?? null);
            try {
                $result=Service::command(UpdRegister::SAVE,$input);
            } catch (RuntimeException $error) {
                if ($error instanceof \PDOException || !in_array($error->getCode(),[409,422],true)) throw $error;
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')==='ERP-Fetch') {
                    http_response_code($error->getCode());
                    return RentalUI::alert($error->getMessage(),'','error');
                }
                $result=['message'=>$error->getMessage()];
            }
            $_SESSION['rental_notice']=$result['message'];
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')!=='ERP-Fetch') Http::redirect(self::route($month));
            return self::listing($month);
        }
        $month=UpdRegister::month($_GET['month'] ?? date('Y-m'));
        return isset($_GET['id']) ? self::detail($month,(int)$_GET['id']) : self::listing($month);
    }

    private static function route(string $month,int $id=0): string
    {
        return self::ROUTE.'?'.http_build_query(array_filter(['month'=>$month,'id'=>$id]));
    }

    private static function listing(string $month): string
    {
        $db=DB::pdo();
        $body=RentalUI::notice().'<form method="get" action="'.self::ROUTE.'" class="registry-ui-modal-grid">'
            .Controller::input('month','Месяц начислений',$month,'month',true)
            .'<button class="btn btn-secondary" type="submit">Показать УПД</button></form>'
            .'<form method="post" action="'.self::ROUTE.'" id="'.self::FORM.'">'
            .PremisesActsCard::hidden('_csrf',Http::csrf()).PremisesActsCard::hidden('month',$month)
            .PremisesActsCard::hidden('command_key',bin2hex(random_bytes(16)));
        $ready=false;
        $rows=[];
        $matched=[];
        foreach (UpdRegister::invoices($db,$month) as $invoice) {
            $row=UpdRegister::row($db,$invoice,$month);
            if ($charge=UpdEvidence::basis($db,$invoice,$month)['service_charge'] ?? null) $matched[$charge]=true;
            $choice='—';
            if (isset($row['token']) && empty($row['draft'])) {
                $ready=true;
                $choice='<label class="registry-ui-checkbox"><input type="checkbox" name="choices['.$invoice['id'].']" value="'
                    .View::e($row['token']).'" aria-label="'.View::e('Выбрать счёт '.$invoice['number']).'"></label>';
            }
            $decision=View::e($row['question'] ?? '');
            if (isset($row['reference'])) $decision .= '<div class="registry-ui-secondary">'.View::e($row['reference']).'</div>';
            $rows[]=[
                $choice,RentalUI::button($invoice['number'],'/rental/invoice?id='.$invoice['id']),self::contract($invoice),
                self::service($row,$month),self::money($row['amount']),
                View::e(Documents::dateText($row['date'] ?? substr($row['original']['record']['record_date'] ?? '',0,10))),
                View::e($row['status']).' '.RentalUI::button('Открыть',self::route($month,(int)$invoice['id'])),$decision,
            ];
        }
        foreach (RentalServices::monthRows($db,$month) as $service) {
            if (isset($matched[$service['charge_id'] ?? ''])) continue;
            try { $lease=Access::contract((int)$service['lease_id']); }
            catch (RuntimeException $error) {
                if ($error instanceof \PDOException || !in_array($error->getCode(),[403,404],true)) throw $error;
                continue;
            }
            $rows[]=['—','—',RentalUI::button($lease['number'],'/rental/contract?id='.$lease['id']),
                $service['service_id']
                    ? RentalUI::button($service['kind'],'/rental/service?'.http_build_query(['id'=>$service['service_id'],'period'=>$month]))
                    : View::e($service['kind']),
                self::money($service['amount']),'—','Нужна сверка',
                View::e('Свяжите сохранённое начисление с подтверждённым счётом. '.$service['basis'])];
        }
        $body.=Controller::table(['Выбрать','Счёт','Договор','Услуга','Сумма','Дата УПД','Состояние','Основание или вопрос'],$rows);
        if ($ready) $body.='<label class="registry-ui-checkbox"><input type="checkbox" name="confirmed" value="1" required>'
            .'<span>Подтверждаю сохранение выбранных проектов</span></label>';
        $body.='</form>';
        $action=$ready ? '<button class="btn btn-primary" type="submit" form="'.self::FORM.'">Сформировать проекты</button>' : '';
        return RentalUI::modal('УПД за месяц',$body,$action,'invoice-list',wide:true,extractForms:false);
    }

    private static function detail(string $month,int $id): string
    {
        $db=DB::pdo();
        $invoice=UpdRegister::invoices($db,$month,$id)[0] ?? throw new RuntimeException('Счёт недоступен за выбранный месяц.',404);
        $row=UpdRegister::row($db,$invoice,$month);
        $source=$row['original']['record'] ?? [];
        $body='<div class="demo-row">'.RentalUI::button('Счёт № '.$invoice['number'],'/rental/invoice?id='.$id)
            .self::contract($invoice).self::service($row,$month).'</div>';
        $fields=[['Месяц начислений',$month],['Состояние',$row['status']],
            ['Итоговая сумма',Controller::money($row['amount'])],['Учтено по счёту',Controller::money($row['invoice_paid'] ?? $invoice['paid'])],
            ['Основание',$row['reference'] ?? '—'],['Дата УПД',Documents::dateText($row['date'] ?? substr($source['record_date'] ?? '',0,10))]];
        if (isset($row['date_rule'])) $fields[]=['Правило даты',$row['date_rule']];
        if (isset($row['calendar'])) $fields[]=['Рабочий календарь',$row['calendar']];
        if (isset($row['payment'])) {
            $fields[]=['Поступление № '.$row['payment']['payment_id'],$row['payment']['reference']];
            $fields[]=['Проведение по выписке',self::timestamp($row['payment']['posted_at'])];
        }
        if (isset($row['before'])) $fields[]=['Учётное время УПД','Ранее '.self::timestamp($row['before']).'. Точное время не назначено.'];
        if (isset($row['balance'])) {
            $fields[]=['Зачтено в проекте',Controller::money($row['credit']['amount'] ?? '0.00')];
            $fields[]=['Остаток после зачёта',Controller::money($row['balance'])];
        }
        if (isset($row['credit'])) {
            $fields[]=['Аванс № '.$row['credit']['payment_id'],$row['credit']['reference']];
            if ($row['credit']['already_booked'] ?? false) $fields[]=['Распределение','Уже учтено по этому счёту'];
        }
        if ($source) {
            $fields[]=['Номер УПД 1С',$source['number']];
            $fields[]=['Оригинал в сохранённом источнике',$source['collection'].' / '.$source['source_key']];
            $fields[]=['Контрольная сумма снимка',$source['snapshot_sha']];
            if ($row['local_draft_exists']) $fields[]=['История','По тому же основанию ранее сохранён локальный проект.'];
        }
        if (isset($row['created_at'])) $fields[]=['Проект сохранён',self::timestamp($row['created_at'])];
        $body.=RentalUI::pairs($fields);
        if (isset($row['question'])) $body.=RentalUI::alert('Требуется решение бухгалтера',$row['question'],'warning');
        $lines=[];
        foreach ($row['lines'] ?? $row['original']['lines'] ?? [] as $line) $lines[]=[
            View::e($line['description']),self::money($line['net'] ?? null),self::money($line['tax'] ?? null),
            self::money($line['gross'] ?? $line['amount'] ?? null),
        ];
        if ($lines) $body.=Controller::table(['Строка','Без НДС','НДС','Сумма'],$lines);
        return RentalUI::modal('Основание УПД',$body,RentalUI::button('К списку за месяц',self::route($month)),'invoice-list');
    }

    private static function timestamp(string $value): string
    {
        return (new \DateTimeImmutable($value))->format('d.m.Y H:i:s P');
    }

    private static function money(?string $amount): string
    {
        return $amount===null ? '—' : View::e(Controller::money($amount));
    }

    private static function contract(array $invoice): string
    {
        return RentalUI::button($invoice['contract_number'],'/rental/contract?id='.$invoice['contract_id']);
    }

    private static function service(array $row,string $month): string
    {
        $label=UpdRegister::TYPES[$row['kind']] ?? ($row['invoice']['kind'] ?: 'Уточнить вид');
        return !empty($row['agreement_id'])
            ? RentalUI::button($label,'/rental/service?'.http_build_query(['id'=>$row['agreement_id'],'period'=>$month]))
            : View::e($label);
    }
}
