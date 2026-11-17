<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\{Auth,DB,Http,PagePolicy,View};
use Modules\Rental\Importer;
use PDO;
use RuntimeException;

/** Native adapter for S premises/list.html, editor.html and card.html. */
final class LegacyPremisesController
{
    private const URL = '/auxiliary/premises';
    private const KINDS = ['room' => 'Помещение', 'part' => 'Часть помещения', 'parking' => 'Машино-место'];
    private const LABELS = [
        'address' => 'Точный адрес', 'designation' => 'Номер или обозначение помещения',
        'floor' => 'Этаж', 'kind' => 'Вид', 'area' => 'Площадь, м²',
        'cadastral_number' => 'Кадастровый номер / связь с объектом', 'account' => 'Лицевой счёт',
        'parent_description' => 'Здание или более крупное помещение', 'parent_id' => 'Родительская карточка',
        'note' => 'Примечание',
    ];

    private static function e(mixed $value): string { return View::e($value); }

    private static function read(): void
    {
        Auth::requirePermission('rental.read');
        $user = Auth::user();
        if (!empty($user['grants_configured']) && PagePolicy::level('auxiliary.premises', $user) < 1) throw new RuntimeException('Недостаточно прав для просмотра помещений.', 403);
    }

    private static function rows(string $sql, array $arguments = []): array
    {
        $query = DB::pdo()->prepare($sql);
        $query->execute($arguments);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    }

    private static function catalogue(string $query): array
    {
        $rows = self::rows('SELECT * FROM aux_premises ORDER BY address,floor,designation,id');
        if ($query === '') return $rows;
        $needle = str_replace(',', '.', mb_strtolower($query));
        return array_values(array_filter($rows, function(array $row) use($needle): bool {
            $row['kind'] = self::KINDS[$row['kind']] ?? $row['kind'];
            $values = [];
            foreach (['id','address','designation','floor','kind','area','cadastral_number','account','parent_description','note','area_variants','account_variants'] as $field) $values[] = (string)($row[$field] ?? '');
            return str_contains(str_replace(',', '.', mb_strtolower(implode(' ', $values))), $needle);
        }));
    }

    private static function field(string $name, mixed $value, bool $editable, array $parents): string
    {
        if (!is_scalar($value) && $value !== null) $value = '';
        $id = 'premise-'.$name;
        $attributes = ' id="'.$id.'" name="'.$name.'"'.($editable ? '' : ' disabled');
        if (in_array($name, ['address','designation','kind'], true)) $attributes .= ' required';
        if ($name === 'kind' || $name === 'parent_id') {
            $control = '<select class="field-select"'.$attributes.'>';
            if ($name === 'kind') $options = self::KINDS;
            else {
                $options = ['' => '---------'];
                foreach ($parents as $parent) $options[$parent['id']] = $parent['designation'].', этаж '.$parent['floor'].' — '.$parent['address'];
            }
            foreach ($options as $key => $label) $control .= '<option value="'.self::e($key).'"'.((string)$key === (string)$value ? ' selected' : '').'>'.self::e($label).'</option>';
            $control .= '</select>';
        } elseif ($name === 'note') {
            $control = '<textarea class="field-textarea" rows="3"'.$attributes.'>'.self::e($value).'</textarea>';
        } else {
            $attributes .= match ($name) {
                'address','parent_description' => ' maxlength="500"', 'designation' => ' maxlength="200"',
                'floor' => ' maxlength="50"', 'cadastral_number','account' => ' maxlength="100"',
                'area' => ' min="0.001" step="0.001"', default => '',
            };
            $control = '<input class="field-input" type="'.($name === 'area' ? 'number' : 'text').'" value="'.self::e($value).'"'.$attributes.'>';
        }
        return '<div class="field"><label class="field-label" for="'.$id.'">'.self::e(self::LABELS[$name]).'</label>'.$control.'</div>';
    }

    private static function sources(int $id): string
    {
        $rows = self::rows('SELECT s.*,b.filename,b.sha256,r.sheet FROM aux_source_rows s JOIN aux_premise_source_links l ON l.source_row_id=s.id JOIN aux_import_batches b ON b.id=s.batch_id LEFT JOIN aux_import_reports r ON r.batch_id=b.id WHERE l.premise_id=? ORDER BY s.source_row_number,s.id', [$id]);
        if (!$rows) return '';
        $html = '<details><summary class="registry-ui-primary">Связь с источником ('.count($rows).')</summary><div class="table-scroll registry-ui-compact-table-scroll"><table class="table registry-ui-table registry-ui-data registry-ui-compact-table"><thead><tr><th>Строка</th><th>Обозначение / этаж</th><th>Площадь строки, м²</th><th>Кадастр / счёт</th></tr></thead><tbody>';
        foreach ($rows as $row) {
            $raw = Importer::decodeExact($row['raw_evidence']);
            $values = $raw['values'] ?? $raw['raw'] ?? [];
            if (!is_array($values)) $values = [];
            $html .= '<tr><td>'.(int)$row['source_row_number'].($row['hidden'] ? ' (скрытая)' : '').'<details><summary>Источник</summary><span class="registry-ui-modal-help">'.self::e($row['filename']).' · «'.self::e($row['sheet']).'» · SHA256: '.self::e($row['sha256']).'</span></details></td><td>'.self::e($values['designation'] ?? '').' / '.self::e($values['floor'] ?? '').'</td><td>'.self::e($values['area'] ?? '').'</td><td>'.self::e($values['cadastral'] ?? '').' / '.self::e($values['account'] ?? '').'</td></tr>';
        }
        return $html.'</tbody></table></div></details>';
    }

