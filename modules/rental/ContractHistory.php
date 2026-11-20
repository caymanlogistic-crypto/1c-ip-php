<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\View;
use PDO;
use RuntimeException;

final class ContractHistory
{
    public static function remember(PDO $db, array $contract, array $actor, string $commandKey, array $revision=[]): void
    {
        $objects = $db->prepare(
            'SELECT co.object_id,o.id,o.name,o.type,o.area,o.floor,o.cadastre_id,o.active,o.version,o.source_json,'
            .\App\ContractOccupancy::selectColumns('co').' '
            .'FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id '
            .'WHERE co.contract_id=? ORDER BY co.object_id'
        );
        $objects->execute([$contract['id']]);
        $terms = $db->prepare('SELECT * FROM contract_terms WHERE contract_id=? ORDER BY id');
        $terms->execute([$contract['id']]);

        $snapshotData = [
            'contract' => $contract,
            'objects' => $objects->fetchAll(PDO::FETCH_ASSOC),
            'terms' => $terms->fetchAll(PDO::FETCH_ASSOC),
        ];
        $partTerms=\App\BillingPartHistory::snapshot($db,'contract',(int)$contract['id'],(int)$contract['version']);
        if($partTerms!==null)$snapshotData['part_terms']=$partTerms;
        $legal=$db->prepare("SELECT role,baseline_hash,file_id,actor_id,confirmed_at FROM rental_approvals WHERE entity_type='contract' AND entity_id=?");
        $legal->execute([$contract['id']]);
        $accounting=$db->prepare("SELECT id,record_version,actor_id,legal_file_id,confirmed_at FROM rental_packet_confirmations "
            ."WHERE record_kind='contract' AND record_id=? AND record_version<=? ORDER BY workflow_revision DESC LIMIT 1");
        $accounting->execute([$contract['id'],$contract['version']]);
        $decisions=$legal->fetchAll();
        $confirmation=$accounting->fetch()?:null;
        if ($decisions || $confirmation || $revision)
            $snapshotData['review']=['legal'=>$decisions,'accounting'=>$confirmation,'revision'=>$revision];
        $snapshot = Importer::canonicalJson($snapshotData);
        $db->prepare(
            'INSERT INTO rental_contract_versions '
            .'(contract_id,version,snapshot_text,snapshot_sha256,actor_id,actor_role_id,actor_name,command_key) '
            .'VALUES(?,?,?,?,?,?,?,?)'
        )->execute([
            $contract['id'], $contract['version'], $snapshot, hash('sha256', $snapshot),
            $actor['id'], $actor['role_id'] ?? $actor['role'], $actor['name'], $commandKey,
        ]);
    }

    public static function listing(int $id): string
    {
        Access::contract($id);
        $rows = [];
        foreach (Controller::query(
            'SELECT version,created_at,actor_name FROM rental_contract_versions WHERE contract_id=? ORDER BY version DESC',
            [$id]
        ) as $version) {
            $rows[] = [
                Controller::link('/rental/history?contract_id='.$id.'&version='.$version['version'], 'Версия '.$version['version']),
                View::e($version['created_at']), View::e($version['actor_name']),
            ];
        }
        return $rows
            ? '<span class="registry-ui-modal-summary">Предыдущие условия</span>'.Controller::table(['Версия', 'Время', 'Участник'], $rows)
            : '';
    }

    public static function preview(int $id, int $version): string
    {
        $current = Access::contract($id);
        $row = Controller::query(
            'SELECT * FROM rental_contract_versions WHERE contract_id=? AND version=?', [$id, $version]
        )[0] ?? throw new RuntimeException('Версия договора не найдена.', 404);
        if (!hash_equals($row['snapshot_sha256'], hash('sha256', $row['snapshot_text']))) {
            throw new RuntimeException('Проверка целостности версии не пройдена.');
        }
        $snapshot = json_decode($row['snapshot_text'], true, 512, JSON_THROW_ON_ERROR);
        $contract = $snapshot['contract'];
        $names = array_column($snapshot['objects'], 'name', 'object_id');
        $rows = [];
        foreach ($snapshot['terms'] as $term) {
            $rows[] = [
                View::e($names[$term['object_id']] ?? 'Объект '.$term['object_id']),
                View::e(Documents::dateText($term['start_date'])),
                View::e(Documents::dateText($term['end_date'])),
                Controller::money($term['amount']),
            ];
        }
        if(isset($snapshot['part_terms']))foreach(\App\BillingPartHistory::terms($snapshot['part_terms']) as $term){
            $rows[]=[
                View::e('Юридический адрес: '.$term['name']),
                View::e(Documents::dateText($term['start_date'])),
                View::e(Documents::dateText($term['end_date'])),
                Controller::money($term['amount']),
            ];
        }
        $body = RentalUI::pairs([
            ['Договор', $contract['number']], ['Версия', (string)$version],
            ['Дата договора', Documents::dateText($contract['document_date'])],
            ['Месячная аренда с НДС', Controller::money($contract['amount'])],
        ]).Controller::table(['Помещение', 'Начало', 'Окончание', 'Аренда с НДС'], $rows);
        if (!empty($snapshot['review'])) {
            $review=$snapshot['review'];
            $body.='<span class="registry-ui-modal-summary">Решения по прежней редакции</span>';
            foreach ($review['legal'] as $decision) {
                $body.=RentalUI::pairs([['Юридическое решение','Согласовано '.$decision['confirmed_at']]])
                    .'<a class="btn btn-secondary" href="/rental/download?id='.View::e($decision['file_id']).'">Одобренный файл</a>';
            }
            if ($review['accounting']) $body.=RentalUI::pairs([
                ['Проверка бухгалтером','Подтверждено '.$review['accounting']['confirmed_at']],
                ['Версия пакета',(string)$review['accounting']['record_version']]
            ]);
        }
        return RentalUI::modal(
            'Условия договора '.$current['number'].' · версия '.$version,
            $body,
            RentalUI::button('История и сообщения', '/rental/history?contract_id='.$id),
            'contracts'
        );
    }
}
