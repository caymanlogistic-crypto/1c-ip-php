<?php
declare(strict_types=1);
namespace Modules\Rental;

use DOMDocument;
use DOMElement;
use DOMXPath;
use RuntimeException;
use ZipArchive;

/** Bounded OOXML substitution; source styles, geometry and untouched ZIP parts survive. */
final class DocxTemplate
{
    private const W='http://schemas.openxmlformats.org/wordprocessingml/2006/main';
    private const XML='http://www.w3.org/XML/1998/namespace';

    private static function xml(string $bytes): DOMDocument
    {
        if (preg_match('/<!DOCTYPE|<!ENTITY/i',$bytes)) throw new RuntimeException('DTD и сущности в Word запрещены.');
        $document=new DOMDocument();
        $document->preserveWhiteSpace=true;
        $old=libxml_use_internal_errors(true);
        try {
            if (!$document->loadXML($bytes,LIBXML_NONET) || $document->doctype!==null) throw new RuntimeException('Некорректный XML Word.');
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($old);
        }
        return $document;
    }

    public static function validate(string $path,int $maxBytes=5*1024*1024): void
    {
        if (!is_file($path) || filesize($path)>$maxBytes) throw new RuntimeException('Размер Word превышает '.(int)($maxBytes/1024/1024).' МБ.');
        $zip=new ZipArchive();
        if ($zip->open($path)!==true) throw new RuntimeException('Некорректный Word-шаблон.');
        try {
            if ($zip->numFiles>2000 || $zip->locateName('word/document.xml')===false || $zip->locateName('[Content_Types].xml')===false) {
                throw new RuntimeException('Некорректный состав Word-шаблона.');
            }
            $names=[]; $size=0;
            for ($index=0;$index<$zip->numFiles;$index++) {
                $entry=$zip->statIndex($index);
                $name=str_replace('\\','/',$entry['name']);
                $size+=$entry['size'];
                if (isset($names[$name]) || $size>25*1024*1024) throw new RuntimeException('Word-шаблон превышает допустимый распакованный размер или содержит повторяющиеся части.');
                $names[$name]=true;
                if (str_starts_with($name,'/') || preg_match('/^[a-z]:/i',$name) || in_array('..',explode('/',$name),true)
                    || preg_match('~vbaproject|embeddings/|activex/~i',$name)) {
                    throw new RuntimeException('Word с макросами, вложенными объектами или недопустимыми путями не поддерживается.');
                }
                if (!str_ends_with($name,'.xml') && !str_ends_with($name,'.rels')) continue;
                $bytes=$zip->getFromIndex($index);
                if (!is_string($bytes)) throw new RuntimeException('Не удалось прочитать часть Word.');
                $document=self::xml($bytes);
                if ($name==='[Content_Types].xml' && str_contains($bytes,'macroEnabled')) throw new RuntimeException('Макросы в шаблоне запрещены.');
                $instructions='';
                foreach ($document->getElementsByTagName('*') as $node) {
                    if (str_ends_with($name,'.rels') && strtolower(trim($node->getAttribute('TargetMode')))==='external') {
                        throw new RuntimeException('Внешние связи в Word-шаблоне запрещены.');
                    }
                    if ($node->namespaceURI===self::W && in_array($node->localName,['instrText','fldSimple'],true)) {
                        $instructions.=$node->textContent.' '.$node->getAttributeNS(self::W,'instr').' ';
                    }
                }
                $instructions=strtoupper(preg_replace('/\s+/u','',$instructions));
                foreach (['DDE','INCLUDETEXT','INCLUDEPICTURE','LINK','DATABASE','MACROBUTTON'] as $active) {
                    if (str_contains($instructions,$active)) throw new RuntimeException('Активные поля Word не поддерживаются.');
                }
            }
        } finally {
            $zip->close();
        }
    }

