<?php
declare(strict_types=1);

namespace Modules\Integrations;

use App\{Auth,DB,Http,View};

final class YakovlevPage
{
    private const URL = '/connections/onec-yakovlev';

    public static function handle(): string
    {
        Auth::requirePermission('integrations.read');
        $db = DB::pdo();
        $tabs = YakovlevImport::TABS + ['history'=>'Журнал загрузок'];
        $tab = (string)($_GET['tab']??'parties');
        if (!isset($tabs[$tab])) throw new \RuntimeException('Вкладка не найдена',404);
        $canImport = PageView::canEdit(YakovlevImport::PROVIDER,'integrations.write');
        if (($_SERVER['REQUEST_METHOD']??'GET') === 'POST') {
            if (!$canImport) throw new \RuntimeException('Нет права на импорт',403);
            Http::checkCsrf();
            $actor = (int)Auth::user()['id'];
            $from = (string)($_POST['from']??'');
            $to = (string)($_POST['to']??'');
            try {
                $result = (new YakovlevImport($db,Config::store()))->import($tab,$from,$to,$actor,(string)($_POST['request_key']??''));
                $_SESSION['yakovlev_notice'] = match($result['status']) {
                    'success' => 'Сохранено записей: '.$result['coverage']['records_imported'].'.',
                    'pending' => 'Этот запуск уже зарегистрирован. Проверьте журнал загрузок.',
                    default => 'Этот запуск завершился ошибкой. Подробности в журнале загрузок.',
                };
            } catch (\Throwable $error) {
                $_SESSION['yakovlev_notice'] = match($error->getMessage()) {
                    'IMPORT_BUSY' => 'Загрузка уже выполняется.',
                    'INVALID_IMPORT_PERIOD' => 'Проверьте даты. Выберите период продолжительностью до года.',
                    'AUTH_REQUIRED' => '1С отклонила доступ.',
                    'YAKOVLEV_READ_NOT_ENABLED' => 'Чтение на этом сервере пока не настроено.',
                    'NETWORK_OR_TLS_FAILED','TIME_LIMIT','REQUEST_BUDGET','YAKOVLEV_IMPORT_LIMIT','RESPONSE_TOO_LARGE'
                        => 'Не удалось получить всю выборку. Для документов попробуйте меньший период.',
                    default => 'Импорт не выполнен. Проверьте доступность 1С и данные источника.',
                };
                error_log('Yakovlev import: '.(preg_match('/^[A-Z_]+$/D',$error->getMessage())?$error->getMessage():get_class($error)));
            }
            Http::redirect(self::URL.'?tab='.$tab);
        }
        $e = Support::e(...);
        $body = PageView::header(YakovlevImport::TITLE,'Чтение из 1С и сохранение данных в системе','');
        $notice = $_SESSION['yakovlev_notice']??null;
        unset($_SESSION['yakovlev_notice']);
        if ($notice !== null) $body .= '<p role="status" class="form-alert alert-info">'.$e($notice).'</p>';
        $body .= PageView::tabs($tabs,$tab);
        if ($canImport && $tab !== 'history') $body .= self::importForm($tab);
        $body .= self::registry($db,$tab);
        if (isset($_GET['document'])) $body .= RegistryView::detail(YakovlevImport::PROVIDER,(string)$_GET['document']);
        $body .= '<script defer src="/design/system/palette-runtime.js"></script><script defer src="/design/system/registry-interactions.js"></script>';
        return View::page(YakovlevImport::TITLE,$body,'connection-onec-yakovlev',['connection-onec-ui.js']);
    }

    private static function importForm(string $tab): string
    {
        $today = new \DateTimeImmutable('today',new \DateTimeZone('Europe/Moscow'));
        $first = YakovlevImport::FIRST_DAY;
        $from = max($first,$today->modify('-30 days')->format('Y-m-d'));
        $html = '<form method="post" action="'.self::URL.'?tab='.$tab.'" class="table-toolbar registry-ui-toolbar">'
            .'<input type="hidden" name="_csrf" value="'.Support::e(Http::csrf()).'">'
            .'<input type="hidden" name="request_key" value="'.Support::uuid().'">';
        if ($tab !== 'parties') {
            foreach (['from'=>['С',$from],'to'=>['По',$today->format('Y-m-d')]] as $key=>[$label,$value]) {
                $html .= '<label class="field"><span class="field-label">'.$label.'</span><input class="field-input" type="date" name="'.$key.'" min="'.$first.'" max="'.$today->format('Y-m-d').'" value="'.$value.'" required></label>';
            }
        }
        return $html.'<div class="toolbar-right"><button type="submit" class="btn btn-primary">Загрузить из 1С</button></div></form>';
    }

