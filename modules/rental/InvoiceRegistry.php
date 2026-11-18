<?php
declare(strict_types=1);
namespace Modules\Rental;

use App\{Auth, Menu, View};

/** The two source invoice registers share one financial projection. */
final class InvoiceRegistry
{
    public const HEADERS = ['Счёт','Контрагент','Договор','Вид','Оплатить до','Сумма','Оплачено','Остаток','Состояние'];
    private const COLUMNS = ['code','flex','short','text','code','money','money','money','flex'];
    private const FLAGS = ['all'=>'Все','unpaid'=>'Неоплаченные','overdue'=>'Просроченные','attention'=>'Требуют внимания','paid'=>'Оплаченные'];
    private const PAYMENT_FLAGS = ['all'=>'Все','waiting'=>'Ожидают оплаты','overdue'=>'Просрочка оплаты','paid'=>'Оплаченные'];
    private const PRIORITY = ['red'=>4,'yellow'=>3,'blue'=>2,'green'=>1,''=>0];
    private const CLASSES = ['red'=>'registry-ui-soft-error','yellow'=>'registry-ui-warning','blue'=>'registry-ui-soft-info','green'=>'registry-ui-soft-success',''=>''];

    private static function normalized(string $value): string
    {
        return trim(preg_replace('/\s+/u',' ',mb_strtolower($value,'UTF-8')));
    }

    private static function date(?string $date): string
    {
        return $date ? Documents::dateText($date) : '—';
    }

    public static function selected(array $row, string $flag): bool
    {
        return match ($flag) {
            'unpaid','waiting','overdue','attention','paid' => $row['_view'][$flag],
            default => true,
        };
    }

    public static function totals(array $rows): array
    {
        $result = [
            'count'=>count($rows),'amount'=>'0.00','paid'=>'0.00','debt'=>'0.00',
            'amount_unknown'=>0,'paid_unknown'=>0,'debt_unknown'=>0,
            'unpaid'=>0,'overdue_count'=>0,'overdue'=>'0.00',
        ];
        foreach ($rows as $row) {
            $view = $row['_view'] ?? InvoiceFacts::view($row);
            foreach (['amount','paid'] as $key) {
                if ($row[$key] === null) $result[$key.'_unknown']++;
                else $result[$key] = bcadd($result[$key],$row[$key],2);
            }
            if ($view['remaining'] === null) $result['debt_unknown']++;
            if ($view['unpaid']) {
                $result['unpaid']++;
                $result['debt'] = bcadd($result['debt'],$view['remaining'],2);
                if ($view['overdue']) {
                    $result['overdue_count']++;
                    $result['overdue'] = bcadd($result['overdue'],$view['remaining'],2);
                }
            }
        }
        return $result;
    }

    public static function totalText(array $totals, string $key): string
    {
        $missing = $totals[$key.'_unknown'];
        if ($missing && $missing === $totals['count']) return 'Не указано';
        return Controller::money($totals[$key]).($missing ? '<div class="doc-meta">Нет данных: '.$missing.'</div>' : '');
    }

    private static function counts(array $rows): array
    {
        $counts = [0,0,0,0];
        $indexes = ['red'=>0,'yellow'=>1,'green'=>2,'blue'=>3];
        foreach ($rows as $row) {
            $severity = $row['_view']['severity'];
            if (isset($indexes[$severity])) $counts[$indexes[$severity]]++;
        }
        return $counts;
    }

    public static function row(array $row): array
    {
        $view = $row['_view'] ?? InvoiceFacts::view($row);
        $collection = match ($row['status']) {
            'frozen'=>'Напоминания приостановлены',
            'revoked'=>'Счёт отозван',
            default=>'',
        };
        $state = $view['paid'] && !$view['severity']
            ? '<strong class="registry-ui-success">'.View::e($view['state']).'</strong>'
            : View::e($view['state']);
        $state.='<div class="doc-meta">'.View::e($collection ?: $view['explanation']).'</div>';
        if ($collection && $view['severity']) $state.='<div class="doc-meta">'.View::e($view['explanation']).'</div>';
        return [
            '<strong>'.View::e($row['number']).'</strong><div class="doc-meta">'.self::date($row['issued']).'</div>',
            View::e($row['party_name']).(!empty($row['party_inn']) ? '<div class="doc-meta">ИНН: '.View::e($row['party_inn']).'</div>' : ''),
            View::e($row['contract_number']),
            View::e($row['kind'] === 'Обеспечительный платёж' ? 'Обеспечение' : $row['kind']).'<div class="doc-meta">'.View::e($row['period']).'</div>',
            self::date($row['due']),
            Controller::money($row['amount']),
            Controller::money($row['paid']),
            Controller::money($view['remaining']),
            $state,
        ];
    }

