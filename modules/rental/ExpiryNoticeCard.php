<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{DB,Http,View};
use RuntimeException;

final class ExpiryNoticeCard
{
    private const TEMPLATES=[
        'D26'=>['expiry-d26.docx','14d894b3f2f7f8c3e12b95288268d784454b74573175b4b00f5b783d5362b067'],
        'D27'=>['expiry-d27.docx','a709e4316fe71e5a1166c1ff3331cae7cdd0add783d805d78f808290d3d16c6f'],
    ];

    public static function route(int $id,string $end=''): string
    {
        return '/rental/expiry-notice?'.http_build_query(array_filter(['id'=>$id,'end'=>$end]));
    }

    public static function entry(array $contract): string
    {
        if (!ExpiryNotice::canRead()) return '';
        $links=[];
        foreach (ExpiryNotice::all(DB::pdo(),(int)$contract['id']) as $notice) {
            $links[]=RentalUI::button('Уведомление до '.Documents::dateText($notice['old_end']),self::route((int)$contract['id'],$notice['old_end']));
        }
        if ($contract['status']==='active' && $contract['end_date']
            && !ExpiryNotice::read(DB::pdo(),(int)$contract['id'],$contract['end_date']) && ExpiryNotice::canWrite($contract))
            $links[]=RentalUI::button('Уведомление об окончании',self::route((int)$contract['id'],$contract['end_date']));
        if ($prior=ExpiryContinuation::origin($contract)) {
            try {
                $previous=Access::contract($prior['contract_id']);
                $links[]=RentalUI::button('Предыдущий договор № '.$previous['number'],'/rental/contract?id='.$previous['id']);
            } catch (RuntimeException $error) {
                if ($error instanceof \PDOException || !in_array($error->getCode(),[403,404],true)) throw $error;
            }
        }
        return $links ? '<div class="demo-row">'.implode('',$links).'</div>' : '';
    }

