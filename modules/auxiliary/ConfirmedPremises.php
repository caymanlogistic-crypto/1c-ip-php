<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\{Audit,Auth,DB,PagePolicy};
use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** S confirmed_editor.py and owner_inventory.py; no inferred grouping or rental writes. */
final class ConfirmedPremises
{
    public const EDITABLE=['designation','floor','area','cadastral_number','aliases','decision','questions'];
    public const FIELDS=['stable_id','kind','floor','designation','area','area_variants','cadastral_number','aliases','source_ids','source_rows','decision','questions','has_questions'];
    public const LABELS=['ID позиции','Вид','Этаж','Обозначение','Площадь по карточке, м²','Варианты площадей, м²','Кадастровый номер из источника','Другие обозначения','Исходные карточки','Строки исходного реестра','Принятое решение','Что уточнить','Есть вопросы'];
    public const ROLES=['RENTAL_PART'=>'Физическая учётная позиция; юридические границы отдельно','PARKING'=>'Машино-место','SERVICE'=>'Услуга юридического адреса','NOMINAL_SERVICE'=>'Номинальная позиция юридического адреса на этажном кадастре'];
    public const OWNERSHIP=['CURRENT'=>'В нынешнем перечне','SOLD'=>'Продано; только история','UNRECONCILED'=>'Не сопоставлено с нынешними непарковочными объектами','SERVICE'=>'Отдельная услуга','HISTORICAL_PART'=>'Историческая арендная часть; не отдельный нынешний объект'];

    public static function editable(): bool
    {
        $user=Auth::user();
        return $user && Auth::can('premises.write') && (empty($user['grants_configured']) || PagePolicy::level('auxiliary.premises',$user)>=2);
    }

    public static function fingerprint(array $row): string
    {
        $snapshot=[];
        foreach (array_merge(self::EDITABLE,['stable_id','kind','has_questions','revision']) as $key) $snapshot[$key]=$row[$key];
        return hash('sha256',Importer::canonicalJson($snapshot));
    }

    private static function requireRead(): void
    {
        Auth::requirePermission('rental.read');
        $user=Auth::user();
        if (!empty($user['grants_configured']) && PagePolicy::level('auxiliary.premises',$user)<1) throw new RuntimeException('Недостаточно прав для просмотра помещений.',403);
    }

    public static function record(string $stableId): array
    {
        self::requireRead();
        $q=DB::pdo()->prepare('SELECT * FROM aux_confirmed_premises WHERE stable_id=?');
        $q->execute([$stableId]);
        return $q->fetch()?:throw new RuntimeException('Позиция не найдена.',404);
    }