    private static function records(int $contractId): array
    {
        $user = Auth::user();
        $severity = [];
        foreach (Indicators::tasks(null,null,['invoices']) as $task) {
            if ($task['entity'] !== 'invoice' || ($user['role'] !== 'admin' && $task['role'] !== $user['role'])) continue;
            $old = $severity[$task['id']] ?? '';
            if (self::PRIORITY[$task['severity']] > self::PRIORITY[$old]) $severity[$task['id']] = $task['severity'];
        }
        $rows = Controller::query(
            'SELECT i.*,c.number contract_number,c.party_id,p.name party_name,p.inn party_inn FROM invoices i JOIN contracts c ON c.id=i.contract_id JOIN parties p ON p.id=c.party_id WHERE (?=0 OR c.id=?) ORDER BY i.id',
            [$contractId,$contractId]
        );
        foreach ($rows as &$row) {
            $row['_delivery']=InvoiceDelivery::presentation(\App\DB::pdo(),(int)$row['id']);
            $row['_view'] = InvoiceFacts::view($row,$severity[$row['id']] ?? '');
        }
        unset($row);
        return $rows;
    }

    private static function matches(array $row, array $tokens): bool
    {
        $view = $row['_view'];
        $text = self::normalized(implode(' ',[
            $row['number'],$row['issued'],self::date($row['issued']),$row['contract_number'],
            $row['party_name'],$row['party_inn'],$row['kind'],$row['title'] ?? '',$row['purpose'] ?? '',
            $row['period'],$row['due'],self::date($row['due']),
            $row['amount'] === null ? '' : strip_tags(Controller::money($row['amount'])),
            $row['paid'] === null ? '' : strip_tags(Controller::money($row['paid'])),
            $view['remaining'] === null ? '' : strip_tags(Controller::money($view['remaining'])),
            $view['state'],$view['explanation'],
        ]));
        foreach ($tokens as $token) if (!str_contains($text,$token)) return false;
        return true;
    }

    private static function compare(array $a, array $b): int
    {
        $av=$a['_view']; $bv=$b['_view'];
        return (int)$bv['unpaid'] <=> (int)$av['unpaid']
            ?: self::PRIORITY[$bv['severity']] <=> self::PRIORITY[$av['severity']]
            ?: (int)$bv['overdue'] <=> (int)$av['overdue']
            ?: strcmp($a['due'] ?: '9999',$b['due'] ?: '9999')
            ?: strnatcasecmp($a['number'],$b['number'])
            ?: (int)$a['id'] <=> (int)$b['id'];
    }

    private static function groups(array $rows): array
    {
        $groups=[];
        foreach ($rows as $row) $groups[$row['party_id']][]=$row;
        $result=[];
        foreach ($groups as $id=>$items) {
            usort($items,self::compare(...));
            $severity=''; $nearest=null; $reasons=[];
            foreach ($items as $item) {
                $view=$item['_view'];
                if (self::PRIORITY[$view['severity']] > self::PRIORITY[$severity]) $severity=$view['severity'];
                if ($view['unpaid'] && $item['due'] && ($nearest === null || $item['due'] < $nearest)) $nearest=$item['due'];
                if ($view['severity'] && $view['explanation']) $reasons[$view['explanation']]=true;
            }
            $result[]=['id'=>$id,'items'=>$items,'totals'=>self::totals($items),'severity'=>$severity,'nearest'=>$nearest,'reasons'=>array_keys($reasons)];
        }
        usort($result,static function (array $a,array $b): int {
            return (int)($b['totals']['unpaid']>0) <=> (int)($a['totals']['unpaid']>0)
                ?: self::PRIORITY[$b['severity']] <=> self::PRIORITY[$a['severity']]
                ?: bccomp($b['totals']['overdue'],$a['totals']['overdue'],2)
                ?: strcmp($a['nearest'] ?? '9999',$b['nearest'] ?? '9999')
                ?: strnatcasecmp(mb_strtolower($a['items'][0]['party_name']),mb_strtolower($b['items'][0]['party_name']))
                ?: (int)$a['id'] <=> (int)$b['id'];
        });
        return $result;
    }

