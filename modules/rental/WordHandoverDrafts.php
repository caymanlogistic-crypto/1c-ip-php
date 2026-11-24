<?php
declare(strict_types=1);

namespace Modules\Rental;

use PDO;
use RuntimeException;

final class WordHandoverDrafts
{
    private const PER_OBJECT=['area','room_numbers','floor','floor_words','cadastre_numbers','passes_count'];

    public static function plan(PDO $db,array $terms): array
    {
        $acts=[];$sources=[];
        foreach (array_column($terms,null,'object_id') as $id=>$object) {
            $kind=self::kind($object);
            $sources[$kind]??=OriginalDocuments::resolve($db,$kind,0);
            $acts[$id]=['object'=>$object,'source'=>$sources[$kind]];
        }
        return $acts;
    }

    public static function kind(array $object): string
    {
        return in_array($object['type'],TemplateAssets::PARKING_TYPES,true)?'parking-handover':'handover';
    }

    public static function commonFields(array $acts): array
    {
        $fields=[];
        foreach ($acts as $act) $fields=array_merge($fields,array_diff($act['source']['fields'],self::PER_OBJECT));
        return array_values(array_unique($fields));
    }

    public static function build(PDO $db,array $contract,array $act,array $values,string $path,bool $mixed=false): string
    {
        $object=$act['object'];
        $id=(int)$object['object_id'];
        $query=$db->prepare('SELECT c.number FROM rental_objects o LEFT JOIN cadastre c ON c.id=o.cadastre_id WHERE o.id=?');
        $query->execute([$id]);
        $cadastre=$query->fetchColumn();
        $count=PremisesActs::count($db,(int)$contract['id'],$id);
        $floor=(string)($object['floor']??'');
        $facts=[
            'room_numbers'=>$object['name'],'area'=>$object['area']===null?'_____':DirectoryUi::area((string)$object['area']),
            'floor'=>$floor===''?'_____':$floor,
            'floor_words'=>WordGenerationData::floorWords($floor) ?: ($floor===''?'_____':$floor),
            'cadastre_numbers'=>$cadastre?:'_____',
            'passes_count'=>(string)($count['count']??'_____'),
        ];
        $source=$act['source'];
        $phrases=$mixed?match($source['kind']) {
            'parking-handover'=>['В соответствии с пунктом 3.1.1. Договора аренды машино-места'=>'В соответствии с Договором аренды'],
            'handover'=>[
                'к Договору аренды нежилых помещений от'=>'к Договору аренды от',
                'В соответствии с пунктом 3.1.2. Договора аренды'=>'В соответствии с Договором аренды',
                'в целях, установленных п. 2.2. Договора.'=>'в целях, установленных Договором аренды.',
            ],
        }:[];
        OriginalDocuments::buildSource($source,array_replace($values,$facts),$path,phrases:$phrases);
        Documents::validateFile($path,'docx');
        return mb_substr(OriginalDocuments::TITLES[$source['kind']].' '.$object['name'].' · договор №'.$contract['number'],0,220).'.docx';
    }
}
