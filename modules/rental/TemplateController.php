<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth,DB,Http,PagePolicy,View};
use PDO;
use RuntimeException;

final class TemplateController
{
    private static function record(int $id,int $level=1): array
    {
        Access::pages(['templates'],$level);
        Service::roles(['lawyer','director']);
        return Controller::query('SELECT * FROM rental_templates WHERE id=?',[$id])[0]
            ?? throw new RuntimeException('Шаблон не найден.',404);
    }

    public static function card(): string
    {
        $template=self::record((int)($_GET['id'] ?? 0));
        $body=RentalUI::pairs([
            ['Действующая версия',$template['version'] ?: 'Ещё не утверждена'],
            ['Новая версия',$template['pending_version'] ?: 'Нет новой версии'],
            ['Решение',match($template['status']) {
                'pending'=>'Ожидает согласования руководителя',
                'returned'=>$template['reason'],
                default=>'Утвердил: '.($template['approved_by'] ?: \App\People::workflowName('director')),
            }],
        ]);
        if ($template['pending_version']) $body.='<span class="registry-ui-modal-summary">Новая редакция</span>';
        $body.='<span class="registry-ui-modal-text text-prewrap">'.View::e($template['pending_version'] ? $template['pending_body'] : ($template['body'] ?: 'Текст шаблона не загружен')).'</span>';
        $actions=''; $user=Auth::user();
        if (PagePolicy::level('rental.templates',$user)>=2) {
            if (in_array($user['role'],['admin','lawyer'],true) && Auth::can('rental.approve.legal')) {
                $actions.=RentalUI::button($template['pending_version']?'Изменить и отправить':'Подготовить новую версию','/rental/template-editor?id='.$template['id'],true);
            }
            if (in_array($user['role'],['admin','director'],true) && Auth::can('rental.approve.executive') && $template['status']==='pending') {
                $actions.=RentalUI::button('Вернуть с замечаниями','/rental/template-decision?id='.$template['id'].'&action=template.return');
                $form='template-approve-'.bin2hex(random_bytes(6));
                $body.=self::formOpen($form,'template.approve',$template).'</form>';
                $actions.='<button class="btn btn-primary" type="submit" form="'.$form.'">Утвердить версию</button>';
            }
        }
        return RentalUI::modal($template['name'],$body,$actions,'templates');
    }

    private static function formOpen(string $form,string $action,array $template=[]): string
    {
        return '<form id="'.$form.'" method="post" action="/rental/template-save" enctype="multipart/form-data">'
            .'<input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'">'
            .'<input type="hidden" name="command_key" value="'.bin2hex(random_bytes(16)).'">'
            .'<input type="hidden" name="action" value="'.$action.'">'
            .'<input type="hidden" name="id" value="'.($template['id'] ?? 0).'">'
            .'<input type="hidden" name="version" value="'.($template['record_version'] ?? 1).'">';
    }

    public static function editor(): string
    {
        Auth::requirePermission('rental.approve.legal'); Service::roles(['lawyer']);
        Access::pages(['templates'],2);
        $id=(int)($_GET['id'] ?? 0);
        $template=$id ? self::record($id,2) : [];
        $hasSource=!empty($template['pending_file_id']) || !empty($template['word_file_id']);
        if (!$hasSource && $template) {
            try { TemplateAssets::source(DB::pdo(),$template); $hasSource=true; }
            catch (RuntimeException) { $hasSource=false; }
        }
        $form='template-editor-'.bin2hex(random_bytes(6));
        $body=self::formOpen($form,'template.edit',$template)
            .Controller::input('name','Название шаблона',$template['name'] ?? '','text',true)
            .'<label class="field"><span class="field-label">Дополнительные условия</span>'
            .'<textarea class="field-textarea" name="body" rows="6">'.View::e($template['pending_body'] ?? $template['body'] ?? '').'</textarea></label>'
            .'<label class="field"><span class="field-label">Файл шаблона Word .docx</span>'
            .'<input class="field-input" type="file" name="template_file" accept=".docx"'.(!$hasSource?' required':'').'></label>'
            .'<p class="registry-ui-modal-help">Word-шаблон сохраняется целиком. Для подстановки условий используйте поля {{number}}, {{date}}, {{party}}, {{organization}}, {{terms}}, {{total}}, {{vat}}; для ДС также {{base}} и {{effective}}. Текст и оформление остальных абзацев сохраняются.</p><div class="demo-row">';
        foreach (['office'=>'офис','parking'=>'машиноместо','mixed'=>'смешанный','addendum'=>'ДС'] as $kind=>$label) {
            $body.='<a class="btn btn-secondary" href="/rental/template-base?kind='.$kind.'" download>Word: '.$label.'</a>';
        }
        $body.='</div></form>';
        return RentalUI::modal($id?'Новая версия шаблона':'Создать шаблон',$body,
            '<button class="btn btn-primary" type="submit" form="'.$form.'">Отправить руководителю</button>','templates');
    }

