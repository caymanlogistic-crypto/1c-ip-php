<?php
declare(strict_types=1);

namespace Modules\Rental;

use DomainException;
use PDO;

/** Admit only a source line whose period and complete object scope can be proved. */
final class YakovlevInvoiceCoverage
{
    private const PARKING='/парковочн\p{L}*\s+мест\p{L}*\s*№?\s*([\p{L}\d.\/-]+)(?=[\s,;])/iu';
    private const MONTHS=['январ'=>1,'феврал'=>2,'март'=>3,'апрел'=>4,'ма[йя]'=>5,'июн'=>6,'июл'=>7,
        'август'=>8,'сентябр'=>9,'октябр'=>10,'ноябр'=>11,'декабр'=>12];

    public static function prove(PDO $db,array $contract,array $source,string $total,?string $issued = null): array
    {
        $rows=array_merge($source['Товары'] ?? [],$source['Услуги'] ?? []);
        if (!$rows || ($source['СуммаВключаетНДС'] ?? null)!==false)
            throw new DomainException('INVOICE_LINES_UNCONFIRMED');
        $q=$db->prepare('SELECT r.object_id,o.name,o.type,t.start_date,t.end_date,t.amount FROM contract_objects r
            JOIN rental_objects o ON o.id=r.object_id
            LEFT JOIN contract_terms t ON t.contract_id=r.contract_id AND t.object_id=r.object_id
                AND t.start_date<=? AND t.end_date>=?
            WHERE r.contract_id=? ORDER BY r.object_id,t.id');
        $lines=[]; $segments=[]; $period=null; $sum='0.00';
        foreach ($rows as $line) {
            $text=trim((string)($line['Содержание'] ?? ''));
            if (!preg_match('/^(?:аренда|арендная плата)\s/iu',$text))
                throw new DomainException('CHARGE_KIND_UNCONFIRMED');
            if (!preg_match('/№\s*'.preg_quote((string)$contract['number'],'/').'(?![\p{L}\d\/-])/u',$text))
                throw new DomainException('INVOICE_CONTRACT_TEXT_UNCONFIRMED');
            [$month,$from,$until]=self::period($text,$issued);
            if ($period!==null && $period!==$month) throw new DomainException('INVOICE_PERIOD_UNCONFIRMED');
            $period=$month;
            if (!$contract['start_date'] || !$contract['end_date'] || $contract['start_date']>$from || $contract['end_date']<$until)
                throw new DomainException('INVOICE_OUTSIDE_CONTRACT');
            $q->execute([$until,$from,$contract['id']]);
            $term=self::object($q->fetchAll(PDO::FETCH_ASSOC),$text);
            $object=(int)$term['object_id'];
            foreach ($segments as $previous) {
                if ($previous['object']===$object && $previous['from']<=$until && $previous['until']>=$from)
                    throw new DomainException('INVOICE_OBJECT_UNCONFIRMED');
            }
            $segments[]=compact('object','from','until');
            $detail=self::line($term,$line,$text,$period,$from,$until);
            $sum=bcadd($sum,bcadd($detail['netAmount'],$detail['vatAmount'],2),2);
            $lines[]=$detail;
        }
        if ($sum!==$total) throw new DomainException('INVOICE_TOTAL_CONFLICT');
        return compact('period','segments','lines');
    }

    public static function hasRentLine(array $source): bool
    {
        foreach (array_merge($source['Товары'] ?? [],$source['Услуги'] ?? []) as $line) {
            if (preg_match('/^\s*(?:аренда|арендная плата)\s/iu',(string)($line['Содержание'] ?? ''))) return true;
        }
        return false;
    }

    public static function parkingReferences(array $source): array
    {
        $labels=[];
        foreach (array_merge($source['Товары'] ?? [],$source['Услуги'] ?? []) as $line)
            if (preg_match(self::PARKING,(string)($line['Содержание'] ?? ''),$match) && mb_strlen($match[1])<=20)
                $labels[$match[1]]=true;
        return array_keys($labels);
    }

    private static function object(array $terms,string $text): array
    {
        $kind=null;
        if (preg_match(self::PARKING,$text,$label)) {
            $kind='parking';
        } elseif (preg_match('/^аренда\s+(офиса|нежилого помещения|апартаментов)\s+(?:(?:№\s*|помещения\s+)([\p{L}\d.\/-]+)(?=[\s,;]))?/iu',$text,$scope)) {
            $kind=mb_strtolower($scope[1])==='апартаментов' ? 'apartment' : 'office';
            if (isset($scope[2])) $label=[null,$scope[2]];
        }
        if ($kind===null) throw new DomainException('INVOICE_OBJECT_UNCONFIRMED');
        // A generic lease line can identify a single contractual object only.
        if (isset($label[1])) $terms=array_values(array_filter($terms,static fn($row)=>mb_strtolower($row['name'])===mb_strtolower($label[1])));
        if (count($terms)!==1 || !$terms[0]['start_date'] || !$terms[0]['end_date'])
            throw new DomainException('INVOICE_OBJECT_UNCONFIRMED');
        $category=match($terms[0]['type']) {
            'Офис'=>'office',
            'Апартаменты'=>'apartment',
            'Машиноместо'=>'parking',
            default=>null,
        };
        if ($category!==$kind) throw new DomainException('INVOICE_OBJECT_UNCONFIRMED');
        return $terms[0]+['category'=>$category];
    }

    private static function line(array $term,array $line,string $text,string $period,string $from,string $until): array
    {
        if (!in_array((string)($line['Количество'] ?? ''),['1','1.0','1.00'],true)
            || !preg_match('/\AНДС([0-9]+)\z/u',(string)($line['СтавкаНДС'] ?? ''),$vat))
            throw new DomainException('INVOICE_LINES_UNCONFIRMED');
        $net=YakovlevRentalSync::cents($line['Сумма'] ?? null);
        $tax=YakovlevRentalSync::cents($line['СуммаНДС'] ?? null,true);
        $rate=YakovlevRentalSync::cents($term['amount']);
        $first=new \DateTimeImmutable($from);
        $last=new \DateTimeImmutable($until);
        $days=$first->diff($last)->days+1;
        $expected=bcadd(bcdiv(bcmul($rate,(string)$days,2),$first->format('t'),8),'0.005',2);
        if (bcadd($net,$tax,2)!==$expected) throw new DomainException('INVOICE_TOTAL_CONFLICT');
        return ['description'=>$text,'unit'=>'счёт','quantity'=>'1','netPrice'=>$net,'netAmount'=>$net,
            'vatRate'=>$vat[1],'vatAmount'=>$tax,'rentalCategory'=>$term['category'],'rentalPeriod'=>$period];
    }

    public static function period(string $text, ?string $issued = null): array
    {
        foreach (self::MONTHS as $stem=>$month) {
            $startMonth = $issued === null ? '' : '(?:\s+'.$stem.'\p{L}*(?:\s+(?<start_year>20\d\d|\d\d)\s*г?\.?)?)?';
            if (!preg_match('/\bза\s+(?:(?:период\s+)?с\s+(?<first>\d{1,2})'.$startMonth
                .'\s+по\s+(?<last>\d{1,2})\s+)?'.$stem.'\p{L}*\s+(?<year>20\d\d|\d\d)\s*г?\.?\s*$/iu',$text,$m)) continue;
            $year = $m['year'];
            if (strlen($year) === 2) {
                $year = '20'.$year;
                if ($issued === null || abs((int)$year - (int)substr($issued,0,4)) > 1) {
                    throw new DomainException('INVOICE_PERIOD_UNCONFIRMED');
                }
            }
            if (!empty($m['start_year'])) {
                $startYear = strlen($m['start_year']) === 2 ? '20'.$m['start_year'] : $m['start_year'];
                if ($startYear !== $year) throw new DomainException('INVOICE_PERIOD_UNCONFIRMED');
            }
            $period=sprintf('%s-%02d',$year,$month);
            [$from,$until]=InvoicePeriods::bounds($period);
            if (!empty($m['first'])) {
                $a=(int)$m['first']; $b=(int)$m['last'];
                if ($a<1 || $a>$b || $b>(int)substr($until,-2)) throw new DomainException('INVOICE_PERIOD_UNCONFIRMED');
                $from=$period.sprintf('-%02d',$a);
                $until=$period.sprintf('-%02d',$b);
            }
            return [$period,$from,$until];
        }
        throw new DomainException('INVOICE_PERIOD_UNCONFIRMED');
    }

    public static function assertVacant(PDO $db,int $contract,array $proof): void
    {
        $q=$db->prepare("SELECT i.id,ic.object_id covered_object,ic.start_date,ic.end_date
            FROM invoices i
            LEFT JOIN invoice_coverage ic ON ic.invoice_id=i.id
            WHERE i.contract_id=? AND i.status<>'revoked' AND COALESCE(i.kind,'')<>'Обеспечительный платёж'
                AND (i.period=? OR (ic.start_date<=? AND ic.end_date>=?))
            ORDER BY i.id FOR UPDATE");
        foreach ($proof['segments'] as $segment) {
            $q->execute([$contract,$proof['period'],$segment['until'],$segment['from']]);
            foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $old) {
                if (!$old['covered_object'] || (int)$old['covered_object']===$segment['object']
                    && $old['start_date']<=$segment['until'] && $old['end_date']>=$segment['from'])
                    throw new DomainException('COVERAGE_ALREADY_EXISTS');
            }
        }
        // An unclassified existing charge without dates can cover this period too.
        $q=$db->prepare("SELECT i.id FROM invoices i WHERE i.contract_id=? AND i.status<>'revoked'
            AND COALESCE(i.kind,'')<>'Обеспечительный платёж' AND NOT EXISTS
            (SELECT 1 FROM invoice_coverage ic WHERE ic.invoice_id=i.id)
            AND (i.period IS NULL OR i.period NOT REGEXP '^[0-9]{4}-[0-9]{2}$') LIMIT 1 FOR UPDATE");
        $q->execute([$contract]);
        if ($q->fetchColumn()) throw new DomainException('COVERAGE_UNCONFIRMED');
    }
}
