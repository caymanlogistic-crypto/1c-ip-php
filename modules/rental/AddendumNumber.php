<?php
declare(strict_types=1);

namespace Modules\Rental;

use PDO;

final class AddendumNumber
{
    public static function forNew(PDO $db,array $contract): string
    {
        $id=(int)$contract['id'];
        $managed=RegistryState::managed($contract);
        $saved=$db->prepare('SELECT d.number,d.status FROM rental_documents d WHERE d.contract_id=? AND '.WorkspaceReset::visibleSql('d'));
        $saved->execute([$id]);
        $documents=$saved->fetchAll();
        $numbers=array_column($documents,'number');

        if (!$managed) {
            $dates=$db->prepare('SELECT DISTINCT GREATEST(t.start_date,COALESCE(t.effective_date,t.start_date)) AS applies_from
                FROM contract_terms t WHERE t.contract_id=? AND t.start_date IS NOT NULL');
            $dates->execute([$id]);
            $archive=[];
            foreach ($dates->fetchAll(PDO::FETCH_COLUMN) as $day) {
                $archive=[...$archive,...(LeaseCalculationAddenda::byContract($db,[$contract],$day)[$id] ?? [])];
            }
            if (!$archive && !in_array('signed',array_column($documents,'status'),true)) return '';
            $numbers=[...$numbers,...$archive];
        }

        $max='0';
        foreach ($numbers as $number) {
            if (!is_string($number) || trim($number)==='') continue;
            $token=self::token($number);
            if (!preg_match('/^\d{1,96}$/D',$token)) return '';
            if (bccomp($token,$max,0)>0) $max=$token;
        }
        return bcadd($max,'1',0);
    }

    public static function token(string $number): string
    {
        return preg_replace('/^(?:ДС(?=\s|№|\d)\s*)?№?\s*/iu','',trim($number)) ?? '';
    }
}