    private static function importSummary(): string
    {
        $batch = self::rows('SELECT b.filename,r.sheet,r.report_json FROM aux_import_batches b JOIN aux_import_reports r ON r.batch_id=b.id ORDER BY b.id DESC LIMIT 1')[0] ?? null;
        if ($batch === null) return '';
        $report = Importer::decodeExact($batch['report_json']);
        $summary = $report['summary'] ?? [];
        $html = '<section class="table-card registry-ui-card"><section class="panel-section"><h2 class="section-title">Проверка импорта</h2><p class="registry-ui-secondary">'.self::e($batch['filename']).' · лист «'.self::e($batch['sheet']).'». Обработано строк: '.self::e($summary['source_rows'] ?? '').', скрытых: '.self::e($summary['hidden_rows'] ?? '').'. Объединено повторов: '.self::e($summary['merged_repetitions'] ?? '').'. Не привязано строк: '.self::e($summary['unresolved_rows'] ?? '').'.</p><details><summary class="registry-ui-primary">Записи, требующие уточнения ('.self::e($summary['issue_groups'] ?? '').')</summary><div class="table-scroll"><table class="table registry-ui-table registry-ui-data"><thead><tr><th>Строки Excel</th><th>Причина</th><th>Исходные данные</th></tr></thead><tbody>';
        foreach ($report['issues'] ?? [] as $issue) {
            $raw = $issue['raw'] ?? null;
            $description = is_array($raw)
                ? 'Этаж: '.($raw['floor'] ?? '').'; обозначение: '.($raw['designation'] ?? '').'; площадь: '.($raw['area'] ?? '').'; кадастр: '.($raw['cadastral'] ?? '').'; счёт: '.($raw['account'] ?? '')
                : ($issue['designation'] ?? '');
            $html .= '<tr><td>'.self::e(implode(', ', $issue['rows'] ?? [])).'</td><td>'.self::e($issue['reason'] ?? '').'</td><td>'.self::e($description).'</td></tr>';
        }
        return $html.'</tbody></table></div></details></section></section>';
    }

