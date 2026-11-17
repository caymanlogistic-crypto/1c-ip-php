<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\{DB,StorageBudget};
use Modules\Rental\Importer;
use RuntimeException;
use ZipArchive;

/** Native XLSX writer. Text is always inlineStr, including leading '='. */
final class PremisesExport
{
    private static function xml(mixed $value): string
    {
        $text=(string)($value??'');
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/',$text)) throw new RuntimeException('XLSX_INVALID_TEXT_CHARACTER');
        return htmlspecialchars($text,ENT_XML1|ENT_QUOTES,'UTF-8');
    }

    private static function column(int $number): string
    {
        $name='';
        do {$number--;$name=chr(65+$number%26).$name;$number=intdiv($number,26);} while ($number>0);
        return $name;
    }

    private static function values(array $row): array
    {
        $values=[];
        foreach (ConfirmedPremises::FIELDS as $field) {
            $value=$row[$field];
            if ($field==='area') $value=$value===null?'Не установлена':['number'=>$value];
            elseif ($field==='kind') $value=$row['display_kind'];
            elseif ($field==='aliases') $value=$row['display_aliases'];
            elseif ($field==='source_ids') $value=implode(', ',json_decode($value,true,512,JSON_THROW_ON_ERROR));
            elseif ($field==='has_questions') $value=$value?'Да':'Нет';
            $values[]=$value;
        }
        return $values;
    }

    public static function xlsx(array $rows): string
    {
        $list=[ConfirmedPremises::LABELS];
        $cards=[['Исходный ID','Исходный вид','Этаж','Исходное обозначение','Площадь карточки, м²','Варианты площадей, м²','Исходный кадастровый номер','Строки исходного реестра','ID в новом списке','Результат обработки']];
        foreach ($rows as $row) {
            $list[]=self::values($row);
            foreach (Importer::decodeExact($row['source_cards']) as $card) {
                // Only the area column is numeric. Designations/IDs retain literal text.
                if (isset($card[4]) && preg_match('/^\d+(?:\.\d+)?$/D',(string)$card[4])) $card[4]=['number'=>(string)$card[4]];
                $cards[]=$card;
            }
        }
        $sheets=['Список'=>$list,'Исходные карточки'=>$cards];
        $levels=['FLOOR_PART'=>'Этажная кадастровая часть','INDIVIDUAL_PARKING'=>'Кадастр машиноместа из источника','SOURCE_UNCLASSIFIED'=>'Кадастр источника; уровень объекта не установлен'];
        $mapping=[['ID позиции','Обозначение','Уровень учёта','Нынешнее владение','Кадастр источника / исправленный','Уровень кадастра','Подтверждённая группа / канонический ID','Другая нумерация и происхождение','Исходные карточки','Исходные строки','Основание','Остаточный вопрос']];
        $sql='SELECT u.*,p.stable_id,p.designation,p.cadastral_number,p.source_ids,p.source_rows,cp.stable_id canonical_id,c.level,e.evidence_key FROM aux_owner_units u JOIN aux_confirmed_premises p ON p.id=u.premise_id LEFT JOIN aux_confirmed_premises cp ON cp.id=u.canonical_premise_id LEFT JOIN aux_owner_cadastral_objects c ON c.id=u.cadastral_object_id JOIN aux_owner_evidence e ON e.id=u.evidence_id ORDER BY p.stable_id';
        foreach (DB::pdo()->query($sql)->fetchAll() as $record) {
            $mapping[]=[
                $record['stable_id'],$record['designation'],ConfirmedPremises::ROLES[$record['role']]??$record['role'],
                ConfirmedPremises::OWNERSHIP[$record['ownership']]??$record['ownership'],$record['cadastral_number'],
                $record['level']?($levels[$record['level']]??$record['level']):'Не применяется к услуге',
                $record['legal_unit_key']?:($record['canonical_id']??'Не установлено'),$record['aliases'],
                implode(', ',json_decode($record['source_ids'],true,512,JSON_THROW_ON_ERROR)),$record['source_rows'],$record['evidence_key'],$record['notes'],
            ];
        }
        $sheets['Уровни и соответствие']=$mapping;
        $changes=[['ID позиции','Поле','До','После','Основание','Дата получения']];
        $sql='SELECT p.stable_id,c.field,c.before_value,c.after_value,e.evidence_key,e.received_on FROM aux_owner_changes c JOIN aux_confirmed_premises p ON p.id=c.premise_id JOIN aux_owner_evidence e ON e.id=c.evidence_id ORDER BY e.sequence_number,c.id';
        foreach (DB::pdo()->query($sql)->fetchAll() as $record) {
            foreach (['before_value','after_value'] as $field) {
                $raw=$record[$field];
                if (str_starts_with($raw,'"')) $record[$field]=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
            }
            $changes[]=array_values($record);
        }
        $sheets['Изменения OWNER']=$changes;
        $evidence=[['Основание','Дата получения','SHA256','Факт / ограничение']];
        foreach (DB::pdo()->query('SELECT * FROM aux_owner_evidence ORDER BY sequence_number,id')->fetchAll() as $record) {
            $payload=Importer::decodeExact($record['payload']);
            $evidence[]=[$record['evidence_key'],$record['received_on'],$record['sha256'],json_encode($payload['current_declaration']??[],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)];
            foreach (['facts','unresolved','source_findings'] as $section) foreach ($payload[$section]??[] as $fact) {
                $evidence[]=[$record['evidence_key'],$record['received_on'],$record['sha256'],is_array($fact)?json_encode($fact,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR):$fact];
            }
        }
        $sheets['Основания OWNER']=$evidence;
        $cadastres=[['Кадастровый номер','Уровень кадастра','Этаж','Исходные позиции','Основание','Дата получения']];
        $sql='SELECT c.number,c.level,c.floor,c.source_positions,e.evidence_key,e.received_on FROM aux_owner_cadastral_objects c JOIN aux_owner_evidence e ON e.id=c.evidence_id ORDER BY c.number';
        foreach (DB::pdo()->query($sql)->fetchAll() as $record) $cadastres[]=[
            $record['number'],$levels[$record['level']]??$record['level'],$record['floor'],
            implode(', ',json_decode($record['source_positions'],true,512,JSON_THROW_ON_ERROR)),$record['evidence_key'],$record['received_on'],
        ];
        $sheets['Кадастры источника']=$cadastres;
        return self::package($sheets,$rows);
    }

