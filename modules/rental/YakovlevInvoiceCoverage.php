<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\BillableTerms;
use DomainException;
use PDO;

/** Admit only a source line whose period and complete object scope can be proved. */
final class YakovlevInvoiceCoverage
{
    private const SINGLE_PARKING='/парковочн\p{L}*\s+мест\p{L}*\s*№?\s*([\p{L}\d.\/-]+)(?=[\s,;])/iu';
    private const PARKING='/(?:парковочн\p{L}*\s+|парк\.\s*)(?:машино[–-])?мест\p{L}*\s*№?\s*(\d+[.,]\d+(?:\s*[,;]\s*\d+[.,]\d+)*)(?=[\s,;])/iu';
    private const MONTHS=['январ'=>1,'феврал'=>2,'март'=>3,'апрел'=>4,'ма[йя]'=>5,'июн'=>6,'июл'=>7,
        'август'=>8,'сентябр'=>9,'октябр'=>10,'ноябр'=>11,'декабр'=>12];

    public static function prove(PDO $db,array $contract,array $source,string $total,?string $issued = null,bool $recheckArchivedInvoice = false,array $parkingAccountingMap = []): array
    {
        $rows=array_merge($source['Товары'] ?? [],$source['Услуги'] ?? []);
        $includesVat=$source['СуммаВключаетНДС'] ?? null;
        if (!$rows || !is_bool($includesVat) || ($includesVat && !$recheckArchivedInvoice))
            throw new DomainException('INVOICE_LINES_UNCONFIRMED');
        $q=$db->prepare('SELECT r.object_id,o.name,o.type,t.start_date,t.end_date,t.amount FROM contract_objects r
            JOIN rental_objects o ON o.id=r.object_id
            LEFT JOIN contract_terms t ON t.contract_id=r.contract_id AND t.object_id=r.object_id
                AND t.start_date<=? AND t.end_date>=?
                AND (t.effective_date IS NULL OR t.effective_date<=?)
                AND (t.superseded_on IS NULL OR t.superseded_on>=?)
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
            $billingEnd=$recheckArchivedInvoice ? ArchivedRentCorrection::confirmedBillingEnd($contract,$month) : $contract['end_date'];
            if (!$contract['start_date'] || !$billingEnd || $contract['start_date']>$from || $billingEnd<$until)
                throw new DomainException('INVOICE_OUTSIDE_CONTRACT');
            $q->execute([$from,$until,$from,$until,$contract['id']]);
            $terms=self::objects($q->fetchAll(PDO::FETCH_ASSOC),$text,$recheckArchivedInvoice,$parkingAccountingMap);
            if (!$recheckArchivedInvoice && (count($terms)!==1 ||
                !in_array((string)($line['Количество'] ?? ''),['1','1.0','1.00'],true)))
                throw new DomainException('INVOICE_OBJECT_UNCONFIRMED');
            foreach ($terms as $term) {
                $object=(int)$term['object_id'];
                foreach ($segments as $previous) {
                    if ($previous['object']===$object && $previous['from']<=$until && $previous['until']>=$from)
                        throw new DomainException('INVOICE_OBJECT_UNCONFIRMED');
                }
                $segments[]=compact('object','from','until');
            }
            $wholeRate=null;
            if ($recheckArchivedInvoice && count($rows)===1 && count($terms)>1 && array_column($terms,'amount')===array_fill(0,count($terms),null)) {
                $allTerms=BillableTerms::forContract($db,(int)$contract['id']);
                if (count($terms)===count($allTerms) && ($contract['servicing_effective_date'] ?? $from)<=$from)
                    $wholeRate=MonthlyRentReconciliation::sharedMonthlyRent($db,$contract,$allTerms);
            }
            $detail=self::line($terms,$line,$text,$period,$from,$until,$includesVat,$wholeRate);
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
        foreach (array_merge($source['Товары'] ?? [],$source['Услуги'] ?? []) as $line) {
            foreach (self::parkingLabels((string)($line['Содержание'] ?? '')) as $label) $labels[$label]=true;
        }
        return array_keys($labels);
    }

    private static function parkingLabels(string $text): array
    {
        if (!preg_match(self::PARKING,$text,$scope)) return [];
        preg_match_all('/\d+[.,]\d+/',$scope[1],$labels);
        return array_map(static fn(string $label): string=>str_replace(',','.',$label),$labels[0]);
    }

    private static function objects(array $terms,string $text,bool $recheckArchivedInvoice,array $parkingAccountingMap): array
    {
        $wanted=$recheckArchivedInvoice ? self::parkingLabels($text)
            : (preg_match(self::SINGLE_PARKING,$text,$legacy) ? [mb_strtolower($legacy[1])] : []);
        if ($wanted) {
            $wanted=array_map(static fn(string $label): string=>$parkingAccountingMap[$label] ?? $label,$wanted);
            $categories=['Машиноместо'=>'parking'];
        } elseif (preg_match('/^аренда\s+(офиса|нежилого помещения|апартаментов|помещения)\s+(?:(?:№\s*|помещения\s+)([\p{L}\d.\/-]+)(?=[\s,;]))?/iu',$text,$scope)) {
            $categories=match(mb_strtolower($scope[1])) {
                'помещения'=>$recheckArchivedInvoice ? ['Офис'=>'office','Апартаменты'=>'apartment'] : [],
                'апартаментов'=>['Апартаменты'=>'apartment'],
                default=>['Офис'=>'office'],
            };
            if (isset($scope[2])) $wanted=[mb_strtolower($scope[2])];
        } else {
            throw new DomainException('INVOICE_OBJECT_UNCONFIRMED');
        }

        $matches=[];
        foreach ($terms as $term) {
            $label=mb_strtolower($term['name']);
            if ($wanted && !in_array($label,$wanted,true)) continue;
            $category=$categories[$term['type']] ?? null;
            if (!$category || isset($matches[$label]) || !$term['start_date'] || !$term['end_date'])
                throw new DomainException('INVOICE_OBJECT_UNCONFIRMED');
            $matches[$label]=$term+['category'=>$category];
        }
        if (!$matches || ($wanted && count($matches)!==count($wanted))
            || count(array_unique(array_column($matches,'category')))!==1)
            throw new DomainException('INVOICE_OBJECT_UNCONFIRMED');
        return array_values($matches);
    }

    private static function line(array $terms,array $line,string $text,string $period,string $from,string $until,bool $includesVat,?string $wholeRate): array
    {
        $quantity=YakovlevRentalSync::cents($line['Количество'] ?? null);
        if (($quantity!=='1.00' && ($wholeRate!==null || $quantity!==count($terms).'.00'))
            || !preg_match('/\AНДС([0-9]+)\z/u',(string)($line['СтавкаНДС'] ?? ''),$vat))
            throw new DomainException('INVOICE_LINES_UNCONFIRMED');
        $sourceAmount=YakovlevRentalSync::cents($line['Сумма'] ?? null);
        $tax=YakovlevRentalSync::cents($line['СуммаНДС'] ?? null,true);
        $net=$includesVat ? bcsub($sourceAmount,$tax,2) : $sourceAmount;
        if (bccomp($net,'0.00',2)!==1) throw new DomainException('INVOICE_LINES_UNCONFIRMED');
        $first=new \DateTimeImmutable($from);
        $last=new \DateTimeImmutable($until);
        $days=$first->diff($last)->days+1;
        $expected='0.00';
        $rates=$wholeRate===null ? array_column($terms,'amount') : [$wholeRate];
        foreach ($rates as $value) {
            $rate=YakovlevRentalSync::cents($value);
            $portion=bcadd(bcdiv(bcmul($rate,(string)$days,2),$first->format('t'),8),'0.005',2);
            $expected=bcadd($expected,$portion,2);
        }
        if (bcadd($net,$tax,2)!==$expected) throw new DomainException('INVOICE_TOTAL_CONFLICT');
        $price=bcdiv($net,$quantity,2);
        if (bcmul($price,$quantity,2)!==$net || ($quantity!=='1.00' &&
            bcmul(YakovlevRentalSync::cents($line['Цена'] ?? null),$quantity,2)!==$sourceAmount))
            throw new DomainException('INVOICE_LINES_UNCONFIRMED');
        return ['description'=>$text,'unit'=>'счёт','quantity'=>rtrim(rtrim($quantity,'0'),'.'),'netPrice'=>$price,'netAmount'=>$net,
            'vatRate'=>$vat[1],'vatAmount'=>$tax,'rentalCategory'=>$terms[0]['category'],'rentalPeriod'=>$period];
    }

    public static function period(string $text, ?string $issued = null): array
    {
        if ($issued !== null && preg_match('/\bс\s+(\d{2}\.\d{2}\.\d{2,4})(?:\s*г\.)?\s+по\s+(\d{2}\.\d{2}\.\d{2,4})(?:\s*г\.?)?\s*$/iu',$text,$range)) {
            $dates=[];
            foreach (array_slice($range,1) as $value) {
                [$day,$month,$year]=explode('.',$value);
                $year=strlen($year)===2 ? '20'.$year : $year;
                if (!checkdate((int)$month,(int)$day,(int)$year) || abs((int)$year-(int)substr($issued,0,4))>1)
                    throw new DomainException('INVOICE_PERIOD_UNCONFIRMED');
                $dates[]=$year.'-'.$month.'-'.$day;
            }
            [$from,$until]=$dates;
            if ($from>$until || substr($from,0,7)!==substr($until,0,7))
                throw new DomainException('INVOICE_PERIOD_UNCONFIRMED');
            return [substr($from,0,7),$from,$until];
        }
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
