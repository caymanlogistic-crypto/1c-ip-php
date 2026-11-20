<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Auth, DB, Http, View};
use PDO;
use RuntimeException;

final class ReturnAct
{
    public const KIND = 'signed-return-act';

    public static function confirmed(PDO $db, array $contract): ?array
    {
        if (empty($contract['return_confirmed'])) return null;
        $id = (int)$contract['id'];
        $query = $db->prepare("SELECT after_json FROM audit_log WHERE entity_type='contract' AND entity_id=?
            AND action='contract.return-act' ORDER BY id DESC LIMIT 1");
        $query->execute([(string)$id]);
        $receipt = json_decode((string)$query->fetchColumn(), true)['return_act'] ?? null;
        if ($receipt === null) {
            $file = Evidence::latest($db, 'contract', $id, self::KIND);
            $query = $db->prepare("SELECT 1 FROM audit_log WHERE entity_type='contract' AND entity_id=? AND action='document.upload'
                AND JSON_UNQUOTE(JSON_EXTRACT(after_json,'$.file'))=?
                AND JSON_UNQUOTE(JSON_EXTRACT(after_json,'$.return_act_pending'))='true' LIMIT 1");
            $query->execute([(string)$id, $file['id']]);
            return $query->fetchColumn() ? null : $file;
        }
        $query = $db->prepare("SELECT * FROM rental_files WHERE id=? AND entity_type='contract' AND entity_id=? AND kind=?");
        $query->execute([$receipt['file_id'], $id, self::KIND]);
        $file = $query->fetch();
        if (!$file || $receipt['return_date'] !== $contract['return_confirmed']
            || (int)$file['version'] !== $receipt['version'] || !hash_equals($receipt['sha256'], $file['sha256'])
            || !hash_equals($receipt['baseline_hash'], (string)$file['baseline_hash'])) {
            throw new RuntimeException('Подтверждённый акт возврата не найден или изменён.', 409);
        }
        self::assertIntegrity($file);
        return $file;
    }

    private static function currentConfirmation(PDO $db, array $contract, ?string $baseline): ?array
    {
        $file = self::confirmed($db, $contract);
        if (!$file || $contract['status'] === 'closed') return $file;
        return hash_equals((string)$file['baseline_hash'], $baseline ?? Evidence::signature($db, (int)$contract['id'])) ? $file : null;
    }

    private static function candidate(PDO $db, array $contract, ?string $baseline): array
    {
        try {
            $file = Evidence::latest($db, 'contract', (int)$contract['id'], self::KIND);
        } catch (RuntimeException $e) {
            if ($e instanceof \PDOException || $e->getCode() === 404 || $e->getCode() === 409) throw $e;
            throw new RuntimeException('Сохранённый акт возврата не прошёл проверку целостности. Загрузите исправный файл.', 409, $e);
        }
        if ((int)$file['version'] !== (int)$contract['version']
            || !hash_equals((string)$file['baseline_hash'], $baseline ?? Evidence::signature($db, (int)$contract['id']))) {
            throw new RuntimeException('Акт возврата относится к прежней версии договора или данным сверки. Загрузите актуальный файл.', 409);
        }
        return $file;
    }

    private static function assertIntegrity(array $file): void
    {
        try {
            Evidence::integrity($file);
        } catch (RuntimeException $e) {
            throw new RuntimeException('Сохранённый акт возврата не прошёл проверку целостности.', 409, $e);
        }
    }

    public static function state(PDO $db, array $contract, ?string $baseline = null): array
    {
        try {
            $confirmed = self::currentConfirmation($db, $contract, $baseline);
        } catch (RuntimeException $e) {
            if ($e instanceof \PDOException) throw $e;
            if ($contract['status'] === 'closed') return ['file'=>null, 'confirmed'=>false, 'message'=>$e->getMessage()];
        }
        if (isset($confirmed)) return ['file'=>$confirmed, 'confirmed'=>true, 'message'=>'Акт возврата подтверждён'];
        if ($contract['status'] !== 'reconciling') return ['file'=>null, 'confirmed'=>false, 'message'=>'Акт возврата не подтверждён'];
        try {
            return ['file'=>self::candidate($db, $contract, $baseline), 'confirmed'=>false, 'message'=>'Файл загружен. Ожидается подтверждение.'];
        } catch (RuntimeException $e) {
            if ($e instanceof \PDOException) throw $e;
            return ['file'=>null, 'confirmed'=>false, 'message'=>$e->getMessage()];
        }
    }

    public static function summary(array $state): string
    {
        $file = $state['file'];
        $html = RentalUI::pairs([
            ['Акт возврата', $state['message']],
            ...($file ? [['Сохранённый файл', $file['filename']]] : []),
        ]);
        if ($file) {
            $html .= '<div><a class="btn btn-secondary" href="/rental/download?id='.View::e($file['id']).'">Открыть сохранённый акт</a></div>';
        }
        return $html;
    }

    public static function form(array $contract): string
    {
        Service::roles(['manager']);
        Auth::requirePermission('rental.write');
        Access::contract((int)$contract['id'], 2);
        if ($contract['status'] !== 'reconciling') throw new RuntimeException('Договор не на сверке.', 409);
        $state = self::state(DB::pdo(), $contract);
        if ($state['file'] && ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'ERP-Fetch') {
            header('X-Rental-Refresh-URL: '.FinanceController::workspaceTarget($_SERVER['HTTP_X_RENTAL_WORKSPACE_URL'] ?? null, '/rental/reconciliation'));
        }
        $body = self::summary($state);
        if ($state['confirmed']) return RentalUI::modal('Акт возврата помещения', $body, '', 'reconciliation');
        $token = View::e(Http::csrf());
        $common = '<input type="hidden" name="_csrf" value="'.$token.'">'
            .'<input type="hidden" name="id" value="'.(int)$contract['id'].'">'
            .'<input type="hidden" name="version" value="'.(int)$contract['version'].'">';
        $body .= '<form id="return-act-upload" class="registry-ui-modal-grid" enctype="multipart/form-data" method="post" action="/rental/upload">'
            .$common.'<input type="hidden" name="entity_type" value="contract">'
            .'<input type="hidden" name="kind" value="'.self::KIND.'">'
            .'<label class="field"><span class="field-label">Подписанный акт возврата</span>'
            .'<input type="file" name="file" accept=".doc,.docx,.pdf" required aria-describedby="return-act-upload-help"></label>'
            .'<span class="field-msg" id="return-act-upload-help">PDF, DOC или DOCX до 20 МБ. Чтобы сохранить выбранный файл, нажмите «Загрузить файл».</span></form>';
        $actions = '<button class="btn btn-secondary" type="submit" form="return-act-upload">Загрузить файл</button>';
        if ($state['file']) {
            $body .= '<form id="return-act-confirm" class="registry-ui-modal-grid" method="post" action="/rental/action">'
                .$common.'<input type="hidden" name="action" value="contract.return-act">'
                .'<input type="hidden" name="command_key" value="'.bin2hex(random_bytes(16)).'">'
                .'<input type="hidden" name="file_id" value="'.View::e($state['file']['id']).'">'
                .Controller::input('return_date', 'Дата фактического возврата', $contract['finish_date'] ?? date('Y-m-d'), 'date', true)
                .'<label class="registry-ui-checkbox"><input type="checkbox" name="both_signed" value="1" required>'
                .'<span>Акт подписан обеими сторонами</span></label></form>';
            $actions .= '<button class="btn btn-primary" type="submit" form="return-act-confirm">Подтвердить акт</button>';
        }
        return RentalUI::modal('Акт возврата помещения', $body, $actions, 'reconciliation');
    }
}