    public static function page(): string
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET')==='POST') return self::save();
        $id=(int)($_GET['id'] ?? 0);
        $contract=ExpiryNotice::access($id);
        $end=Service::date((string)($_GET['end'] ?? $contract['end_date']));
        $notice=ExpiryNotice::read(DB::pdo(),$id,$end);
        if (isset($_GET['download'])) {
            if (!$notice) throw new RuntimeException('Проект уведомления не найден.',404);
            self::download($notice);
        }
        return self::content($contract,$end,$notice);
    }

    private static function save(): string
    {
        $id=(int)($_POST['id'] ?? 0);
        ExpiryNotice::access($id,2);
        Http::checkCsrf();
        $input=$_POST;
        unset($input['_csrf']);
        $end=Service::date((string)($input['notice_end'] ?? ''));
        $action=(string)($input['action'] ?? '');
        if (!in_array($action,ExpiryNotice::ACTIONS,true)) throw new RuntimeException('Неизвестное действие уведомления.',422);
        try {$result=Service::command($action,$input);}
        catch (RuntimeException $error) {
            if ($error instanceof \PDOException || !in_array($error->getCode(),[409,422],true)) throw $error;
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')==='ERP-Fetch') {
                http_response_code($error->getCode());
                return RentalUI::alert($error->getMessage(),'','error');
            }
            $result=['message'=>$error->getMessage()];
        }
        $_SESSION['rental_notice']=$result['message'];
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')!=='ERP-Fetch') Http::redirect(self::route($id,$end));
        return self::content(ExpiryNotice::access($id),$end,ExpiryNotice::read(DB::pdo(),$id,$end));
    }

    private static function content(array $contract,string $end,array $notice): string
    {
        $id=(int)$contract['id'];
        $current=$contract['status']==='active' && $contract['end_date']===$end;
        $write=ExpiryNotice::canWrite($contract);
        if (!$notice && (!$current || !$write)) throw new RuntimeException('Нет подготовленного уведомления по этому сроку.',404);
        $offer=$notice['offer'] ?? [];
        $state=$notice['state'] ?? 'draft';
        $fields=[['Договор',$contract['number']],['Окончание срока в уведомлении',Documents::dateText($end)],
            ['Ставка действующего договора',Controller::money($contract['amount'])],
            ['Состояние',match($state){'sent'=>'Отправка подтверждена','replied'=>'Получен ответ',default=>'Проект · не отправлен'}]];
        if ($offer) $fields=[
            ...$fields,['Форма',$offer['template']],['Новый срок',Documents::dateText($offer['new_start']).' — '.Documents::dateText($offer['new_end'])],
            ['Прежняя ставка',Controller::money($offer['old_amount'])],['Предложенная ставка',Controller::money($offer['new_amount'])],
            ['Канал',ExpiryNotice::CHANNELS[$offer['channel']]],['Адресат',$offer['recipient']],
        ];
        if (isset($offer['percent'])) $fields[]=['Согласованный процент',$offer['percent'].' %'];
        if (isset($notice['sent'])) $fields=[
            ...$fields,['Фактическая отправка',self::time($notice['sent']['when'])],
            ['Подтверждение отправки',$notice['sent']['reference']],['Для MAX','Внутренний факт подготовлен'],
        ];
        if (isset($notice['response'])) $fields=[
            ...$fields,['Дата ответа',self::time($notice['response']['when'])],['Ответ арендатора',$notice['response']['text']],
            ['Дальнейшее решение',ExpiryNotice::DECISIONS[$notice['response']['decision']]],
        ];
        $body=RentalUI::notice().RentalUI::pairs($fields);
        if ($offer) {
            $url=self::route($id,$end).'&download=1';
            $body.='<div class="demo-row"><a class="btn btn-secondary" href="'.View::e($url).'" download>Скачать Word · '.View::e($offer['template']).'</a></div>';
        }
        $files=$offer?ExpiryNotice::files(DB::pdo(),$notice):[];
        if (isset($notice['sent'])) {
            $file=$files[$notice['sent']['file_id']] ?? null;
            if ($file) $body.='<div class="demo-row">'.Controller::link('/rental/download?id='.$file['id'],'Доказательство · '.$file['filename']).'</div>';
        }
        $actions='';
        if ($write && $state==='draft' && $current) {
            if (!$offer || isset($_GET['edit'])) {
                $body.=self::offerForm($contract,$end,$notice);
                $actions=self::submit('expiry-offer','Сохранить проект');
            } else {
                $body.=self::uploadForm($contract,$end,$notice).self::sentForm($contract,$end,$notice,$files);
                $actions=RentalUI::button('Изменить проект',self::route($id,$end).'&edit=1');
                if ($files) $actions.=self::submit('expiry-sent','Зафиксировать отправку');
            }
        } elseif ($write && $state==='sent') {
            $body.=self::form('expiry-response',ExpiryNotice::RESPONSE,$contract,$end,$notice)
                .Controller::input('answered_at','Когда получен ответ, МСК','','datetime-local',true)
                .'<label class="field"><span class="field-label">Ответ арендатора</span><textarea name="response" class="field-input" required maxlength="1200"></textarea></label>'
                .DirectoryUi::select('decision','Решение',[''=>'Выберите после сверки']+ExpiryNotice::DECISIONS,'',true).'</form>';
            $actions=self::submit('expiry-response','Сохранить ответ и решение');
        } elseif ($state==='replied') {
            $body.=ExpiryContinuation::links($contract,$notice,$write);
        }
        $actions.=RentalUI::button('К договору','/rental/contract?id='.$id);
        return RentalUI::modal('Уведомление об окончании срока',$body,$actions,'contracts-active',wide:true,extractForms:false);
    }

    private static function form(string $form,string $action,array $contract,string $end,array $notice): string
    {
        return '<form method="post" action="'.self::route((int)$contract['id'],$end).'" id="'.$form.'" class="registry-ui-modal-grid">'
            .PremisesActsCard::hidden('_csrf',Http::csrf()).PremisesActsCard::hidden('action',$action)
            .PremisesActsCard::hidden('command_key',bin2hex(random_bytes(16))).PremisesActsCard::hidden('id',$contract['id'])
            .PremisesActsCard::hidden('version',$contract['version']).PremisesActsCard::hidden('notice_end',$end)
            .PremisesActsCard::hidden('notice_version',$notice['version'] ?? 0);
    }

    private static function offerForm(array $contract,string $end,array $notice): string
    {
        $v=$notice['offer'] ?? [];
        $party=Controller::query('SELECT * FROM parties WHERE id=?',[$contract['party_id']])[0];
        $channels=ExpiryNotice::availableChannels(DB::pdo(),$party);
        $body=self::form('expiry-offer',ExpiryNotice::PREPARE,$contract,$end,$notice)
            .'<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">'
            .Controller::input('notice_number','Исходящий номер',$v['number'] ?? '','text',true)
            .Controller::input('notice_date','Дата уведомления',$v['notice_date'] ?? date('Y-m-d'),'date',true)
            .Controller::input('new_end','Окончание нового срока',$v['new_end'] ?? '','date',true)
            .Controller::input('response_due','Срок ответа',$v['response_due'] ?? '','date',true).'</div>'
            .RentalUI::pairs([['Начало нового срока',Documents::dateText(ExpiryNotice::nextDay($end))]])
            .DirectoryUi::select('price_mode','Цена нового срока',ExpiryNotice::MODES,$v['price_mode'] ?? 'same',true)
            .Controller::input('new_amount','Сумма в месяц, если выбрана новая цена',($v['price_mode'] ?? '')==='amount' ? ($v['new_amount'] ?? '') : '','text',false)
            .Controller::input('percent','Явно согласованный процент',$v['percent'] ?? '','text',false)
            .Controller::input('price_basis','Основание выбранной новой цены',$v['price_basis'] ?? '','text',false)
            .'<label class="registry-ui-checkbox"><input type="checkbox" name="percent_confirmed" value="1"'.(isset($v['percent'])?' checked':'').'><span>Процент подтверждён документом</span></label>'
            .DirectoryUi::select('channel','Канал',[''=>'Выберите канал']+$channels,$v['channel'] ?? (count($channels)===1?'edo':''),true)
            .(count($channels)===1?'<p class="registry-ui-secondary">Клиент работает по ЭДО. Почтовый дубль уведомления не предусмотрен.</p>':'')
            .Controller::input('recipient','Адресат или идентификатор получателя',$v['recipient'] ?? '','text',true)
            .Controller::input('signer','Подписант для проекта',$v['signer'] ?? '','text',true);
        foreach ([
            'recipient_title'=>'Должность адресата','renewal_basis'=>'Подтверждённое основание для предложения о новом сроке',
            'notice_basis'=>'Условие договора об уведомлении','payment_terms'=>'Согласованный порядок оплаты',
            'security_terms'=>'Условие об обеспечении','utilities_terms'=>'Условие о коммунальных услугах','index_terms'=>'Оговорка об индексации',
        ] as $name=>$label) $body.=Controller::input($name,$label,$v[$name] ?? '','text',false);
        return $body.'</form>';
    }

    private static function uploadForm(array $contract,string $end,array $notice): string
    {
        return '<form method="post" action="/rental/upload" enctype="multipart/form-data" class="registry-ui-modal-grid">'
            .PremisesActsCard::hidden('_csrf',Http::csrf()).PremisesActsCard::hidden('id',$contract['id'])
            .PremisesActsCard::hidden('version',$contract['version']).PremisesActsCard::hidden('kind',ExpiryNotice::FILE)
            .PremisesActsCard::hidden('notice_end',$end).PremisesActsCard::hidden('notice_version',$notice['version'])
            .'<label class="field"><span class="field-label">Файл доказательства отправки</span><input type="file" name="file" class="field-input" accept=".pdf,.doc,.docx" required></label>'
            .'<button type="submit" class="btn btn-secondary">Загрузить доказательство</button></form>';
    }

    private static function sentForm(array $contract,string $end,array $notice,array $files): string
    {
        if (!$files) return '';
        $choices=[];
        foreach ($files as $file) $choices[$file['id']]=$file['filename'];
        return self::form('expiry-sent',ExpiryNotice::SENT,$contract,$end,$notice)
            .DirectoryUi::select('file_id','Подтверждающий файл',[''=>'Выберите файл']+$choices,'',true)
            .Controller::input('sent_at','Когда отправлено, МСК','','datetime-local',true)
            .Controller::input('reference','Идентификатор или реквизиты фактической отправки','','text',true)
            .'<label class="registry-ui-checkbox"><input type="checkbox" name="confirmed" value="1" required><span>Сверил факт, канал и адресата по доказательству</span></label></form>';
    }

    private static function submit(string $form,string $label): string
    {
        return '<button type="submit" class="btn btn-primary" form="'.$form.'">'.View::e($label).'</button>';
    }

    private static function time(string $value): string
    {
        return (new \DateTimeImmutable($value))->format('d.m.Y H:i').' МСК';
    }

    private static function download(array $notice): never
    {
        $offer=$notice['offer'];
        [$name,$sha]=self::TEMPLATES[$offer['template']];
        $path=__DIR__.'/templates/'.$name;
        if (!hash_equals($sha,hash_file('sha256',$path))) throw new RuntimeException('Исходник уведомления изменился.',409);
        $values=array_intersect_key($offer,array_flip(DocxTemplate::fields($path)));
        foreach (['notice_date','contract_date','old_end','new_start','new_end','response_due'] as $field) {
            $values[$field]=Documents::dateText($offer[$field]);
        }
        $values['new_amount']=Documents::amountText($offer['new_amount']).' ₽';
        if (isset($values['price_basis']) && $offer['percent']!==null)
            $values['price_basis']='Согласованное изменение '.$offer['percent'].' %. '.$values['price_basis'];
        $target=tempnam(APP_ROOT.'/runtime/tmp','expiry-');
        if ($target===false) throw new RuntimeException('Не удалось подготовить Word.');
        try {
            DocxTemplate::build($path,$values,'expiry',$target,['landlord','number','notice_date','party','contract_number','old_end','new_start','new_end','new_amount','signer']);
            header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
            header('Content-Disposition: attachment; filename="notice-'.$offer['template'].'.docx"');
            header('Cache-Control: private, no-store');
            header('X-Content-Type-Options: nosniff');
            readfile($target);
        } finally {if (is_file($target)) unlink($target);}
        exit;
    }
}
