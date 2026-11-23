<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, DB, PagePolicy, View};
use RuntimeException;

final class OnecRequestCard
{
    private const TITLE='Работа с 1С';
    private const STATES=['pending'=>'На согласовании','approved'=>'Передача разрешена','returned'=>'Возвращена менеджеру'];

    public static function page(): string
    {
        $actor=Auth::user();
        if (!$actor) throw new RuntimeException('Требуется вход.',401);
        OnecRequest::access($actor);
        $id=(int)($_GET['id']??0);
        return $id ? self::card($actor,$id) : self::registry($actor);
    }

    private static function registry(array $actor): string
    {
        $requests=Controller::query("SELECT source_key AS id,fact_json FROM rental_facts
            WHERE namespace='directory_onec_request' AND operative=1
            ORDER BY JSON_UNQUOTE(JSON_EXTRACT(fact_json,'$.submitted_at')) DESC");
        $rows=[];
        foreach ($requests as $row) {
            $request=json_decode($row['fact_json'],true,32,JSON_THROW_ON_ERROR);
            if (!OnecRequest::visible($actor,$request)) continue;
            try {
                Access::contract((int)$row['id']);
            } catch (RuntimeException $error) {
                if (!in_array($error->getCode(),[403,404],true)) throw $error;
                continue;
            }
            $snapshot=$request['snapshot'];
            $rows[]=[
                '_attrs'=>'data-rental-url="'.OnecRequest::route((int)$row['id']).'" tabindex="0"',
                View::e('Первый счёт · №'.$snapshot['number']),View::e($snapshot['party_name']),
                View::e($request['reviewer_name']),View::e(self::STATES[$request['state']]),
            ];
        }
        $body=Controller::heading(self::TITLE,'Заявки на подготовку контрагентов и первых счетов.')
            .DirectoryUi::registry('rental-onec-work',['Заявка','Клиент','Бухгалтер','Состояние'],$rows,['text','flex','text','text']);
        return View::page(self::TITLE,$body,OnecRequest::PAGE);
    }

    private static function card(array $actor,int $id): string
    {
        $contract=Access::contract($id);
        $request=OnecRequest::read(DB::pdo(),$id);
        if (($request && !OnecRequest::visible($actor,$request)) || (!$request && $actor['role']!=='manager'))
            throw new RuntimeException('Заявка недоступна.',404);
        $body=RentalUI::notice();
        $editable=PagePolicy::level('rental.'.OnecRequest::PAGE,$actor)>=2;
        $requestVersion=(int)($request['version']??0);
        $canSubmit=$actor['role']==='manager' && $editable && Auth::can('rental.write');
        $current=null;
        $problem='';
        if ($canSubmit || ($request['state']??null)==='pending') {
            try {
                $issued=$request && $request['state']!=='returned' ? $request['snapshot']['issued']
                    : (string)($_GET['issued']??$request['snapshot']['issued']??max(date('Y-m-d'),$contract['document_date']??''));
                $current=OnecRequest::snapshot(DB::pdo(),$contract,$issued);
            } catch (RuntimeException $error) {
                if ($error instanceof \PDOException) throw $error;
                $problem=$error->getMessage();
            }
        }
        $saved=$request['snapshot']??null;
        $same=$current && $saved && hash_equals($request['snapshot_sha256'],OnecRequest::hash($current));
        if ($request) {
            $body.=RentalUI::pairs([['Состояние',self::STATES[$request['state']]],['Менеджер',$request['manager_name']],['Бухгалтер',$request['reviewer_name']]]);
            if ($request['state']==='approved') {
                $body.=RentalUI::alert('Передача разрешена','Запись в 1С, получение PDF и письмо Раисе ещё не выполнены.');
            } elseif ($request['state']==='returned') {
                $body.=RentalUI::alert('Причина возврата',$request['reason'],'warning');
            } elseif (!$same) {
                $body.=RentalUI::alert('Комплект изменился',$problem?:'Передайте актуальные документы на повторное согласование.','warning');
            }
        }
        if ($problem!=='' && !$request) $body.=RentalUI::alert('Заявку пока нельзя отправить',$problem,'warning');
        $submit=$canSubmit && $current && (!$same || ($request['state']??null)==='returned');
        if ($data=$submit?$current:$saved??$current) $body.=self::preview($data);
        if ($submit) {
            $reviewers=OnecRequest::reviewers(DB::pdo());
            if (!$reviewers) $body.=RentalUI::alert('Бухгалтер не назначен','Нет активного бухгалтера с правом согласования.','warning');
            else $body.=Controller::form(OnecRequest::SUBMIT,$id,$requestVersion,
                DirectoryUi::select('reviewer_id','Кому на согласование',$reviewers,count($reviewers)===1?array_key_first($reviewers):'',true)
                .PremisesActsCard::hidden('issued',$current['issued'])
                .PremisesActsCard::hidden('snapshot_sha256',OnecRequest::hash($current)),'Передать бухгалтеру');
        }
        if ($actor['role']==='accountant' && $editable && Auth::can('finance.write') && ($request['state']??null)==='pending') {
            if ($same) $body.=Controller::form(OnecRequest::APPROVE,$id,$requestVersion,
                '<label class="registry-ui-checkbox"><input type="checkbox" name="checked" value="1" required><span>Документы и расчёт проверены</span></label>',
                'Разрешить передачу в 1С');
            $body.=Controller::form(OnecRequest::RETURN,$id,$requestVersion,Controller::input('reason','Причина возврата'),'Вернуть менеджеру');
        }
        return RentalUI::modal(self::TITLE.' · договор № '.$contract['number'],$body,'',OnecRequest::PAGE,wide:true);
    }

    private static function preview(array $snapshot): string
    {
        $body=RentalUI::pairs([
            ['Клиент',$snapshot['party_name']],['ИНН',$snapshot['party_inn']??'—'],
            ['Дата счёта',Documents::dateText($snapshot['issued'])],
        ]);
        foreach ($snapshot['documents'] as $file) {
            $body.='<div class="demo-row"><a class="btn btn-secondary" href="/rental/download?id='.View::e($file['id']).'">'
                .View::e($file['filename']).'</a></div>';
        }
        $rows=[];
        foreach ($snapshot['invoices'] as $invoice) {
            $period=$invoice['kind']==='Обеспечительный платёж'?'Обеспечение'
                :Documents::dateText($invoice['from']).' — '.Documents::dateText($invoice['to']);
            $rows[]=[View::e($invoice['kind']),View::e($period),Controller::money($invoice['amount']),
                $invoice['due']?View::e(Documents::dateText($invoice['due'])):'Требует подтверждения'];
        }
        return $body.Controller::table(['Начисление','Период','Сумма с НДС','Срок оплаты'],$rows);
    }
}