    private static function editor(string $card): string
    {
        $editable = ConfirmedPremises::editable();
        if ($card === 'new') {
            if (!$editable) throw new RuntimeException('Недостаточно прав для создания помещения.', 403);
            $id = 0;
            $row = array_fill_keys(array_keys(self::LABELS), '');
            $row += ['id' => 0, 'revision' => 1, 'creation_key' => Premises::uuid(), 'area_variants' => '[]', 'account_variants' => '[]'];
            $row['kind'] = 'room';
        } else {
            if (!preg_match('/^[1-9][0-9]{0,17}$/D', $card)) throw new RuntimeException('Карточка не найдена.', 404);
            $id = (int)$card;
            $row = self::rows('SELECT * FROM aux_premises WHERE id=?', [$id])[0] ?? throw new RuntimeException('Карточка не найдена.', 404);
        }
        $input = $row;
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Http::checkCsrf();
            if (!$editable) throw new RuntimeException('Недостаточно прав для изменения помещения.', 403);
            $input = array_replace($row, $_POST, ['id' => $id]);
            try {
                Premises::save($input);
                Http::redirect(self::URL);
            } catch (RuntimeException $failure) {
                if (!in_array($failure->getCode(), [409,422], true)) throw $failure;
                $error = $failure->getMessage();
            }
        }
        $title = $id ? 'Помещение «'.$row['designation'].'»' : 'Новое помещение';
        $parents = self::rows('SELECT id,designation,floor,address FROM aux_premises WHERE id<>? ORDER BY address,floor,designation,id', [$id]);
        $body = '<div data-editor-fragment data-editor-title="'.self::e($title).'"><form method="post" id="premise-form" action="'.self::URL.'?legacy=1&amp;card='.self::e($card).'" class="registry-ui-modal-grid"><input type="hidden" name="_csrf" value="'.self::e(Http::csrf()).'"><input type="hidden" name="expected_revision" value="'.self::e($input['expected_revision'] ?? $row['revision']).'"><input type="hidden" name="creation_key" value="'.self::e($input['creation_key'] ?: Premises::uuid()).'">';
        if ($error !== '') $body .= '<span class="field-msg is-error" role="alert">'.self::e($error).'</span>';
        $body .= '<div class="registry-ui-modal-grid registry-ui-modal-grid--reading">';
        foreach (self::LABELS as $name => $label) $body .= self::field($name, $input[$name] ?? '', $editable, $parents);
        $body .= '</div></form>';
        $areas = Importer::decodeExact($row['area_variants'] ?? '[]');
        $accounts = Importer::decodeExact($row['account_variants'] ?? '[]');
        if ($areas || $accounts) $body .= '<details><summary class="registry-ui-primary">Значения в источнике</summary><span class="registry-ui-modal-help">Площади: '.self::e(implode(', ', $areas)).' м². Лицевые счета: '.self::e(implode(', ', $accounts)).'. Варианты сохранены для проверки, площадь кадастрового объекта не установлена.</span></details>';
        if ($id) $body .= self::sources($id);
        $body .= '<div class="modal-foot-actions" data-editor-actions><button class="btn btn-secondary" type="button" data-editor-close>Закрыть</button>'.($editable ? '<button class="btn btn-primary" type="submit" form="premise-form">Сохранить</button>' : '').'</div></div>';
        if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'ERP-Fetch') return $body;
        return View::page('Помещения', View::heading('Помещения', '', 'document', '<a class="btn btn-secondary" href="'.self::URL.'?legacy=1">К списку</a>').'<section class="table-card registry-ui-card demo-form-width"><section class="panel-section">'.$body.'</section></section>');
    }

    public static function index(): string
    {
        self::read();
        if (isset($_GET['card'])) return self::editor((string)$_GET['card']);
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') throw new RuntimeException('Карточка не выбрана.', 404);
        $query = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 200);
        $rows = self::catalogue($query);
        $count = count($rows);
        $pages = max(1, (int)ceil($count / 50));
        $page = max(1, min($pages, (int)($_GET['page'] ?? 1)));
        $rows = array_slice($rows, ($page - 1) * 50, 50);
        $actions = ConfirmedPremises::editable() ? '<a class="btn btn-primary" href="'.self::URL.'?legacy=1&amp;card=new" data-open-form>Создать помещение</a>' : '';
        $body = View::heading('Помещения', '', 'document', $actions).'<section class="table-card registry-ui-card registry-ui-sticky-table registry-ui-sticky-table--wide-scroll registry-ui-scroll" data-registry-id="premises" data-registry-view><div class="table-toolbar registry-ui-toolbar"><span class="found-label">Найдено: <b data-result-count>'.$count.'</b></span><form class="toolbar-right" method="get" data-search-form><input type="hidden" name="legacy" value="1"><input type="search" class="registry-ui-search" name="q" value="'.self::e($query).'" placeholder="Поиск по таблице" aria-label="Поиск по помещениям" data-live-search></form></div><div class="table-scroll" role="region" aria-label="Помещения: прокрутка таблицы" tabindex="0"><table class="table registry-ui-table registry-ui-data registry-ui-data--columns"><thead><tr>';
        foreach (['Обозначение','Этаж','Вид','Площадь, м²','Адрес','Кадастровая связь','Лицевой счёт','Принадлежность','Примечание'] as $label) $body .= '<th scope="col">'.self::e($label).'</th>';
        $body .= '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $body .= '<tr data-record-key="premises:'.(int)$row['id'].'" tabindex="0" data-record-url="'.self::URL.'?legacy=1&amp;card='.(int)$row['id'].'" aria-label="Открыть '.self::e($row['designation']).'"><td><span class="registry-ui-name">'.self::e($row['designation']).'</span></td>';
            foreach ([$row['floor'] ?: '—',self::KINDS[$row['kind']] ?? $row['kind'],$row['area'] === null ? 'Требует проверки' : ConfirmedController::area($row['area']),$row['address'],$row['cadastral_number'] ?: '—',$row['account'] ?: '—',$row['parent_description'] ?: '—',$row['note'] ?: '—'] as $value) $body .= '<td>'.self::e($value).'</td>';
            $body .= '</tr>';
        }
        if (!$rows) $body .= '<tr><td colspan="9"><div class="empty-state is-compact"><h2 class="empty-title">Помещения не найдены</h2><p class="empty-desc">Измените поисковый запрос или создайте помещение.</p></div></td></tr>';
        $body .= '</tbody></table></div><div data-pagination>';
        if ($pages > 1) {
            $body .= '<nav class="pagination" aria-label="Страницы помещений"><span class="pg-info">Страница '.$page.' из '.$pages.'</span><div class="inline-actions">';
            foreach (['Назад' => $page - 1, 'Далее' => $page + 1] as $label => $target) if ($target >= 1 && $target <= $pages) $body .= '<a class="btn btn-toolbar" href="'.self::URL.'?'.self::e(http_build_query(['legacy' => '1', 'q' => $query, 'page' => $target])).'">'.$label.'</a>';
            $body .= '</div></nav>';
        }
        return View::page('Помещения', $body.'</div></section>'.self::importSummary());
    }
}
