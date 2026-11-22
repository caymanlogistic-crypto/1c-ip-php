<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\{Audit,ContractOccupancy,InvoiceScope};
use Modules\Integrations\{Config,Support};
use PDO;
use RuntimeException;

/** The owner's 18 November register resolves the recorded 2.12/2.40 assignment. */
final class ArchivedParkingAssignment
{
    public const REGISTER_SHA = '328caf9a55923613b1845cce03fd65b0fe1e9f7d795a6c1d2f6193443f192fb2';
    private const SOURCE = 'ownerParkingRegisterAssignment';
    private const INVOICES = ['АЯИП-001246','АЯИП-001273'];

    public static function correct(PDO $db,array $actor,array $input,array $rstroy): array
    {
        ArchivedRentCorrection::requireOperator();
        if (!$db->inTransaction() || InvoiceScope::enabled() || $input['parking_register_sha']!==self::REGISTER_SHA)
            throw new RuntimeException('Укажите подтверждённый реестр и проверьте режим учёта счетов.',409);
        $gid=Service::get($db,'contracts',1004);
        $old=Service::get($db,'contracts',970416);
        $gidId=(int)$gid['id'];
        $rstroyId=(int)$rstroy['id'];
        $oldId=(int)$old['id'];
        $source=Importer::decodeExact($rstroy['source_json']);
        if ($gid['number']!=='24' || $gid['status']!=='active' || $old['number']!=='56'
            || $old['status']!=='reconciling' || $old['return_confirmed']!==null
            || ($source['sourceLegalMapping']['party1CRef_Key'] ?? null)!=='a444cc56-df4f-11f1-ae1e-005056950eec'
            || self::places($db,$gidId)!==[1009=>'2.3',1015=>'2.12'] || self::places($db,$oldId)!==[1030=>'2.40']
            || self::places($db,$rstroyId)!==[1015=>'2.12',1016=>'2.13'] || !isset($source[ContractOccupancy::EXCEPTIONS][1015])) {
            throw new RuntimeException('Карточки парковки изменились. Сверьте договоры №24, №56 и №65.',409);
        }

        $term=OccupiedBilling::term($db,$gidId,1015,true);
        $basis=['as_of'=>'2026-11-18','register_rows'=>[51,52,69,70],'register_sha256'=>self::REGISTER_SHA,
            'gidfinance'=>['contract'=>$gidId,'from'=>1015,'to'=>1030],
            'rstroy'=>['contract'=>$rstroyId,'objects'=>[1015,1016]],'previous_contract'=>$oldId];
        foreach ([$gid,$old,$rstroy] as $contract) {
            ContractHistory::remember($db,$contract,$actor,$input['command_key'],$basis);
        }

        $oldSource=Importer::decodeExact($old['source_json']);
        $oldSource['ownerConfirmedPhysicalRelease'][1030]=['effective_on'=>$term['start_date']]+$basis;
        self::saveContract($db,$oldId,$oldSource);
        Service::available($db,[1030],$term['start_date'],$term['end_date'],$gidId,(int)$gid['party_id']);

        $db->prepare('UPDATE contract_objects SET object_id=1030 WHERE contract_id=? AND object_id=1015')->execute([$gidId]);
        $db->prepare('UPDATE contract_terms SET object_id=1030 WHERE contract_id=? AND object_id=1015')->execute([$gidId]);
        $gidSource=Importer::decodeExact($gid['source_json']);
        $gidSource[self::SOURCE]=$basis;
        self::saveContract($db,$gidId,$gidSource);

        Service::available($db,[1015],$rstroy['start_date'],$rstroy['end_date'],$rstroyId,(int)$rstroy['party_id']);
        unset($source[ContractOccupancy::EXCEPTIONS][1015]);
        if (!$source[ContractOccupancy::EXCEPTIONS]) unset($source[ContractOccupancy::EXCEPTIONS]);
        $source[self::SOURCE]=$basis;
        self::saveContract($db,$rstroyId,$source);

        $invoices=self::correctInvoiceCoverage($db,$actor,$gidId,$basis);
        $result=$basis+['invoices'=>$invoices];
        Audit::record(ArchivedRentCorrection::ACTION,'contract',$gidId,['object_id'=>1015],$result);
        Service::event($db,$actor,ArchivedRentCorrection::ACTION,'Подтверждено закрепление места 2.40 по №24.',$gidId,$result);
        Service::event($db,$actor,ArchivedRentCorrection::ACTION,'Подтверждено закрепление мест 2.12 и 2.13 по №65.',$rstroyId,$result);
        Service::event($db,$actor,ArchivedRentCorrection::ACTION,'Уточнена физическая занятость места 2.40 по реестру на 18 ноября.',$oldId,$basis);
        return $result;
    }

