<?php
declare(strict_types=1);
namespace NativeOnec;

/** Fixed GET-only Yakovlev invoice context. No caller query/resource/owner. */
trait FreshRead
{
    private const FRESH_ORG='5b2b76f9-0ae3-11ea-80e4-005056953851';
    private const FRESH_INN='390613939405';
    private const FRESH_CATALOGS=['Catalog_Организации','Catalog_Контрагенты','Catalog_ДоговорыКонтрагентов',
        'Catalog_Номенклатура','Catalog_КлассификаторЕдиницИзмерения','Catalog_БанковскиеСчета'];
    private function freshReferences(string $catalog,array $keys):array
    {
        if(!in_array($catalog,self::FRESH_CATALOGS,true))throw new \RuntimeException('READ_ROUTE_REJECTED');
        $keys=array_values(array_unique($keys));
        if(count($keys)>100)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        if($keys===[])return [];
        foreach($keys as$key)if(!is_string($key)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$key)||$key==='00000000-0000-0000-0000-000000000000')throw new \RuntimeException('READ_KEY_INVALID');
        [$rows,$more]=$this->readRows($catalog,['$format'=>'json','$top'=>(string)(count($keys)+1),
            '$filter'=>'DeletionMark eq false and ('.implode(' or ',array_map(static fn($key)=>"Ref_Key eq guid'".$key."'",$keys)).')','$orderby'=>'Ref_Key'],count($keys)+1);
        if($more||count($rows)!==count($keys))throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        $result=[];
        foreach($rows as$row){$key=$row['Ref_Key']??null;if(!is_string($key)||!in_array($key,$keys,true)||isset($result[$key])||($row['DeletionMark']??null)!==false)throw new \RuntimeException('READ_KEY_INVALID');$result[$key]=$row;}
        return $result;
    }
    public function liveInvoiceContext():array
    {
        $org=$this->freshReferences('Catalog_Организации',[self::FRESH_ORG]);
        if(($org[self::FRESH_ORG]['ИНН']??null)!==self::FRESH_INN)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        // Organization selection precedes TOP; never take three arbitrary sellers.
        [$invoices,$more]=$this->readRows(self::COLLECTIONS['invoices'],['$format'=>'json','$top'=>'3',
            '$filter'=>"DeletionMark eq false and Организация_Key eq guid'".self::FRESH_ORG."'",'$orderby'=>'Date desc,Ref_Key desc'],3);
        if(count($invoices)!==3)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        return $this->freshContext($invoices,$org);
    }
    public function observedInvoiceContext(array $observed):array
    {
        $key=$observed['Ref_Key']??null;$version=$observed['DataVersion']??null;
        if(!is_string($key)||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$key)||!is_string($version)||$version===''||strlen($version)>250||($observed['Организация_Key']??null)!==self::FRESH_ORG||($observed['DeletionMark']??null)!==false)throw new \RuntimeException('READ_KEY_INVALID');
        $org=$this->freshReferences('Catalog_Организации',[self::FRESH_ORG]);
        if(($org[self::FRESH_ORG]['ИНН']??null)!==self::FRESH_INN)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        [$invoices,$more]=$this->readRows(self::COLLECTIONS['invoices'],['$format'=>'json','$top'=>'2',
            '$filter'=>"DeletionMark eq false and Организация_Key eq guid'".self::FRESH_ORG."' and Ref_Key eq guid'".$key."'"],2);
        if($more||count($invoices)!==1||($invoices[0]['Ref_Key']??null)!==$key||($invoices[0]['DataVersion']??null)!==$version)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        return $this->freshContext($invoices,$org);
    }
    private function freshContext(array $invoices,array $org):array
    {
        $buyers=[];$contracts=[];$products=[];$directUnits=[];$versions=[];
        foreach($invoices as$row){
            if(($row['Организация_Key']??null)!==self::FRESH_ORG||($row['DeletionMark']??null)!==false||!is_string($row['DataVersion']??null)||!is_array($row['Товары']??null)||count($row['Товары'])>100)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
            $key=$row['Ref_Key']??null;if(!is_string($key)||isset($versions[$key]))throw new \RuntimeException('READ_KEY_INVALID');
            $versions[$key]=$row['DataVersion'];$buyers[]=$row['Контрагент_Key']??null;$contracts[]=$row['ДоговорКонтрагента_Key']??null;
            foreach($row['Товары']as$item){
                if(!is_array($item))throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
                $product=$item['Номенклатура']??$item['Номенклатура_Key']??null;
                if($product!==null&&$product!==''&&$product!=='00000000-0000-0000-0000-000000000000')$products[]=$product;
                $unit=$item['ЕдиницаИзмерения_Key']??null;
                if($unit!==null&&$unit!==''&&$unit!=='00000000-0000-0000-0000-000000000000')$directUnits[]=$unit;
            }
        }
        $customers=$this->freshReferences('Catalog_Контрагенты',$buyers);
        $agreements=$this->freshReferences('Catalog_ДоговорыКонтрагентов',$contracts);
        $items=$this->freshReferences('Catalog_Номенклатура',$products);
        $unitKeys=$directUnits;
        foreach($items as$item){$key=$item['ЕдиницаИзмерения_Key']??null;if($key!==null&&$key!==''&&$key!=='00000000-0000-0000-0000-000000000000')$unitKeys[]=$key;}
        $units=$this->freshReferences('Catalog_КлассификаторЕдиницИзмерения',$unitKeys);
        // Detect invoice edits while related references were being read.
        [$again,$next]=$this->readRows(self::COLLECTIONS['invoices'],['$format'=>'json','$top'=>(string)(count($invoices)+1),
            '$select'=>'Ref_Key,DataVersion','$filter'=>implode(' or ',array_map(static fn($key)=>"Ref_Key eq guid'".$key."'",array_keys($versions)))],count($invoices)+1);
        if($next||count($again)!==count($invoices))throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        $checked=[];
        foreach($again as$row){$key=$row['Ref_Key']??null;if(!is_string($key)||isset($checked[$key])||!isset($versions[$key])||($row['DataVersion']??null)!==$versions[$key])throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');$checked[$key]=true;}
        return ['organization_ref'=>self::FRESH_ORG,'organization_inn'=>self::FRESH_INN,'invoices'=>$invoices,
            'catalogs'=>['organizations'=>$org,'buyers'=>$customers,'contracts'=>$agreements,'products'=>$items,'units'=>$units]];
    }
}