    public static function decision(): string
    {
        Auth::requirePermission('rental.approve.executive'); Service::roles(['director']);
        $template=self::record((int)($_GET['id'] ?? 0),2);
        if (($_GET['action'] ?? '')!=='template.return' || !$template['pending_version']) throw new RuntimeException('Новая версия не найдена.',404);
        $form='template-return-'.bin2hex(random_bytes(6));
        return RentalUI::modal('Вернуть шаблон юристу',self::formOpen($form,'template.return',$template)
            .Controller::input('reason','Причина','','text',true).'</form>',
            '<button class="btn btn-primary" type="submit" form="'.$form.'">Вернуть</button>','templates');
    }

    public static function persist(array $input,?array $upload=null): array
    {
        $action=(string)($input['action'] ?? '');
        if ($action!=='template.edit') return Service::command($action,$input);
        Auth::requirePermission('rental.read'); Auth::requirePermission('rental.approve.legal');
        Service::roles(['lawyer']); PagePolicy::requireCommand('template.edit');
        $stored=null;
        try {
            return DB::transaction(static function(PDO $db) use ($action,$input,$upload,&$stored): array {
                FileOrder::lock($db);
                $id=(int)($input['id'] ?? 0);
                $query=$db->prepare('SELECT result_json FROM rental_commands WHERE command_key=?');
                $query->execute([$input['command_key'] ?? '']);
                $receipt=$query->fetchColumn();
                if (!$id) {
                    $id=$receipt ? (int)(json_decode($receipt,true)['id'] ?? 0)
                        : (int)$db->query('SELECT GREATEST(COALESCE(MAX(id),0),900000)+1 FROM rental_templates')->fetchColumn();
                    $input['id']=$id; $input['new_template']=1;
                } else {
                    $template=Service::get($db,'rental_templates',$id);
                    if (!$receipt && (int)$template['record_version']!==(int)($input['version'] ?? 0)) throw new RuntimeException('Шаблон уже изменён.',409);
                }
                if ($upload && ($upload['error'] ?? UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_NO_FILE) {
                    if ($upload['error']!==UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) throw new RuntimeException('Не удалось загрузить Word-шаблон.',422);
                    $input['attachment_sha256']=hash_file('sha256',$upload['tmp_name']);
                    $stored=TemplateAssets::store($db,$id,1,$upload['tmp_name'],$upload['name']);
                    $input['word_file_id']=$stored['id'];
                }
                return Service::command($action,$input);
            });
        } catch (\Throwable $error) {
            if ($stored && $stored['created'] && is_file($stored['path'])) unlink($stored['path']);
            throw $error;
        }
    }

    public static function save(): never
    {
        Http::checkCsrf(); $input=$_POST; unset($input['_csrf']);
        try {
            $result=self::persist($input,$_FILES['template_file'] ?? null);
            $_SESSION['rental_notice']=$result['message'];
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')==='ERP-Fetch') {
                $workspace=FinanceController::workspaceTarget($_SERVER['HTTP_X_RENTAL_WORKSPACE_URL'] ?? null,'/rental/templates');
                if (parse_url($workspace,PHP_URL_PATH)!=='/rental/templates') $workspace='/rental/templates';
                header('X-Rental-Refresh-URL: '.$workspace);
                header('Content-Type: text/html; charset=UTF-8');
                $_GET['id']=$result['id'];
                echo self::card();
                exit;
            }
            Http::redirect('/rental/template?id='.$result['id']);
        } catch (\Throwable $error) {
            if (in_array($error->getCode(),[401,403],true)) throw $error;
            if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')==='ERP-Fetch') {
                http_response_code($error->getCode()===409?409:422);
                echo RentalUI::alert($error instanceof \PDOException?'Не удалось сохранить версию шаблона.':$error->getMessage(),'','error');
                exit;
            }
            $_SESSION['rental_notice']=$error->getMessage();
            Http::redirect('/rental/templates');
        }
    }

    public static function base(): never
    {
        Access::pages(['templates']); Service::roles(['lawyer','director']);
        $kind=(string)($_GET['kind'] ?? '');
        $definition=TemplateAssets::KINDS[$kind] ?? throw new RuntimeException('Шаблон не найден.',404);
        $path=__DIR__.'/templates/'.$kind.'.docx';
        if (!hash_equals($definition['sha256'],hash_file('sha256',$path))) throw new RuntimeException('Исходный шаблон изменился.');
        header('Content-Type: application/vnd.openxmlformats-officedocument.wordprocessingml.document');
        header('Content-Disposition: attachment; filename="'.$kind.'.docx"');
        header('X-Content-Type-Options: nosniff'); readfile($path); exit;
    }
}
