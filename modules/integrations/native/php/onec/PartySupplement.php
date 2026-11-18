<?php
declare(strict_types=1);
namespace NativeOnec;

/** Fixed GET-only supplement for explicitly bound parties, never arbitrary OData or credentials. */
trait PartySupplement
{
    private const SUPPLEMENT_CATALOGS=['Catalog_КонтактныеЛица','Catalog_ВидыКонтактнойИнформации','Catalog_РолиКонтактныхЛиц','Catalog_Банки','Catalog_Валюты'];
    private function supplementRefs(string $catalog,array $keys,string $select): array
    {
        $keys=array_values(array_unique(array_filter($keys,static fn($k)=>$k!==null && $k!=='' && $k!=='00000000-0000-0000-0000-000000000000')));
        if(count($keys)>100)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        foreach($keys as $key)if(!is_string($key) || !preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$key))throw new \RuntimeException('READ_KEY_INVALID');
        if(!$keys)return [];
        [$rows,$more]=$this->readRows($catalog,['$format'=>'json','$top'=>(string)(count($keys)+1),'$select'=>$select,
            '$filter'=>'DeletionMark eq false and ('.implode(' or ',array_map(static fn($k)=>"Ref_Key eq guid'".$k."'",$keys)).')'],count($keys)+1);
        if($more)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');$out=[];
        foreach($rows as $row){$key=$row['Ref_Key']??null;if(!in_array($key,$keys,true)||isset($out[$key])||($row['DeletionMark']??null)!==false||!is_string($row['DataVersion']??null)||$row['DataVersion']==='')throw new \RuntimeException('READ_KEY_INVALID');$out[$key]=$row;}
        return $out;
    }
    public function partySupplement(array $keys): array
    {
        if(!$keys || count($keys)>100 || count($keys)!==count(array_unique($keys)))throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        $parties=$this->supplementRefs('Catalog_Контрагенты',$keys,'Ref_Key,DataVersion,DeletionMark,IsFolder,Code,Description,ИНН,КПП,НаименованиеПолное,ОсновнойБанковскийСчет_Key,ОсновноеКонтактноеЛицо_Key,КонтактнаяИнформация');
        if(count($parties)!==count($keys))throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        // Only related contacts; birthdays, identity documents and account passwords are not selected.
        [$contacts,$more]=$this->readRows('Catalog_КонтактныеЛица',['$format'=>'json','$top'=>'201',
            '$select'=>'Ref_Key,DataVersion,DeletionMark,Description,Должность,Роль_Key,ОбъектВладелец,ОбъектВладелец_Type,КонтактнаяИнформация',
            '$filter'=>"DeletionMark eq false and ОбъектВладелец_Type eq 'StandardODATA.Catalog_Контрагенты' and (".implode(' or ',array_map(static fn($k)=>"ОбъектВладелец eq '".$k."'",$keys)).')'],201);
        if($more || count($contacts)>200)throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');$contactMap=[];
        foreach($contacts as $c){if(!in_array($c['ОбъектВладелец']??null,$keys,true)||($c['ОбъектВладелец_Type']??'')!=='StandardODATA.Catalog_Контрагенты'||($c['DeletionMark']??null)!==false||!preg_match('/^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D',$c['Ref_Key']??'')||!is_string($c['DataVersion']??null)||$c['DataVersion']===''||isset($contactMap[$c['Ref_Key']??'']))throw new \RuntimeException('READ_KEY_INVALID');$contactMap[$c['Ref_Key']]=$c;}
        $banks=$this->supplementRefs('Catalog_БанковскиеСчета',array_column($parties,'ОсновнойБанковскийСчет_Key'),
            'Ref_Key,DataVersion,DeletionMark,Description,Owner,Owner_Type,НомерСчета,Банк_Key,БанкДляРасчетов_Key,Валютный,ВалютаДенежныхСредств_Key,ДатаОткрытия,ДатаЗакрытия');
        $bankNames=$this->supplementRefs('Catalog_Банки',array_column($banks,'Банк_Key'),'Ref_Key,DataVersion,DeletionMark,Code,Description,КоррСчет');
        $currencies=$this->supplementRefs('Catalog_Валюты',array_column($banks,'ВалютаДенежныхСредств_Key'),'Ref_Key,DataVersion,DeletionMark,Code,Description');
        $kindKeys=[];foreach([...array_values($parties),...$contacts] as $row)foreach($row['КонтактнаяИнформация']??[] as $ci)$kindKeys[]=$ci['Вид_Key']??null;
        $kinds=$this->supplementRefs('Catalog_ВидыКонтактнойИнформации',$kindKeys,'Ref_Key,DataVersion,DeletionMark,Description,PredefinedDataName,ИмяПредопределенногоВида,Тип');
        $roles=$this->supplementRefs('Catalog_РолиКонтактныхЛиц',array_column($contacts,'Роль_Key'),'Ref_Key,DataVersion,DeletionMark,Description');
        $again=$this->supplementRefs('Catalog_Контрагенты',$keys,'Ref_Key,DataVersion,DeletionMark');
        foreach($parties as $key=>$row)if(!is_string($row['DataVersion']??null)||$row['DataVersion']===''||($again[$key]['DataVersion']??null)!==$row['DataVersion'])throw new \RuntimeException('READ_SCHEMA_OR_LIMIT');
        return ['parties'=>$parties,'contacts'=>$contactMap,'bank_accounts'=>$banks,'banks'=>$bankNames,'currencies'=>$currencies,'kinds'=>$kinds,'roles'=>$roles,
            'edo'=>'NOT_EXPOSED_IN_REVIEWED_COUNTERPARTY_METADATA'];
    }
}