    private static function paragraphs(DOMDocument $document): iterable
    {
        $query=new DOMXPath($document);
        $query->registerNamespace('w',self::W);
        foreach ($query->query('//w:p') as $paragraph) {
            $texts=[]; $text='';
            foreach ($paragraph->getElementsByTagNameNS(self::W,'t') as $node) {
                $texts[]=$node;
                $text.=$node->textContent;
            }
            yield [$texts,$text,$paragraph];
        }
    }

    public static function fields(string $path,bool $withoutPlan=false): array
    {
        self::validate($path);$zip=new ZipArchive();$zip->open($path);$fields=[];
        try {
            for($i=0;$i<$zip->numFiles;$i++) {
                $name=$zip->getNameIndex($i);if(!str_starts_with($name,'word/')||!str_ends_with($name,'.xml'))continue;
                foreach(self::paragraphs(self::xml($zip->getFromIndex($i))) as [$nodes,$text]) {
                    if ($withoutPlan && preg_match('/\{\{office_plan\}\}|поэтажн|план\p{L}*\s+(?:здания|помещения|помещений|апартаментов)|на\s+плане/iu',$text)) {
                        throw new RuntimeException('В шаблоне для физлица удалите план и ссылки на него.',422);
                    }
                    preg_match_all('/\{\{([^{}]+)\}\}/u',$text,$matches);
                    foreach($matches[1] as $field) {
                        if(!preg_match('/^[a-z][a-z0-9_]{0,63}$/D',$field))throw new RuntimeException('Имя поля Word: латинские буквы, цифры и подчёркивание, до 64 знаков.',422);
                        $fields[$field]=true;
                        if(count($fields)>200)throw new RuntimeException('В шаблоне допускается до 200 различных полей.',422);
                    }
                }
            }
        } finally {$zip->close();}
        return array_keys($fields);
    }

    /** Replace matched characters only, preserving the formatting of surrounding runs. */
    private static function fillParagraph(DOMDocument $document,array $texts,string $text,array $values,array $phrases=[]): bool
    {
        preg_match_all('/\{\{([^{}]+)\}\}/u',$text,$matches,PREG_OFFSET_CAPTURE);
        $replacements=[];
        foreach ($matches[0] as $index=>[$token,$offset]) {
            $key=$matches[1][$index][0];
            if (!array_key_exists($key,$values)) throw new RuntimeException('Не заполнено поле Word: '.$key);
            $replacements[$offset]=[$token,$values[$key]];
        }
        foreach ($phrases as $phrase=>$replacement) {
            $offset=strpos($text,$phrase);
            if ($offset!==false) $replacements[$offset]=[$phrase,$replacement];
        }
        if (!$replacements) return false;
        $ranges=[];$offset=0;
        foreach ($texts as $node) {
            $length=mb_strlen($node->textContent);
            $ranges[]=[$node,$offset,$offset+$length];$offset+=$length;
        }
        // Work right to left so earlier original offsets remain valid even in the same run.
        krsort($replacements);
        foreach ($replacements as $byteOffset=>[$original,$replacement]) {
            $start=mb_strlen(substr($text,0,$byteOffset));$end=$start+mb_strlen($original);
            $first=true;
            foreach ($ranges as [$node,$from,$to]) {
                if ($to<=$start || $from>=$end) continue;
                $prefix=$from<$start?mb_substr($node->textContent,0,$start-$from):'';
                $suffix=$to>$end?mb_substr($node->textContent,$end-$from):'';
                $node->textContent=$prefix.($first?str_replace(["\r\n","\r"],"\n",$replacement):'').$suffix;
                $node->setAttributeNS(self::XML,'xml:space','preserve');$first=false;
            }
        }
        foreach ($texts as $node) {
            if (!str_contains($node->textContent,"\n")) continue;
            $lines=explode("\n",$node->textContent);$node->textContent=array_shift($lines);$cursor=$node;
            foreach ($lines as $line) {
                $break=$document->createElementNS(self::W,'w:br');
                $node->parentNode->insertBefore($break,$cursor->nextSibling);
                $next=$document->createElementNS(self::W,'w:t');$next->textContent=$line;
                $next->setAttributeNS(self::XML,'xml:space','preserve');
                $node->parentNode->insertBefore($next,$break->nextSibling);$cursor=$next;
            }
        }
        return true;
    }