    public static function save(string $stableId,array $input): void
    {
        self::requireRead();
        Auth::requirePermission('premises.write');
        if (!self::editable()) throw new RuntimeException('Недостаточно прав для изменения помещения.',403);
        $evidence=trim((string)($input['evidence']??''));
        if (str_contains($evidence,"\0") || !mb_check_encoding($evidence,'UTF-8')) throw new RuntimeException('Недопустимый символ в основании изменения.',422);
        if (mb_strlen($evidence)<10 || mb_strlen($evidence)>2000) throw new RuntimeException('Основание изменения: от 10 до 2000 символов.',422);
        DB::transaction(function(PDO $pdo) use($stableId,$input,$evidence): void {
            $q=$pdo->prepare('SELECT * FROM aux_confirmed_premises WHERE stable_id=? FOR UPDATE');
            $q->execute([$stableId]);$old=$q->fetch()?:throw new RuntimeException('Позиция не найдена.',404);
            if (!hash_equals(self::fingerprint($old),(string)($input['expected']??''))) throw new RuntimeException('Карточка уже изменена в другом окне. Закройте и откройте её снова.',409);
            $row=[];
            foreach (self::EDITABLE as $field) {
                $row[$field]=trim((string)($input[$field]??''));
                if (str_contains($row[$field],"\0") || !mb_check_encoding($row[$field],'UTF-8')) throw new RuntimeException('Недопустимый символ в поле карточки.',422);
            }
            if ($row['designation']==='' || mb_strlen($row['designation'])>200 || mb_strlen($row['floor'])>50) throw new RuntimeException('Проверьте обозначение помещения и этаж.',422);
            if ($row['area']==='') $row['area']=null;
            else {
                $area=str_replace(',','.',$row['area']);
                if (!preg_match('/^\d{1,11}(?:\.\d{1,3})?$/D',$area) || bccomp($area,'0',3)<=0) throw new RuntimeException('Укажите положительную площадь либо оставьте поле пустым.',422);
                $row['area']=bcadd($area,'0',3);
            }
            if ($row['cadastral_number']!==$old['cadastral_number'] && $row['cadastral_number']!=='' && !preg_match('/^\d{2}:\d{2}:\d{7}:\d+$/D',$row['cadastral_number'])) throw new RuntimeException('Укажите полный кадастровый номер, например 77:07:0013001:5842.',422);
            if ($row['designation']!==$old['designation']) {
                $row['aliases']=implode('; ',array_unique(array_filter([$row['aliases'],$old['designation']],fn($v)=>$v!=='')));
            }
            $row['has_questions']=(int)($row['questions']!=='');
            $before=array_intersect_key($old,$row);
            $changed=false;
            foreach ($row as $key=>$value) if (($value===null)!==($old[$key]===null) || (string)$value!==(string)$old[$key]) $changed=true;
            if (!$changed) return;
            $sets=implode(',',array_map(fn($field)=>$field.'=?',array_keys($row)));
            $pdo->prepare('UPDATE aux_confirmed_premises SET '.$sets.',revision=revision+1 WHERE id=?')->execute(array_merge(array_values($row),[$old['id']]));
            Audit::record('premise.confirmed.update','confirmed-premise',$stableId,$before,[
                'fields'=>$row,'evidence'=>$evidence,'old_fingerprint'=>self::fingerprint($old),
                'source_sha256'=>$old['source_sha256'],'revision'=>(int)$old['revision']+1,
            ]);
        });
    }

