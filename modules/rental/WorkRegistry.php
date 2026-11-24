<?php
declare(strict_types = 1);
namespace Modules\Rental;
use App\View;
final class WorkRegistry {
    public const HEADERS = [
        'Договор',
        'Контрагент',
        'Начало',
        'Окончание',
        'Объекты аренды',
        'Стадия',
        'Комментарий',
        'Аренда с НДС',
        'Ответственный'
    ];
    public const COLUMNS = [
        'short',
        'long',
        'short',
        'code',
        'text',
        'text',
        'flex',
        'money',
        'text'
    ];

    public static function matches(string $query, array $values): bool {
        $text = mb_strtolower(implode(' ', array_map(fn($v) => (string)$v, $values)));
        foreach (preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) as $token)if (!str_contains($text, $token))return false;
        return true;
    }

    public static function render(bool $bookingOnly=false): string {
        if ($bookingOnly) {
            CustomerBookings::readAccess();
        } else {
            Access::pages(['contracts']);
            Service::roles(['manager']);
        }
        $query = trim((string)($_GET['q'] ?? ''));
        $flag = (string)($_GET['flag'] ?? ($bookingOnly?'booking':'all'));
        if (!in_array($flag,$bookingOnly?['booking','history']:['all','draft','booking','history'],true)) $flag=$bookingOnly?'booking':'all';
        $contracts=$bookingOnly?[]:Controller::query("SELECT c.*,COALESCE(p.name,'—') party_name FROM contracts c LEFT JOIN parties p ON p.id=c.party_id WHERE c.status='draft' AND ".ContractDraft::visibleSql()." ORDER BY c.id DESC");
        $documents=$bookingOnly?[]:Controller::query("SELECT d.*,c.number base_number,c.document_date base_document_date,p.name party_name FROM rental_documents d JOIN contracts c ON c.id=d.contract_id JOIN parties p ON p.id=c.party_id WHERE d.status='draft' AND ".WorkspaceReset::visibleSql('d')." AND JSON_UNQUOTE(JSON_EXTRACT(d.source_json,'$.entryMode'))='current' ORDER BY d.id DESC");
        $open=[]; $history=[];
        $items=Controller::query("SELECT r.*,o.name object_name,o.type object_type,o.source_json object_source,c.number cadastre_number,
            c.source_json cadastre_source,u.name author,p.name party_name FROM reservations r
            JOIN rental_objects o ON o.id=r.object_id JOIN cadastre c ON c.id=o.cadastre_id
            LEFT JOIN users u ON u.id=r.author_id LEFT JOIN parties p ON p.id=r.party_id
            WHERE r.document_id IS NULL AND ".WorkspaceReset::visibleSql('r')." ORDER BY r.id DESC");
        foreach ($items as $r) {
            if (!DirectoryScope::realObject(['source_json'=>$r['object_source']],
                ['number'=>$r['cadastre_number'],'source_json'=>$r['cadastre_source']])) continue;
            if (CustomerBookings::openForDecision($r) && !CustomerBookings::expired($r,date('Y-m-d'))) $open[]=$r;
            else $history[]=$r;
        }
        $bookings=$flag==='history'?$history:$open;
        $h=Controller::tabs($bookingOnly ? ['booking'=>['Резервирование',count($open)],'history'=>['История',count($history)]]
            : ['all'=>['Все',count($contracts)+count($documents)+count($open)],'draft'=>['Черновики',count($contracts)+count($documents)],
               'booking'=>['Резервирование',count($open)],'history'=>['История резервирования',count($history)]],$flag,true);
        $rows = [
        ];
        $manager = People::label('manager');
        if (in_array($flag,['all','draft'],true)) {
            foreach ($contracts as $c) {
                $draft = ContractDraft::input($c);
                $objects = $draft === null ? Controller::objectNames((int)$c['id']) : View::e(implode(', ', array_column(ContractDraft::objects(\App\DB::pdo(), $draft), 'name')));
                if (!self::matches($query, [
                    $c['number'],
                    $c['party_name'],
                    strip_tags($objects),
                    'Черновик',
                    $c['start_date'],
                    $c['end_date']
                ]))continue;
                $rows[] = [
                    '_attrs' => 'data-record-key="contract:'
                    . $c['id']
                    . '" data-open="contract" data-id="'
                    . $c['id']
                    . '" data-rental-url="/rental/edit-contract?id='
                    . $c['id']
                    . '" tabindex="0"',
                    View::e($c['number']),
                    View::e($c['party_name']),
                    Documents::dateText($c['start_date']),
                    Documents::dateText($c['end_date']),
                    $objects,
                    'Черновик',
                    '',
                    Controller::money($c['amount']),
                    View::e($manager)
                ];
            }
            foreach ($documents as $d) {
                $objects = Controller::query('SELECT o.name FROM document_terms t JOIN rental_objects o ON o.id=t.object_id WHERE t.document_id=? ORDER BY o.id', [
                    $d['id']
                ]);
                $names = implode(', ', array_column($objects, 'name'));
                if (!self::matches($query, [
                    $d['number'],
                    $d['document_date'],
                    Documents::dateText($d['document_date']),
                    $d['base_number'],
                    $d['party_name'],
                    $names,
                    'ДС',
                    'Черновик'
                ]))continue;
                $rows[] = [
                    '_attrs' => 'data-record-key="document:'
                    . $d['id']
                    . '" data-open="document" data-id="'
                    . $d['id']
                    . '" data-rental-url="/rental/document?id='
                    . $d['id']
                    . '" tabindex="0"',
                    View::e($d['number']?'ДС №'
                    . $d['number']:'ДС'),
                    View::e($d['party_name']),
                    Documents::dateText($d['start_date']),
                    Documents::dateText($d['end_date']),
                    View::e($names),
                    'Черновик',
                    View::e('К договору №'
                    . $d['base_number']
                    . ($d['base_document_date']?' от '
                    . Documents::dateText($d['base_document_date']):'')),
                    Controller::money($d['amount']),
                    View::e($manager)
                ];
            }
        }
        if ($flag !== 'draft')foreach ($bookings as $r) {
            $expired=CustomerBookings::expired($r,date('Y-m-d'));
            $status=$expired?'Срок истёк':match ($r['status']) {
                'active'=>'Резервирование', 'consumed','converted'=>'Передано в договор', 'cancelled'=>'Снято',
                default=>'Завершено',
            };
            $author=$r['author'] ?: $manager;
            if (!self::matches($query, [
                CustomerBookings::client($r),
                $r['object_name'],
                $r['object_type'],
                $r['comment'],
                $r['until_date'],
                Documents::dateText($r['until_date']),
                $status,
                $author
            ]))continue;
            $rows[] = [
                '_attrs' => 'class="'.($expired?'registry-ui-warning':'registry-ui-soft-info').'" data-record-key="reservation:'
                . $r['id']
                . '" data-rental-url="/rental/booking?id='
                . $r['id']
                . '" tabindex="0"',
                '№'.(int)$r['id'],
                View::e(CustomerBookings::client($r)),
                Documents::dateText($r['start_date']),
                Documents::dateText($r['until_date']),
                View::e($r['object_name']),
                View::e($status),
                '<strong>Срок до '
                . View::e(Documents::dateText($r['until_date']))
                . '</strong>'
                . ($r['comment']?'<div>'
                . View::e($r['comment'])
                . '</div>':''),
                '—',
                View::e($author)
            ];
        }
        return $h
        . '<section class="table-card registry-ui-card registry-ui-sticky-table registry-ui-scroll" data-registry-id="rental-contracts-work" data-registry-managed-markup="true">'
        . '<div class="table-toolbar registry-ui-toolbar">'
        . '<div class="found-label">Найдено: <b>'
        . count($rows)
        . '</b></div><div class="toolbar-right"><form method="get" data-rental-registry-filter><input type="hidden" name="flag" value="'
        . View::e($flag)
        . '"><input class="registry-ui-search" id="search" name="q" type="search" value="'
        . View::e($query)
        . '" placeholder="Поиск по всем столбцам" aria-label="Поиск по реестру"></form></div></div>'
        . Controller::table(self::HEADERS, $rows, self::COLUMNS)
        . '</section>';
    }
}
