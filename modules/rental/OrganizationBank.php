<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\Audit;
use PDO;
use RuntimeException;

final class OrganizationBank
{
    public static function save(PDO $db,array $actor,array $input): array
    {
        Service::roles(['accountant']);
        Access::pages(['organization'],2);
        $organization=Service::get($db,'organization',1);
        Service::version($organization,$input);
        $key=trim((string)($input['bank_id'] ?? ''));
        $query=$db->prepare('SELECT * FROM organization_bank_accounts WHERE organization_id=1 ORDER BY id FOR UPDATE');
        $query->execute();
        $banks=$query->fetchAll(PDO::FETCH_UNIQUE|PDO::FETCH_ASSOC);
        $old=$key!==''?($banks[$key] ?? throw new RuntimeException('Банковский счёт не найден.',404)):null;
        $intent=(string)($input['intent'] ?? 'save');
        if (!in_array($intent,['save','primary','delete'],true)) throw new RuntimeException('Действие не найдено.',422);
        if ($intent==='delete') {
            if (!$old) throw new RuntimeException('Банковский счёт не найден.',404);
            if (count($banks)<=1) throw new RuntimeException('Нельзя удалить последний банковский счёт.',422);
            $db->prepare('DELETE FROM organization_bank_accounts WHERE organization_id=1 AND id=?')->execute([$key]);
            unset($banks[$key]);
            if ($old['is_primary']) {
                $first=(string)array_key_first($banks);
                $db->prepare('UPDATE organization_bank_accounts SET is_primary=1 WHERE organization_id=1 AND id=?')->execute([$first]);
            }
        } else {
            $fields=DirectoryRules::bank($input);
            if ($old && $fields['bank']===DirectoryUi::bankName($old)) $fields['bank']=$old['bank'];
            $primary=$intent==='primary' || !$banks || !empty($old['is_primary']);
            if ($primary) $db->exec('UPDATE organization_bank_accounts SET is_primary=0 WHERE organization_id=1');
            if ($old) {
                $db->prepare('UPDATE organization_bank_accounts SET bank=?,account=?,bik=?,correspondent_account=?,is_primary=? WHERE organization_id=1 AND id=?')
                    ->execute([...array_values($fields),(int)$primary,$key]);
            } else {
                $key=bin2hex(random_bytes(12));
                $db->prepare("INSERT INTO organization_bank_accounts(id,organization_id,bank,account,bik,correspondent_account,is_primary,source_json) VALUES(?,1,?,?,?,?,?,'{}')")
                    ->execute([$key,...array_values($fields),(int)$primary]);
            }
        }
        if ((int)$db->query('SELECT COUNT(*) FROM organization_bank_accounts WHERE organization_id=1 AND is_primary=1')->fetchColumn()!==1) {
            throw new RuntimeException('Выберите один основной банковский счёт.',422);
        }
        $db->exec('UPDATE organization SET version=version+1 WHERE id=1');
        Audit::record('organization.bank.save','organization',1,$old,['intent'=>$intent,'bank_id'=>$key]);
        Service::event($db,$actor,'organization.bank.save','Изменены банковские реквизиты организации');
        return ['id'=>1,'message'=>'Изменение сохранено.'];
    }
}
