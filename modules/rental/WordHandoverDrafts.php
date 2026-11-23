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
        $acts=[];
        foreach (array_column($terms,null,'object_id') as $id=>$object) {
            $kind=in_array($object['type'],TemplateAssets::PARKING_TYPES,true)?'parking-handover':'handover';
            $acts[$id]=['object'=>$object,'source'=>OriginalDocuments::resolve($db,$kind,0)];
        }
        return $acts;
    }

    public static function commonFields(array $acts): array
    {
        $fields=[];
        foreach ($acts as $act) $fields=array_merge($fields,array_diff($act['source']['fields'],self::PER_OBJECT));
        return array_values(array_unique($fields));
    }

    public static function build(PDO $db,array $contract,array $act,array $values,string $path): string
    {
        $object=$act['object'];
        $id=(int)$object['object_id'];
        $query=$db->prepare('SELECT c.number FROM rental_objects o LEFT JOIN cadastre c ON c.id=o.cadastre_id WHERE o.id=?');
        $query->execute([$id]);
        $cadastre=$query->fetchColumn();
        $count=PremisesActs::count($db,(int)$contract['id'],$id);
        $floor=(string)($object['floor']??'');
        $ordinal=new \NumberFormatter('ru_RU',\NumberFormatter::SPELLOUT);
        $ordinal->setTextAttribute(\NumberFormatter::DEFAULT_RULESET,'%spellout-ordinal-masculine-prepositional');
        $facts=[
            'room_numbers'=>$object['name'],'area'=>$object['area']===null?'_____':DirectoryUi::area((string)$object['area']),
            'floor'=>$floor===''?'_____':$floor,
            'floor_words'=>preg_match('/^[1-9]\d{0,2}$/D',$floor)?(string)$ordinal->format((int)$floor):($floor===''?'_____':$floor),
            'cadastre_numbers'=>$cadastre?:'_____',
            'passes_count'=>(string)($count['count']??'_____'),
        ];
        $source=$act['source'];
        OriginalDocuments::buildSource($source,array_replace($values,$facts),$path);
        Documents::validateFile($path,'docx');
        return mb_substr(OriginalDocuments::TITLES[$source['kind']].' '.$object['name'].' · договор №'.$contract['number'],0,220).'.docx';
    }
}
