<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit, Auth, DB, Http, View};
use PDO;
use RuntimeException;

final class ReturnAct
{
    public const KIND = 'signed-return-act';
    public const ONE_FILE='one-sided-return-act';
    public const RECORD_ONE='closing.return-act-one-sided';
    private const SIGNATURES='rental_return_act_signature';

    public static function receipt(PDO $db,string $file): array
    {
        return FinalSettlement::fact($db,self::SIGNATURES,$file);
    }

    public static function record(PDO $db,array $contract,array $file,string $mode,?string $signedOn,string $signer,?string $returned): void
    {
        $data=['contract_id'=>(int)$contract['id'],'sha256'=>$file['sha256'],'mode'=>$mode,'signed_on'=>$signedOn,
            'signer'=>$signer,'returned'=>$returned,'physical_sha256'=>self::physicalBaseline($db,$contract),'when'=>date(DATE_ATOM),
            'actor'=>Auth::user()['name']];
        $old=self::receipt($db,$file['id']);
        if ($old) {
            unset($old['when'],$old['actor']);
            $same=$data;unset($same['when'],$same['actor']);
            if (Importer::canonicalJson($old)!==Importer::canonicalJson($same))
                throw new RuntimeException('Этот файл акта уже учтён с другими реквизитами.',409);
            return;
        }
        $db->prepare('INSERT INTO rental_facts(namespace,source_key,fact_json,operative) VALUES(?,?,?,1)')
            ->execute([self::SIGNATURES,$file['id'],Importer::json($data)]);
    }

    private static function physicalBaseline(PDO $db,array $contract): string
    {
        return hash('sha256',Importer::canonicalJson(['contract_id'=>(int)$contract['id'],'party_id'=>(int)$contract['party_id'],
            'objects'=>array_keys(array_filter(Occupancy::relations($db,(int)$contract['id']),Occupancy::physical(...)))]));
    }

    public static function physicalProof(PDO $db,array $contract,array $file): ?string
    {
        $original=self::receipt($db,$file['id']);
        if (($original['mode'] ?? '')!=='both' || $original['contract_id']!==(int)$contract['id']
            || !hash_equals($original['sha256'],$file['sha256']) || $original['returned']!==$contract['return_confirmed']
            || !hash_equals($original['physical_sha256'],self::physicalBaseline($db,$contract))) return null;
        self::assertIntegrity($file);
        return $original['physical_sha256'];
    }

    public static function files(PDO $db,int $contract,string $kind): array
    {
        $q=$db->prepare("SELECT * FROM rental_files WHERE entity_type='contract' AND entity_id=? AND kind=? ORDER BY upload_order DESC");
        $q->execute([$contract,$kind]);
        return array_column($q->fetchAll(),null,'id');
    }

    public static function recordOne(PDO $db,array $input): array
    {
        Service::roles(['manager']);
        $id=(int)($input['id'] ?? 0);
        Access::contract($id,2);
        $contract=Service::get($db,'contracts',$id);
        Service::version($contract,$input);
        if ($contract['status']!=='reconciling') throw new RuntimeException('Договор не на сверке.',409);
        if (OccupiedBilling::noPhysicalReturn($db,$contract)) throw new RuntimeException('Физический возврат не применяется.',409);
        $file=self::files($db,$id,self::ONE_FILE)[(string)($input['file_id'] ?? '')] ?? null;
        if (!$file || (int)$file['version']!==(int)$contract['version']
            || !hash_equals((string)$file['baseline_hash'],Evidence::signature($db,$id)))
            throw new RuntimeException('Загрузите односторонний акт для актуальной версии сверки.',409);
        $signer=DirectoryDetails::text($input,'signer',240);
        if ($signer==='') throw new RuntimeException('Укажите, кто подписал односторонний акт.',422);
        self::assertIntegrity($file);
        self::record($db,$contract,$file,'one',FinalSettlement::signedDate($input),$signer,null);
        $db->prepare('UPDATE contracts SET version=version+1 WHERE id=?')->execute([$id]);
        Audit::record(self::RECORD_ONE,'contract',$id,null,['file_id'=>$file['id'],'sha256'=>$file['sha256'],'signer'=>$signer]);
        return ['id'=>$id,'message'=>'Односторонний акт учтён. Двусторонний возврат и расчёты не подтверждены.'];
    }

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
        if (self::physicalProof($db,$contract,$file)!==null) return $file;
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
        if (OccupiedBilling::noPhysicalReturn($db,$contract)) {
            return ['file'=>null,'confirmed'=>false,'not_applicable'=>true,'message'=>'Физический возврат не применяется.'];
        }
        try {
            $confirmed = self::currentConfirmation($db, $contract, $baseline);
        } catch (RuntimeException $e) {
            if ($e instanceof \PDOException) throw $e;
            if ($contract['status'] === 'closed') return ['file'=>null, 'confirmed'=>false, 'message'=>$e->getMessage()];
        }
        if (isset($confirmed)) {
            $receipt=self::receipt($db,$confirmed['id']);
            return ['file'=>$confirmed,'confirmed'=>true,'message'=>'Акт возврата подтверждён',
                'signed_on'=>$receipt['signed_on'] ?? null,'actual_return'=>$contract['return_confirmed']];
        }
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
            ...(isset($state['actual_return']) ? [['Фактический возврат',Documents::dateText($state['actual_return'])],
                ['Подписание акта',Documents::dateText($state['signed_on'] ?? null)]] : []),
            ...($file ? [['Сохранённый файл', $file['filename']]] : []),
        ]);
        if ($file) {
            $html .= '<div><a class="btn btn-secondary" href="/rental/download?id='.View::e($file['id']).'">Открыть сохранённый акт</a></div>';
        }
        return $html;
    }

    public static function history(PDO $db,int $contract): string
    {
        $rows=[];
        foreach ([self::KIND=>'Двусторонний акт',self::ONE_FILE=>'Односторонний акт'] as $kind=>$label) {
            foreach (self::files($db,$contract,$kind) as $file) {
                $proof=self::receipt($db,$file['id']);
                $rows[]=[View::e($label),$proof?'Учтён':'Сохранённый файл',
                    View::e(Documents::dateText($proof['signed_on'] ?? null)),View::e(($proof['signer'] ?? '') ?: '—'),
                    '<a href="/rental/download?id='.View::e($file['id']).'">'.View::e($file['filename']).'</a>'];
            }
        }
        return $rows?'<h3 class="registry-ui-modal-summary">История актов возврата</h3>'
            .Controller::table(['Вид','Статус','Подписание','Подписант','Файл'],$rows):'';
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
        $body = self::summary($state).self::history(DB::pdo(),(int)$contract['id']);
        if ($state['confirmed'] || !empty($state['not_applicable'])) return RentalUI::modal('Акт возврата помещения', $body, '', 'reconciliation');
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
                .Controller::input('return_date', 'Дата фактического возврата', '', 'date', true)
                .Controller::input('signed_on','Дата подписания акта','','date',true)
                .PremisesActsCard::returnFields(DB::pdo(),$contract)
                .'<label class="registry-ui-checkbox"><input type="checkbox" name="both_signed" value="1" required>'
                .'<span>Акт подписан обеими сторонами</span></label></form>';
            $actions .= '<button class="btn btn-primary" type="submit" form="return-act-confirm">Подтвердить акт</button>';
        }
        return RentalUI::modal('Акт возврата помещения', $body, $actions, 'reconciliation');
    }
}
