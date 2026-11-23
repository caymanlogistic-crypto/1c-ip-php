<?php
declare(strict_types=1);

namespace Modules\Integrations;

use Modules\Rental\{DirectoryUi,Documents,PartyLegalReview};
use PDO;

final class ContractSubmitMax
{
    public const VARIABLES=['contractSubmitTitle','contractStart','contractEnd','contractObjectLines','contractSubmitAction'];

    public static function values(PDO $db,array $contract,bool $submission=false): array
    {
        $objects=$db->prepare('SELECT o.name,o.type,o.area FROM contract_objects co JOIN rental_objects o ON o.id=co.object_id WHERE co.contract_id=? ORDER BY o.id');
        $objects->execute([$contract['id']]);
        $rows=[];
        foreach ($objects->fetchAll(PDO::FETCH_ASSOC) as $object) {
            $rows[]=[
                'name'=>self::line($object['name']),
                'type'=>self::line($object['type']),
                'area'=>$object['area']===null?'':DirectoryUi::area($object['area']),
            ];
        }
        $layout=[
            'contractStart'=>Documents::dateText($contract['start_date']),
            'contractEnd'=>Documents::dateText($contract['end_date']),
            'contractObjectLines'=>Support::json($rows),
        ];
        if (!$submission) return $layout;
        $review=PartyLegalReview::state($db,(int)$contract['party_id']);
        $newParty=in_array($review['status'] ?? '',['draft','pending','returned'],true);
        if ($newParty) {
            $previous=$db->prepare("SELECT 1 FROM contracts WHERE party_id=? AND id<>? AND status<>'draft' LIMIT 1");
            $previous->execute([$contract['party_id'],$contract['id']]);
            $newParty=!$previous->fetchColumn();
        }
        return $layout+[
            'contractSubmitTitle'=>$newParty?'ЗАРЕГИСТРИРОВАН НОВЫЙ КОНТРАГЕНТ И ДОГОВОР':'ЗАРЕГИСТРИРОВАН НОВЫЙ ДОГОВОР',
            'contractSubmitAction'=>$newParty
                ?'Договор направлен в юридический отдел.'."\n".'Действие · Согласовать работу с контрагентом и условия договора'
                :'Договор направлен в юридический отдел.'."\n".'Действие · Согласовать условия договора',
        ];
    }

    private static function line(string $value): string
    {
        return trim(preg_replace('/\s+/u',' ',$value));
    }

    public static function objectLines(string $json,string $format,callable $escape): string
    {
        $objects=$json===''?[]:Support::decode($json);
        [$before,$after]=match($format) {
            'html'=>['<b>','</b>'],
            'markdown'=>['**','**'],
            default=>['',''],
        };
        $lines=[];
        foreach ($objects as $object) {
            $lines[]='Объект: '.$before.$escape($object['name']).$after.' ('.$escape($object['type']).')'
                .($object['area']!==''?' — '.$escape($object['area']).' м²':'');
        }
        return implode("\n",$lines);
    }
}
