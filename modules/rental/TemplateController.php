<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth,DB,Http,PagePolicy,View};
use RuntimeException;

final class TemplateController
{
    public static function listing(): string
    {
        self::access();
        $query=trim((string)($_GET['q']??''));
        $rows=[];
        $editable=self::editable();
        foreach (OriginalDocuments::ownerKinds() as $kind=>$title) {
            if (!WorkRegistry::matches($query,[$title,'исходник владельца'])) continue;
            $row=[View::e($title),self::download($kind)];
            if ($editable) $row[]=self::editButton($kind);
            $rows[]=$row;
        }
        $headers=$editable?['Шаблон','Текущий файл','Действия']:['Шаблон','Текущий файл'];
        $columns=$editable?['flex','text','text']:['flex','text'];
        return View::page('Шаблоны документов',
            Controller::heading('Шаблоны документов','Исходники Word для формирования документов.')
            .RentalUI::notice().DirectoryUi::registry('word-templates',$headers,$rows,$columns,DirectoryUi::search($query,true),filterForm:true),'templates');
    }

    private static function access(): void
    {
        Auth::requirePermission('rental.read');
        Access::pages(['templates']);
        Service::roles(['lawyer','director']);
    }

    private static function editable(): bool
    {
        $user=Auth::user();
        return in_array($user['role'],['lawyer','admin'],true)
            && Auth::can('rental.approve.legal') && PagePolicy::level('rental.templates',$user)>=2;
    }

    private static function title(string $kind): string
    {
        return OriginalDocuments::ownerKinds()[$kind]??throw new RuntimeException('Шаблон не найден.',404);
    }

    private static function download(string $kind): string
    {
        return '<a class="btn btn-secondary" href="/rental/template-base?original='.$kind.'" download>Скачать DOCX</a>';
    }

    private static function editButton(string $kind): string
    {
        return RentalUI::button('Заменить DOCX','/rental/template-editor?kind='.$kind);
    }

    public static function card(): string
    {
        self::access();
        $kind=(string)($_GET['kind']??'');
        $title=self::title($kind);
        $body=RentalUI::notice();
        $actions=self::download($kind).(self::editable()?self::editButton($kind):'');
        return RentalUI::modal($title,$body,$actions,'templates');
    }

    public static function editor(): string
    {
        self::access();
        if (!self::editable()) throw new RuntimeException('Недостаточно прав для замены шаблона.',403);
        $kind=(string)($_GET['kind']??'');
        $title=self::title($kind);
        $form='owner-template-replace';
        $body=RentalUI::notice()
            .'<form id="'.$form.'" method="post" action="/rental/template-save" enctype="multipart/form-data" class="registry-ui-form-section">'
            .PremisesActsCard::hidden('_csrf',Http::csrf())
            .PremisesActsCard::hidden('kind',$kind)
            .PremisesActsCard::hidden('command_key',bin2hex(random_bytes(16)))
            .PremisesActsCard::hidden('expected_sha256',OwnerWordTemplates::digest(DB::pdo(),$kind))
            .'<label class="file-dropzone registry-ui-review-upload" data-file-dropzone>'
            .'<span class="dz-title">Перетащите сюда шаблон Word</span>'
            .'<span class="btn btn-secondary">Выбрать файл</span>'
            .'<span class="dz-note" id="owner-template-help">DOCX до 5 МБ. Сохраните поля {{...}} для подстановки данных.</span>'
            .'<input type="file" name="template_file" accept=".docx" aria-label="Выбрать шаблон Word"'
            .' aria-describedby="owner-template-help" required>'
            .'<span class="registry-ui-modal-help" data-file-dropzone-selection hidden></span></label>'
            .'<p class="registry-ui-modal-help">Новый файл сразу используется при формировании документов.</p></form>';
        return RentalUI::modal('Заменить шаблон · '.$title,$body,
            '<button class="btn btn-primary" type="submit" form="'.$form.'">Заменить DOCX</button>','templates');
    }

    public static function save(): never
    {
        self::access();
        if (!self::editable()) throw new RuntimeException('Недостаточно прав для замены шаблона.',403);
        Http::checkCsrf();
        $kind=(string)($_POST['kind']??'');
        self::title($kind);
        try {
            $file=$_FILES['template_file']??[];
            if (($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK || !is_string($file['tmp_name']??null)
                || !is_uploaded_file($file['tmp_name']) || strtolower(pathinfo($file['name'],PATHINFO_EXTENSION))!=='docx') {
                throw new RuntimeException('Выберите файл DOCX до 5 МБ.',422);
            }
            $bytes=file_get_contents($file['tmp_name'],false,null,0,OwnerWordTemplates::MAX_BYTES+1);
            if ($bytes===false || strlen($bytes)>OwnerWordTemplates::MAX_BYTES) throw new RuntimeException('Выберите файл DOCX до 5 МБ.',422);
            $result=Service::command(OwnerWordTemplates::REPLACE,[
                'kind'=>$kind,'expected_sha256'=>$_POST['expected_sha256']??'',
                'command_key'=>$_POST['command_key']??'','content'=>base64_encode($bytes),
            ]);
            $_SESSION['rental_notice']=$result['message'];
            if (($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='ERP-Fetch') {
                $workspace=FinanceController::workspaceTarget($_SERVER['HTTP_X_RENTAL_WORKSPACE_URL']??null,'/rental/templates');
                if (parse_url($workspace,PHP_URL_PATH)!=='/rental/templates') $workspace='/rental/templates';
                header('X-Rental-Completion-URL: '.$workspace);
                header('Content-Type: text/html; charset=UTF-8');
                exit;
            }
            Http::redirect('/rental/templates');
        } catch (\Throwable $error) {
            if (in_array($error->getCode(),[401,403],true)) throw $error;
            $message=$error instanceof \PDOException?'Не удалось заменить Word-шаблон.':$error->getMessage();
            if (($_SERVER['HTTP_X_REQUESTED_WITH']??'')==='ERP-Fetch') {
                http_response_code($error->getCode()===409?409:422);
                echo RentalUI::alert($message,'','error');
                exit;
            }
            $_SESSION['rental_notice']=$message;
            Http::redirect('/rental/template-editor?kind='.$kind);
        }
    }

    public static function base(): never
    {
        self::access();
        $kind=(string)($_GET['original']??'');
        self::title($kind);
        $source=OriginalDocuments::source($kind,DB::pdo());
        $bytes=$source['bytes']??file_get_contents($source['path']);
        if ($bytes===false) throw new RuntimeException('Текущий Word-шаблон недоступен.',409);
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="original-'.$kind.'.docx"');
        header('Content-Length: '.strlen($bytes));
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: private, no-store');
        echo $bytes;
        exit;
    }
}
