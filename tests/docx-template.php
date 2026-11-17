<?php
declare(strict_types=1);
require dirname(__DIR__).'/app/bootstrap.php';
use Modules\Rental\DocxTemplate;
function expect(bool $value,string $message): void {if(!$value)throw new RuntimeException($message);}
$directory=APP_ROOT.'/runtime/docx-test-'.bin2hex(random_bytes(5));mkdir($directory,0700,true);
$source=$directory.'/source.docx';$target=$directory.'/filled.docx';
try {
 $xml='<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
 .'<w:p><w:r><w:rPr><w:b/></w:rPr><w:t>Договор № </w:t></w:r><w:r><w:rPr><w:u w:val="single"/></w:rPr><w:t>{{num</w:t></w:r><w:r><w:rPr><w:i/></w:rPr><w:t>ber}} / </w:t></w:r><w:r><w:rPr><w:b/></w:rPr><w:t>{{date}}</w:t></w:r><w:r><w:t> конец</w:t></w:r></w:p>'
 .'<w:p><w:r><w:t>Перед {{party}} между {{organization}} после</w:t></w:r></w:p>'
 .'<w:p><w:r><w:t>{{terms}} {{total}} {{vat}}</w:t></w:r></w:p></w:body></w:document>';
 $zip=new ZipArchive();$zip->open($source,ZipArchive::CREATE);$zip->addFromString('[Content_Types].xml','<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>');$zip->addFromString('word/document.xml',$xml);$zip->addFromString('word/styles.xml','<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"/>');$zip->addFromString('word/media/opaque.bin',random_bytes(40));$zip->close();
 $values=['number'=>'ТЕСТ-42','date'=>'18.11.2026','party'=>'ООО «А & Б»','organization'=>'ИП <Яковлев>','terms'=>"Первая строка\nВторая строка",'total'=>'12500.00','vat'=>'20%'];DocxTemplate::build($source,$values,'office',$target);
 $a=new ZipArchive();$a->open($source);$b=new ZipArchive();$b->open($target);
 foreach(['[Content_Types].xml','word/styles.xml','word/media/opaque.bin'] as $part)expect($a->getFromName($part)===$b->getFromName($part),'Untouched part changed: '.$part);
 $doc=new DOMDocument();expect($doc->loadXML($b->getFromName('word/document.xml')),'Invalid output XML');$q=new DOMXPath($doc);$q->registerNamespace('w','http://schemas.openxmlformats.org/wordprocessingml/2006/main');
 $runs=$q->query('//w:p[1]/w:r');expect($runs[0]->textContent==='Договор № ','Bold prefix moved');expect($runs[1]->textContent==='ТЕСТ-42','Split token was not filled in its run');expect($runs[2]->textContent===' / ','Italic suffix lost');expect($q->query('//w:p[1]/w:r[2]/w:rPr/w:u')->length===1,'Token underline lost');
 expect($q->query('//w:p[1]/w:r[3]/w:rPr/w:i')->length===1,'Suffix italic lost');expect($q->query('//w:br')->length===1,'Multiline value lost');expect(str_contains($doc->textContent,'Перед ООО «А & Б» между ИП <Яковлев> после'),'Same-run fields or escaping damaged');expect(!str_contains($doc->textContent,'{{'),'Unfilled token');
 echo "PASS: split tokens, mixed styles, Unicode/XML escaping, multiple fields per run, newlines, untouched package parts\n";
 $a->close();$b->close();
} finally {foreach([$source,$target] as $path)if(is_file($path))unlink($path);rmdir($directory);}