    public static function catalogue(array $options=[]): array
    {
        self::requireRead();
        $rows=DB::pdo()->query('SELECT * FROM aux_confirmed_premises ORDER BY stable_id')->fetchAll();
        $byId=array_column($rows,null,'id');
        $profiles=DB::pdo()->query('SELECT * FROM aux_owner_units')->fetchAll();
        $units=array_column($profiles,null,'premise_id');
        $linked=PremiseIssues::state($rows,$profiles);
        $historical=($options['representation']??'')==='history';
        $tab=in_array($options['tab']??'', ['rental','parking','technical'],true)?$options['tab']:'all';
        $query=mb_strtolower(mb_substr(trim((string)($options['q']??'')),0,200));
        $position=(string)($options['position']??'');
        foreach ($byId as &$row) {
            $row['has_questions']=(int)!empty($linked['entity'][$row['stable_id']]);
            $unit=$units[$row['id']]??null;
            $row['display_kind']=!$historical && $unit && in_array($unit['role'],['SERVICE','NOMINAL_SERVICE'],true)?'Юридический адрес':$row['kind'];
            $aliases=[$row['aliases']];
            foreach (json_decode($unit['aliases']??'[]',true,512,JSON_THROW_ON_ERROR) as $alias) if (!empty($alias['value'])) $aliases[]=$alias['value'];
            $row['display_aliases']=implode('; ',array_unique(array_filter($aliases,fn($v)=>$v!=='')));
        }
        unset($row);
        $selected=[];
        foreach ($byId as $row) {
            if ($position!=='' && $row['stable_id']!==$position) continue;
            $unit=$units[$row['id']]??null;
            $composition=self::composition($row,$unit);
            if ($position==='' && $tab==='technical' && $row['kind']!=='Техническое помещение') continue;
            if ($position==='' && $tab!=='technical' && $profiles && !$historical) {
                $allowed=match($tab){'parking'=>['parking-current'],'rental'=>['physical-nonparking'],default=>['physical-nonparking','parking-current','technical-current']};
                if (!in_array($composition,$allowed,true)) continue;
            }
            if ($position==='' && ($options['kind']??'')!=='' && $row['display_kind']!==$options['kind']) continue;
            if ($position==='' && ($options['composition']??'')!=='' && $composition!==$options['composition']) continue;
            if ($position==='' && ($options['ownership']??'')!=='' && ($unit['ownership']??'')!==$options['ownership']) continue;
            if ($position==='' && ($options['questions']??'')==='yes' && !$row['has_questions']) continue;
            $search=[];
            foreach (self::FIELDS as $field) $search[]=(string)($row[$field]??'');
            $search[]=$row['display_aliases'];
            if ($position==='' && $query!=='' && !str_contains(mb_strtolower(implode(' ',$search)),$query)) continue;
            if (!$historical && $unit && $unit['canonical_premise_id']!==null) {
                $root=$byId[$unit['canonical_premise_id']]??throw new RuntimeException('Не найдена подтверждённая каноническая позиция.');
                if ($root['id']!==$row['id']) {
                    $row=$root;
                }
            }
            $row['owner']=$units[$row['id']]??null;
            $row['composition']=self::composition($row,$row['owner']);
            $selected[$row['stable_id']]=$row;
        }
        foreach ($selected as &$row) {
            $members=[];
            foreach ($profiles as $unit) if ((int)($unit['canonical_premise_id']??$unit['premise_id'])===(int)$row['id']) $members[]=$byId[$unit['premise_id']];
            if (!$historical && count($members)>1) {
                $sourceIds=$sourceRows=$sourceCards=$variants=[];
                foreach ($members as $member) {
                    array_push($sourceIds,...json_decode($member['source_ids'],true,512,JSON_THROW_ON_ERROR));
                    preg_match_all('/\d+/',$member['source_rows'],$matches);array_push($sourceRows,...array_map('intval',$matches[0]));
                    array_push($sourceCards,...json_decode($member['source_cards'],true,512,JSON_THROW_ON_ERROR));
                    $variants[]=$member['stable_id'].': '.$member['area_variants'];
                }
                $sourceIds=array_values(array_unique($sourceIds));sort($sourceIds,SORT_STRING);
                $sourceRows=array_unique($sourceRows);sort($sourceRows,SORT_NUMERIC);
                $row['source_ids']=json_encode($sourceIds,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $row['source_rows']=implode(', ',$sourceRows);
                $row['source_cards']=json_encode($sourceCards,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
                $row['area_variants']=implode('; ',$variants);
            }
        }
        unset($row);
        $selected=array_values($selected);
        usort($selected,function(array $a,array $b): int {
            $an=preg_match('/^-?\d+(?:[.,]\d+)?$/D',trim($a['floor']));$bn=preg_match('/^-?\d+(?:[.,]\d+)?$/D',trim($b['floor']));
            if ($an!==$bn) return $an?-1:1;
            $floor=$an?bccomp(str_replace(',','.',$a['floor']),str_replace(',','.',$b['floor']),10):strcmp(mb_strtolower($a['floor']),mb_strtolower($b['floor']));
            if ($floor!==0) return $floor;
            $designation=strcmp(str_replace('ё','е',mb_strtolower($a['designation'])),str_replace('ё','е',mb_strtolower($b['designation'])));
            return $designation?:strcmp($a['stable_id'],$b['stable_id']);
        });
        return $selected;
    }

    private static function composition(array $row,?array $unit): string
    {
        if ($row['kind']==='Техническое помещение') return 'technical-current';
        if (!$unit) return '';
        if ($unit['ownership']==='SOLD') return 'sold';
        if ($unit['ownership']==='HISTORICAL_PART') return 'historical-part';
        return in_array($unit['role'],['SERVICE','NOMINAL_SERVICE'],true)?'legal-address':($unit['role']==='PARKING'?'parking-current':'physical-nonparking');
    }
}