    private static function groupHeader(array $group): string
    {
        $t=$group['totals']; $party=$group['items'][0]; $severity=$group['severity'];
        $unknown=$t['amount_unknown'] || $t['paid_unknown'] || $t['debt_unknown'];
        $status=match (true) {
            $severity === 'red'=>'Есть ошибки', $severity === 'yellow'=>'Требует внимания',
            $t['unpaid']>0=>'Есть неоплаченные счета', $unknown=>'Не все суммы известны',
            default=>'Нет неоплаченных счетов',
        };
        $reason=implode('; ',array_slice($group['reasons'],0,2));
        if (count($group['reasons'])>2) $reason.=' · ещё '.(count($group['reasons'])-2).' — раскройте счета';
        $name=View::e($party['party_name']); $id=(int)$group['id'];
        $html='<tr class="'.self::CLASSES[$severity].'" data-row-key="invoice-group:'.$id.'" data-rental-parent-group="'.$id.'">';
        $html.='<td colspan="5"><button class="btn btn-secondary" type="button" data-rental-toggle-group="'.$id.'" data-group-name="'.$name.'" aria-expanded="false" aria-label="Показать счета: '.$name.'">+</button> <strong>'.$name.'</strong>';
        if ($party['party_inn']) $html.=' · ИНН '.View::e($party['party_inn']);
        $html.='<div class="doc-meta">Счетов: '.$t['count'].' · Неоплаченных: '.$t['unpaid'].' · Просроченных: '.$t['overdue_count'];
        if ($t['overdue_count']) $html.=' · Просроченная сумма: '.Controller::money($t['overdue']);
        $html.=' · Ближайший срок: '.($group['nearest'] ? self::date($group['nearest']) : 'не указан').'</div></td>';
        foreach (['amount','paid','debt'] as $key) $html.='<td class="registry-ui-number">'.self::totalText($t,$key).'</td>';
        return $html.'<td class="registry-column-text">'.View::e($status).Menu::badges(self::counts($group['items']),true).($reason ? '<div class="doc-meta">'.View::e($reason).'</div>' : '').'</td></tr>';
    }

    private static function invoiceRow(array $row, bool $grouped): string
    {
        $html='<tr class="'.self::CLASSES[$row['_view']['severity']].'" data-row-key="invoice:'.(int)$row['id'].'" data-rental-url="/rental/invoice?id='.(int)$row['id'].'" tabindex="0"';
        if ($grouped) $html.=' data-rental-invoice-group="'.(int)$row['party_id'].'" hidden';
        $html.='>';
        foreach (self::row($row) as $index=>$cell) {
            $class = self::COLUMNS[$index] === 'money' ? 'registry-ui-number' : (in_array($index,[0,2,3,8],true) ? 'registry-column-text' : '');
            $html.='<td'.($class ? ' class="'.$class.'"' : '').'>'.$cell.'</td>';
        }
        return $html.'</tr>';
    }

    private static function controls(array $all,string $query,string $party,string $flag,int $cid,bool $grouped): string
    {
        $html='<form method="get" class="registry-ui-tabs" role="group" aria-label="Разделы списка"><input type="hidden" name="party" value="'.View::e($party).'">';
        if ($cid) $html.='<input type="hidden" name="contract_id" value="'.$cid.'">';
        if ($query !== '') $html.='<input type="hidden" name="q" value="'.View::e($query).'">';
        foreach (($grouped ? self::FLAGS : self::PAYMENT_FLAGS) as $key=>$label) {
            $items=array_values(array_filter($all,static fn($r)=>self::selected($r,$key)));
            $badges=$grouped ? self::counts($items) : [$key === 'overdue' ? count($items) : 0,0,0,0];
            $html.='<button type="submit" name="flag" value="'.$key.'" class="'.($flag === $key ? 'is-active' : '').'" aria-pressed="'.($flag === $key ? 'true' : 'false').'">'.View::e($label).' <b>'.count($items).'</b>'.Menu::badges($badges,true).'</button>';
        }
        $html.='</form>';
        return $html;
    }