    private static function places(PDO $db,int $contract): array
    {
        return array_column(Occupancy::objects($db,$contract),'name','id');
    }

    private static function saveContract(PDO $db,int $id,array $source): void
    {
        $db->prepare('UPDATE contracts SET source_json=?,version=version+1 WHERE id=?')
            ->execute([Importer::json($source),$id]);
    }

    private static function correctInvoiceCoverage(PDO $db,array $actor,int $contract,array $basis): array
    {
        $q=$db->prepare('SELECT i.* FROM invoices i WHERE i.contract_id=? AND (EXISTS (
            SELECT 1 FROM invoice_objects x WHERE x.invoice_id=i.id AND x.object_id=1015) OR EXISTS (
            SELECT 1 FROM invoice_coverage x WHERE x.invoice_id=i.id AND x.object_id=1015)) ORDER BY i.number FOR UPDATE');
        $q->execute([$contract]);
        $invoices=$q->fetchAll();
        if (array_column($invoices,'number')!==self::INVOICES) throw new RuntimeException('Изменился состав счетов для исправления.',409);
        $coverage=$db->prepare('SELECT object_id,start_date,end_date FROM invoice_coverage WHERE invoice_id=? ORDER BY object_id,start_date FOR UPDATE');
        $objects=$db->prepare('SELECT object_id FROM invoice_objects WHERE invoice_id=? ORDER BY object_id FOR UPDATE');
        $store=Config::store();
        $lease=Service::get($db,'contracts',$contract);
        $result=[];
        foreach ($invoices as $invoice) {
            $id=(int)$invoice['id'];
            $snapshot=Importer::decodeExact($invoice['source_json']);
            $archive=InvoiceContractAssignment::archive($db,$snapshot['yakovlev_archive']['document_id'] ?? '',true);
            $original=Support::decode($store->read($archive['raw_path'],$archive['raw_sha256']))['fields']['record'];
            if ($archive['status']!=='Проведён' || !MonthlyInvoiceArchive::matchesInvoice($archive,$invoice)
                || $archive['source_id']!=='invoices:'.($snapshot['Ref_Key'] ?? '')
                || !in_array('2.40',YakovlevInvoiceCoverage::parkingReferences($original),true)) {
                throw new RuntimeException('Оригинал счёта не подтверждает место 2.40.',409);
            }
            $proof=YakovlevInvoiceCoverage::prove($db,$lease,$original,$invoice['amount'],$invoice['issued'],true);
            $coverage->execute([$id]);
            $before=$coverage->fetchAll();
            $objects->execute([$id]);
            $beforeObjects=$objects->fetchAll(PDO::FETCH_COLUMN);
            $expected=array_map(static fn(array $row): array=>[$row['object_id']===1015 ? 1030 : $row['object_id'],$row['start_date'],$row['end_date']],$before);
            $proven=array_map(static fn(array $row): array=>[$row['object'],$row['from'],$row['until']],$proof['segments']);
            sort($expected);
            sort($proven);
            if ($expected!==$proven || !in_array(1015,$beforeObjects,true) || in_array(1030,$beforeObjects,true)) {
                throw new RuntimeException('Покрытие счёта отличается от подтверждённых периодов.',409);
            }
            FinanceService::remember($db,$invoice,(int)$actor['id']);
            $db->prepare('UPDATE invoice_objects SET object_id=1030 WHERE invoice_id=? AND object_id=1015')->execute([$id]);
            $db->prepare('UPDATE invoice_coverage SET object_id=1030 WHERE invoice_id=? AND object_id=1015')->execute([$id]);
            $snapshot[self::SOURCE]=$basis+['original_sha256'=>$archive['raw_sha256'],'previous_objects'=>$beforeObjects,'previous_coverage'=>$before];
            $db->prepare('UPDATE invoices SET source_json=?,version=version+1 WHERE id=?')->execute([Importer::json($snapshot),$id]);
            $result[]=$invoice['number'];
        }
        return $result;
    }
}