    private static function insertLeaseTerms(DOMDocument $document,DOMElement $slot,array $terms): void
    {
        $table=$document->createElementNS(self::W,'w:tbl');
        $properties=$document->createDocumentFragment();
        $properties->appendXML('<w:tblPr xmlns:w="'.self::W.'"><w:tblW w:w="5000" w:type="pct"/>'
            .'<w:tblBorders><w:top w:val="single" w:color="D9D9D9"/><w:left w:val="single" w:color="D9D9D9"/>'
            .'<w:bottom w:val="single" w:color="D9D9D9"/><w:right w:val="single" w:color="D9D9D9"/>'
            .'<w:insideH w:val="single" w:color="D9D9D9"/><w:insideV w:val="single" w:color="D9D9D9"/>'
            .'</w:tblBorders><w:tblLayout w:type="fixed"/></w:tblPr>');
        $table->appendChild($properties);
        $page=$document->getElementsByTagNameNS(self::W,'pgSz')->item(0);
        $margins=$document->getElementsByTagNameNS(self::W,'pgMar')->item(0);
        $width=(int)$page?->getAttributeNS(self::W,'w')-(int)$margins?->getAttributeNS(self::W,'left')
            -(int)$margins?->getAttributeNS(self::W,'right');
        $widths=array_map(static fn(int $percent): int => intdiv(($width>0?$width:8640)*$percent,100),[33,9,34,24]);
        $grid=$table->appendChild($document->createElementNS(self::W,'w:tblGrid'));
        foreach ($widths as $columnWidth) {
            $grid->appendChild($document->createElementNS(self::W,'w:gridCol'))->setAttributeNS(self::W,'w:w',(string)$columnWidth);
        }
        $rows=[['Объект','м²','Период аренды','Руб./мес. с НДС'],...$terms['rows']];
        foreach ($rows as $index=>$row) {
            $tr=$table->appendChild($document->createElementNS(self::W,'w:tr'));
            $rowProperties=$tr->appendChild($document->createElementNS(self::W,'w:trPr'));
            $rowProperties->appendChild($document->createElementNS(self::W,'w:cantSplit'));
            if ($index===0) $rowProperties->appendChild($document->createElementNS(self::W,'w:tblHeader'));
            foreach ($row as $column=>$text) {
                $cell=$tr->appendChild($document->createElementNS(self::W,'w:tc'));
                $cellProperties=$cell->appendChild($document->createElementNS(self::W,'w:tcPr'));
                $width=$cellProperties->appendChild($document->createElementNS(self::W,'w:tcW'));
                $width->setAttributeNS(self::W,'w:type','dxa');
                $width->setAttributeNS(self::W,'w:w',(string)$widths[$column]);
                $paragraph=$cell->appendChild($document->createElementNS(self::W,'w:p'));
                $paragraph->appendChild($document->createElementNS(self::W,'w:pPr'))
                    ->appendChild($document->createElementNS(self::W,'w:jc'))
                    ->setAttributeNS(self::W,'w:val',$column===1||$column===3?'right':'left');
                $run=$paragraph->appendChild($document->createElementNS(self::W,'w:r'));
                if ($index===0) {
                    $cellProperties->appendChild($document->createElementNS(self::W,'w:shd'))->setAttributeNS(self::W,'w:fill','F2F2F2');
                    $run->appendChild($document->createElementNS(self::W,'w:rPr'))->appendChild($document->createElementNS(self::W,'w:b'));
                }
                $cellProperties->appendChild($document->createElementNS(self::W,'w:vAlign'))->setAttributeNS(self::W,'w:val','center');
                $run->appendChild($document->createElementNS(self::W,'w:t'))->textContent=$text;
            }
        }
        $slot->parentNode->insertBefore($table,$slot);
    }