    public static function render(bool $grouped): string
    {
        Access::finance();
        $reset=isset($_GET['reset']);
        $query=$reset ? '' : trim((string)($_GET['q'] ?? ''));
        $party=$reset ? 'all' : (string)($_GET['party'] ?? 'all');
        $flag=(string)($_GET['flag'] ?? 'all');
        if (!$grouped && $flag === 'unpaid') $flag='waiting';
        $flags=$grouped ? self::FLAGS : self::PAYMENT_FLAGS;
        if (!isset($flags[$flag])) $flag='all';
        $cid=(int)($_GET['contract_id'] ?? 0);
        $records=self::records($cid);
        $parties=[];
        foreach ($records as $row) $parties[$row['party_id']]=$row['party_name'];
        asort($parties,SORT_NATURAL|SORT_FLAG_CASE);
        $tokens=array_filter(explode(' ',self::normalized($query)));
        $all=array_values(array_filter($records,static fn($r)=>($party === 'all' || (string)$r['party_id'] === $party) && self::matches($r,$tokens)));
        $chosen=array_values(array_filter($all,static fn($r)=>self::selected($r,$flag)));
        $groups=self::groups($chosen);
        $html=self::controls($all,$query,$party,$flag,$cid,$grouped);
        $t=self::totals($chosen);
        $registryId=$grouped ? 'rental-invoices-grouped' : 'rental-invoices-flat';
        $html.='<section class="table-card registry-ui-card registry-ui-sticky-table registry-ui-scroll" data-rental-invoice-registry data-registry-id="'.$registryId.'" data-registry-managed-markup="true"><div class="table-toolbar registry-ui-toolbar"><div class="found-label">Найдено: <b>'.count($chosen).'</b> · Клиентов: <b>'.count($groups).'</b> · Сумма: <b>'.self::totalText($t,'amount').'</b> · Оплачено: <b>'.self::totalText($t,'paid').'</b> · Остаток: <b>'.self::totalText($t,'debt').'</b></div><div class="toolbar-right"><form method="get" data-rental-registry-filter>';
        if ($cid) $html.='<input type="hidden" name="contract_id" value="'.$cid.'">';
        $html.='<input type="hidden" name="flag" value="'.View::e($flag).'">';
        $html.='<button class="registry-ui-reset" type="submit" name="reset" value="1"'.($query === '' && $party === 'all' ? ' hidden' : '').'>Сбросить фильтры</button>';
        $html.='<select id="party-filter" class="field-select toolbar-select" name="party" aria-label="Контрагент"><option value="all">Все контрагенты</option>';
        foreach ($parties as $id=>$name) $html.='<option value="'.(int)$id.'"'.((string)$id === $party ? ' selected' : '').'>'.View::e($name).'</option>';
        $html.='</select><input class="registry-ui-search" name="q" type="search" value="'.View::e($query).'" placeholder="Поиск по всем столбцам" aria-label="Поиск по реестру"></form></div></div><div class="table-scroll"><table class="table registry-ui-table registry-ui-data" data-column-profile="rental-contracts"><colgroup>';
        foreach (self::COLUMNS as $column) $html.='<col class="registry-column-'.$column.'">';
        $html.='</colgroup><thead><tr>';
        foreach (self::HEADERS as $i=>$header) $html.='<th'.(self::COLUMNS[$i] === 'money' ? ' class="registry-ui-number"' : '').'>'.$header.'</th>';
        $html.='</tr></thead><tbody>';
        foreach ($groups as $group) {
            if ($grouped) $html.=self::groupHeader($group);
            foreach ($group['items'] as $row) $html.=self::invoiceRow($row,$grouped);
        }
        if (!$chosen) $html.='<tr><td colspan="9">По заданным условиям счета не найдены.</td></tr>';
        return $html.'</tbody></table></div></section>';
    }
}