    private static function registry(\PDO $db, string $tab): string
    {
        $e = Support::e(...);
        $history = $tab === 'history';
        $parties = $tab === 'parties';
        $search = mb_substr(trim((string)($_GET['q']??'')),0,200);
        $page = max(1,min(100000,(int)($_GET['page']??1)));
        $offset = ($page-1)*50;
        $args = [YakovlevImport::PROVIDER];
        $where = 'provider=?';
        if (!$history) {
            if ($tab === 'bank') $where .= " AND account_id IN ('bank_in','bank_out')";
            else { $where .= ' AND account_id=?'; $args[] = $tab; }
            if ($search !== '') {
                $where .= ' AND (number LIKE ? OR title LIKE ?)';
                array_push($args,'%'.$search.'%','%'.$search.'%');
            }
        }
        $table = $history?'integration_runs':'external_documents';
        $query = $db->prepare("SELECT COUNT(*) FROM $table WHERE $where");
        $query->execute($args);
        $count = (int)$query->fetchColumn();
        $order = $history?'created_at DESC,id':($parties?'title,id':'document_date DESC,number DESC,id');
        $columns = $history?'id,operation,status,result_code,created_at,finished_at,coverage_json':'id,number,title,document_date,amount';
        $query = $db->prepare("SELECT $columns FROM $table WHERE $where ORDER BY $order LIMIT 50 OFFSET $offset");
        $query->execute($args);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        $labels = $history?['Запуск','Завершение','Раздел','Период','Результат','Получено из 1С','Изменения']:($parties?['Контрагент','ИНН']:['Дата','Номер','Документ','Сумма']);
        $next = $offset+50<$count?'?'.http_build_query(['tab'=>$tab,'page'=>$page+1,'q'=>$search]):'';
        $html = '<section role="tabpanel" class="table-card registry-ui-card"><div class="table-toolbar registry-ui-toolbar">'
            .'<div class="found-label">Записей: <strong>'.$count.'</strong> · показано: <strong id="visible-count">'.count($rows).'</strong></div>';
        if (!$history) $html .= '<form method="get" class="toolbar-right"><input type="hidden" name="tab" value="'.$tab.'"><div class="registry-ui-search-group"><input type="search" class="toolbar-search registry-ui-search" name="q" value="'.$e($search).'" placeholder="Поиск" aria-label="Поиск по сохранённым данным"></div></form>';
        $html .= '</div><div class="table-scroll" role="region" tabindex="0" aria-label="Сохранённые данные"><table class="table registry-ui-table registry-ui-data"><thead><tr>';
        foreach ($labels as $label) $html .= '<th scope="col">'.$e($label).'</th>';
        $html .= '</tr></thead><tbody id="records-body" data-onec-next="'.$e($next).'">';
        foreach ($rows as $row) {
            if ($history) {
                $values = self::journalValues($row);
            } else {
                $values = $parties?[$row['title'],$row['number']]:[date('d.m.Y',strtotime($row['document_date'])),$row['number'],$row['title'],RegistryView::money($row['amount'])];
            }
            $url = '?tab='.$tab.'&document='.$row['id'];
            $attrs = $history?'':' tabindex="0" data-connection-document="'.$e($url).'" aria-label="'.$e('Открыть '.($parties?$row['title']:$row['number'])).'"';
            $html .= '<tr data-onec-row-key="'.$e($row['id']).'"'.$attrs.'>';
            foreach ($values as $index=>$value) {
                $html .= '<td><div class="registry-ui-value"><span class="'.($labels[$index]==='Сумма'?'registry-ui-money':'registry-ui-primary').'">'.$e($value).'</span></div></td>';
            }
            $html .= '</tr>';
        }
        if (!$rows) $html .= '<tr><td colspan="'.count($labels).'">Данных пока нет.</td></tr>';
        return $html.'</tbody></table></div></section><div id="onec-load-feedback" aria-live="polite"></div>';
    }

    private static function journalValues(array $row): array
    {
        $coverage = json_decode($row['coverage_json']??'{}',true);
        $scope = $coverage['scope']??[];
        $section = substr($scope['kind']??'',9);
        $start = $row['operation']==='yakovlev-load' ? date('d.m.Y H:i:s',strtotime($row['created_at'])) : 'Не записан';
        $finish = $row['finished_at'] ? date('d.m.Y H:i:s',strtotime($row['finished_at'])) : '—';
        $period = match(true) {
            isset($scope['from'],$scope['to']) => date('d.m.Y',strtotime($scope['from'])).' — '.date('d.m.Y',strtotime($scope['to'])),
            $section==='parties' => 'Весь справочник',
            default => '—',
        };
        $status = match($row['status']) {
            'success' => 'Сохранено',
            'pending' => time()-strtotime($row['created_at'])<300?'Выполняется':'Завершение не подтверждено',
            'failed' => match($row['result_code']) {
                'IMPORT_BUSY' => 'Пропущено: уже идёт загрузка',
                'AUTH_REQUIRED' => 'Ошибка доступа к 1С',
                'YAKOVLEV_READ_NOT_ENABLED' => 'Чтение не настроено',
                'NETWORK_OR_TLS_FAILED','TIME_LIMIT' => 'Ошибка связи с 1С',
                default => 'Ошибка загрузки',
            },
            default => 'Завершение не подтверждено',
        };
        $byKind = $coverage['evidence']['received_by_kind']??[];
        $downloaded = [];
        foreach (['parties'=>'Контрагентов','bank_in'=>'Поступлений','bank_out'=>'Списаний','invoices'=>'Счетов'] as $kind=>$label) {
            if (isset($byKind[$kind])) $downloaded[] = $label.' '.$byKind[$kind];
        }
        if (!$downloaded && $row['status']==='success') $downloaded[] = (string)($coverage['records_imported']??0);
        $stats = $coverage['changes']??null;
        $summary = $stats===null?'—':'Новых '.$stats['new'].' · Изменено '.$stats['changed'].' · Без изменений '.$stats['unchanged'];
        return [$start,$finish,YakovlevImport::TABS[$section]??'—',$period,$status,implode(' · ',$downloaded)?:'—',$summary];
    }
}