    public static function build(string $source,array $values,string $kind,string $target,?array $requiredFields=null,array $plans=[],array $leaseTerms=[],array $phrases=[]): void
    {
        self::validate($source);
        foreach ($values as $value) {
            if (!is_string($value) || mb_strlen($value)>30000 || str_contains($value,"\0")) throw new RuntimeException('Недопустимое содержимое документа.');
        }
        $zip=new ZipArchive();
        if ($zip->open($source)!==true) throw new RuntimeException('Не удалось открыть шаблон Word.');
        $tokens=[]; $documents=[];$planSlot=null;
        try {
            for ($index=0;$index<$zip->numFiles;$index++) {
                $name=$zip->getNameIndex($index);
                if (!str_starts_with($name,'word/') || !str_ends_with($name,'.xml')) continue;
                $document=self::xml($zip->getFromIndex($index));
                foreach (self::paragraphs($document) as [$texts,$text,$paragraph]) {
                    if (str_contains($text,'{{office_plan}}')) {
                        if ($planSlot!==null || $name!=='word/document.xml' || trim($text)!=='{{office_plan}}'
                            || $paragraph->parentNode->localName!=='body') {
                            throw new RuntimeException('Поместите {{office_plan}} в один отдельный абзац приложения № 1.',422);
                        }
                        $planSlot=$paragraph;
                        continue;
                    }
                    preg_match_all('/\{\{([^{}]+)\}\}/u',$text,$matches);
                    foreach ($matches[1] as $token) $tokens[$token]=true;
                }
                $documents[$name]=$document;
            }
        } finally {
            $zip->close();
        }
        if (($planSlot!==null)!==($plans!==[])) {
            throw new RuntimeException('Для приложения № 1 Word нужны поле {{office_plan}} и план помещения.',422);
        }
        $required=['number','date','party','organization','terms','total','vat'];
        if ($kind==='addendum') $required=array_merge($required,['base','effective']);
        if ($requiredFields!==null) $required=$requiredFields;
        if (array_diff($required,array_keys($tokens)) || array_diff(array_keys($tokens),array_keys($values))) {
            throw new RuntimeException('Word-шаблон не содержит всех полей текущих условий или содержит неизвестные поля. Используйте основу Word либо приложите готовый Word/PDF.');
        }
        foreach ($required as $key) if (trim($values[$key] ?? '')==='') throw new RuntimeException('Не заполнены реквизиты или условия Word: '.$key);
        $changed=[];
        foreach ($documents as $name=>$document) {
            $modified=false;
            foreach (self::paragraphs($document) as [$texts,$text,$paragraph]) {
                if ($planSlot!==null && $paragraph->isSameNode($planSlot)) continue;
                if ($leaseTerms && $name==='word/document.xml' && trim($text)==='{{terms}}' && $paragraph->parentNode->localName==='body') {
                    self::insertLeaseTerms($document,$paragraph,$leaseTerms);
                    $modified=self::fillParagraph($document,$texts,$text,['terms'=>$leaseTerms['notes']])||$modified;
                    continue;
                }
                $modified=self::fillParagraph($document,$texts,$text,$values,$name==='word/document.xml'?$phrases:[])||$modified;
            }
            if ($modified) $changed[$name]=$document->saveXML();
        }
        $temporary=$target.'.'.bin2hex(random_bytes(8)).'.tmp';
        try {
            if (!copy($source,$temporary)) throw new RuntimeException('Не удалось скопировать шаблон Word.');
            $out=new ZipArchive();
            if ($out->open($temporary)!==true) throw new RuntimeException('Не удалось открыть Word.');
            try {
                if ($planSlot!==null) {
                    $document=$documents['word/document.xml'];
                    WordAppendixPlan::append($document,$planSlot,$out,$plans);
                    $changed['word/document.xml']=$document->saveXML();
                }
                foreach ($changed as $name=>$bytes) if (!$out->addFromString($name,$bytes)) throw new RuntimeException('Не удалось сохранить поля Word.');
            } finally {
                $out->close();
            }
            if (!rename($temporary,$target)) throw new RuntimeException('Не удалось сохранить Word.');
        } finally {
            if (is_file($temporary)) unlink($temporary);
        }
    }
}
