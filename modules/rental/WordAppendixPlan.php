<?php
declare(strict_types=1);
namespace Modules\Rental;

use DOMDocument;
use DOMElement;
use PDO;
use RuntimeException;
use ZipArchive;

/** Plans for the Word appendix, as images or unchanged PDF companions. */
final class WordAppendixPlan
{
    private const DRAWING='http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing';
    private const REL='http://schemas.openxmlformats.org/package/2006/relationships';
    private const OFFICE_REL='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    private const CONTENT='http://schemas.openxmlformats.org/package/2006/content-types';
    private const REL_PATH='word/_rels/document.xml.rels';

    public static function load(PDO $db,array $terms,array &$missing): array
    {
        $plans=[];
        foreach ($terms as $term) {
            $id=(int)$term['object_id'];
            if (isset($plans[$id]) || isset($missing[$id])) continue;
            $plan=DirectoryDetails::read($db,'object_plan',$id);
            if (!$plan) {
                $missing[$id]=$term['name'];
                continue;
            }
            $bytes=base64_decode($plan['content'],true);
            if ($bytes===false || !hash_equals($plan['sha256'],hash('sha256',$bytes))) {
                throw new RuntimeException('Проверка целостности плана объекта «'.$term['name'].'» не пройдена.',409);
            }
            if ($plan['mime']==='application/pdf' && str_starts_with($bytes,'%PDF-')) {
                $plans[$id]=['sha256'=>$plan['sha256'],'bytes'=>$bytes,'mime'=>$plan['mime'],'name'=>$term['name']];
                continue;
            }
            $image=@getimagesizefromstring($bytes);
            $mime=$image['mime']??'';
            if (!in_array($mime,['image/png','image/jpeg'],true) || $mime!==$plan['mime']) {
                throw new RuntimeException('План объекта «'.$term['name'].'» должен быть в PDF, PNG или JPEG.',422);
            }
            [$width,$height]=$image;
            $scale=min(9525,5400000/$width,6500000/$height);
            $plans[$id]=['sha256'=>$plan['sha256'],'bytes'=>$bytes,'mime'=>$mime,
                'width'=>max(1,(int)($width*$scale)),'height'=>max(1,(int)($height*$scale))];
        }
        return $plans;
    }

    public static function append(DOMDocument $doc,DOMElement $slot,ZipArchive $zip,array $plans): void
    {
        $relationships=new DOMDocument();
        if (!$relationships->loadXML($zip->getFromName(self::REL_PATH)?:'<Relationships xmlns="'.self::REL.'"/>',LIBXML_NONET)) {
            throw new RuntimeException('Не удалось прочитать связи Word.');
        }
        $types=new DOMDocument();
        if (!$types->loadXML($zip->getFromName('[Content_Types].xml'),LIBXML_NONET)) {
            throw new RuntimeException('Не удалось прочитать типы частей Word.');
        }
        $used=[];
        foreach ($doc->getElementsByTagNameNS(self::DRAWING,'docPr') as $node) $used[$node->getAttribute('id')]=true;
        $n=1;$assets=[];$hasPdf=false;
        foreach ($plans as $plan) {
            if ($plan['mime']==='application/pdf') {
                $hasPdf=true;
                continue;
            }
            $hash=$plan['sha256'];
            if (isset($assets[$hash])) continue;
            $assets[$hash]=true;
            $ext=$plan['mime']==='image/png'?'png':'jpeg';
            $media='media/office-plan-'.bin2hex(random_bytes(8)).'.'.$ext;
            $rid='rIdOfficePlan'.bin2hex(random_bytes(8));
            if (!$zip->addFromString('word/'.$media,$plan['bytes'])) throw new RuntimeException('Не удалось сохранить изображение плана.');
            $rel=$relationships->createElementNS(self::REL,'Relationship');
            foreach (['Id'=>$rid,'Target'=>$media,'Type'=>self::OFFICE_REL.'/image'] as $key=>$value) $rel->setAttribute($key,$value);
            $relationships->documentElement->appendChild($rel);
            $type=$types->createElementNS(self::CONTENT,'Override');
            $type->setAttribute('PartName','/word/'.$media);
            $type->setAttribute('ContentType',$plan['mime']);
            $types->documentElement->appendChild($type);
            while (isset($used[$n])) $n++;
            $slot->parentNode->insertBefore(self::paragraph($doc,$plan,$rid,$n++),$slot);
        }
        if ($hasPdf) {
            $w=$slot->namespaceURI;
            $paragraph=$doc->createElementNS($w,'w:p');
            $run=$paragraph->appendChild($doc->createElementNS($w,'w:r'));
            $run->appendChild($doc->createElementNS($w,'w:t','План в PDF входит в пакет отдельным файлом приложения № 1.'));
            $slot->parentNode->insertBefore($paragraph,$slot);
        }
        $slot->parentNode->removeChild($slot);
        if (!$zip->addFromString(self::REL_PATH,$relationships->saveXML()) ||
            !$zip->addFromString('[Content_Types].xml',$types->saveXML())) {
            throw new RuntimeException('Не удалось сохранить связи с планом.');
        }
    }

    private static function paragraph(DOMDocument $doc,array $plan,string $rid,int $id): \DOMDocumentFragment
    {
        $cx=$plan['width'];$cy=$plan['height'];$fragment=$doc->createDocumentFragment();
        $fragment->appendXML(<<<XML
<w:p xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"
     xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing"
     xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main"
     xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture"
     xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <w:pPr><w:jc w:val="center"/></w:pPr>
  <w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">
    <wp:extent cx="$cx" cy="$cy"/><wp:docPr id="$id" name="План помещения $id"/>
    <a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">
      <pic:pic><pic:nvPicPr><pic:cNvPr id="0" name="План помещения"/><pic:cNvPicPr/></pic:nvPicPr>
        <pic:blipFill><a:blip r:embed="$rid"/><a:stretch><a:fillRect/></a:stretch></pic:blipFill>
        <pic:spPr><a:xfrm><a:off x="0" y="0"/><a:ext cx="$cx" cy="$cy"/></a:xfrm>
          <a:prstGeom prst="rect"><a:avLst/></a:prstGeom></pic:spPr>
      </pic:pic>
    </a:graphicData></a:graphic>
  </wp:inline></w:drawing></w:r>
</w:p>
XML);
        return $fragment;
    }
}