    private static function worksheet(array $rows,array $disputed=[]): string
    {
        $columns=count($rows[0]);$last=self::column($columns).count($rows);
        $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><dimension ref="A1:'.$last.'"/><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
        $widths=[];
        foreach ($rows[0] as $index=>$header) {
            $widths[$index]=min(65,max(18,mb_strlen((string)$header)+2));
            $column=$index+1;$xml.='<col min="'.$column.'" max="'.$column.'" width="'.$widths[$index].'" customWidth="1"/>';
        }
        $xml.='</cols><sheetData>';
        foreach ($rows as $index=>$values) {
            $number=$index+1;$height=48;
            if ($index>0) {
                $height=32;
                foreach ($values as $column=>$value) {
                    $text=is_array($value)?(string)$value['number']:(string)($value??'');
                    $width=max(10,($widths[$column]??18)-3);
                    $height=max($height,intdiv(mb_strlen($text)+$width-1,$width)*17);
                }
                $height=min(409,$height);
            }
            $xml.='<row r="'.$number.'" ht="'.$height.'" customHeight="1">';
            foreach ($values as $column=>$value) {
                $reference=self::column($column+1).$number;
                $style=$index===0?1:(in_array($column,$disputed[$index-1]??[],true)?2:0);
                if (is_array($value)) {
                    if (!preg_match('/^\d+(?:\.\d+)?$/D',$value['number']??'')) throw new RuntimeException('XLSX_DECIMAL_INVALID');
                    $xml.='<c r="'.$reference.'" s="'.$style.'" t="n"><v>'.$value['number'].'</v></c>';
                } else {
                    $xml.='<c r="'.$reference.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.self::xml($value).'</t></is></c>';
                }
            }
            $xml.='</row>';
        }
        return $xml.'</sheetData><autoFilter ref="A1:'.$last.'"/></worksheet>';
    }

    private static function package(array $sheets,array $catalogue): string
    {
        $directory=APP_ROOT.'/runtime/exports';
        StorageBudget::target($directory.'/premises.xlsx',16777216);
        if (!is_dir($directory) && !mkdir($directory,0700,true)) throw new RuntimeException('XLSX_OUTPUT_DIRECTORY_FAILED');
        $path=tempnam($directory,'premises-');
        if ($path===false) throw new RuntimeException('XLSX_OUTPUT_FAILED');
        $zip=new ZipArchive();
        try {
            if ($zip->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true) throw new RuntimeException('XLSX_OUTPUT_FAILED');
            $types='<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
            $workbook='<?xml version="1.0" encoding="UTF-8"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
            $rels='<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
            $index=0;
            foreach ($sheets as $name=>$data) {
                $index++;
                $types.='<Override PartName="/xl/worksheets/sheet'.$index.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
                $workbook.='<sheet name="'.self::xml($name).'" sheetId="'.$index.'" r:id="rId'.$index.'"/>';
                $rels.='<Relationship Id="rId'.$index.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$index.'.xml"/>';
                $disputed=$index===1?array_map(fn($r)=>json_decode($r['disputed_columns'],true,512,JSON_THROW_ON_ERROR),$catalogue):[];
                $zip->addFromString('xl/worksheets/sheet'.$index.'.xml',self::worksheet($data,$disputed));
            }
            $rels.='<Relationship Id="styles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
            $zip->addFromString('[Content_Types].xml',$types.'</Types>');
            $zip->addFromString('xl/workbook.xml',$workbook.'</sheets></workbook>');
            $zip->addFromString('xl/_rels/workbook.xml.rels',$rels);
            $zip->addFromString('_rels/.rels','<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="workbook" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
            // Exact source export palette/style roles: header244062/FFFFFF, disputedC00000.
            $zip->addFromString('xl/styles.xml','<?xml version="1.0" encoding="UTF-8"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="3"><font><sz val="11"/><name val="Calibri"/></font><font><b/><color rgb="FFFFFFFF"/><sz val="11"/><name val="Calibri"/></font><font><color rgb="FFC00000"/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF244062"/><bgColor indexed="64"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1" applyFont="1" applyFill="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1" applyFont="1"><alignment vertical="top" wrapText="1"/></xf></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
            if (!$zip->close()) throw new RuntimeException('XLSX_OUTPUT_FAILED');
            return file_get_contents($path)?:throw new RuntimeException('XLSX_OUTPUT_FAILED');
        } finally {
            if (is_file($path)) unlink($path); // Only this tempnam-created file, never a directory/source.
        }
    }
}
