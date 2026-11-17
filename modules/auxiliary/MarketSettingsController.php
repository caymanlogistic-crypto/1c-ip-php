<?php
declare(strict_types=1);
namespace Modules\Auxiliary;

use App\{Auth,Http,PagePolicy,View};
use RuntimeException;

/** PHP form adapter for source S admin.js renderSettings, not its old runtime. */
final class MarketSettingsController
{
    private static function field(string $label, string $name, mixed $value, string $type = 'text', bool $readonly = false, bool $disabled = false): string
    {
        $html = '<label class="field"><span class="field-label">'.View::e($label).'</span>';
        if ($type === 'textarea') return $html.'<textarea class="field-textarea" rows="3" name="'.View::e($name).'"'.($disabled ? ' disabled' : '').'>'.View::e($value).'</textarea></label>';
        return $html.'<input class="field-input" type="'.View::e($type).'" name="'.View::e($name).'" value="'.View::e($value).'"'
            .($type === 'number' ? ' min="0" step="any"' : '').($readonly ? ' readonly' : '').'></label>';
    }

    private static function checkbox(string $label, string $name, bool $checked): string
    {
        return '<label class="checkbox-inline"><input type="hidden" name="'.View::e($name).'" value="0"><input class="ui-checkbox" type="checkbox" name="'.View::e($name).'" value="1"'.($checked ? ' checked' : '').'> '.View::e($label).'</label>';
    }

    private static function section(string $title, string $body): string
    {
        return '<section class="table-card registry-ui-card"><div class="panel-section demo-stack"><h2 class="section-title">'.View::e($title).'</h2>'.$body.'</div></section>';
    }

    public static function neighbor(int|string $index, array $value): string
    {
        $prefix = 'settings[geography][nearby]['.$index.']';
        return '<div class="form-grid-3" data-market-neighbor>'.self::field('Адрес', $prefix.'[address]', $value['address'])
            .self::checkbox('Корпуса и строения', $prefix.'[include_subunits]', $value['include_subunits'])
            .'<button class="btn btn-secondary" type="button" data-market-remove-neighbor>Убрать адрес</button></div>';
    }

    private static function integer(mixed $value): int
    {
        if (!is_string($value) || !preg_match('/^(?:0|[1-9][0-9]{0,7})$/D', $value)) throw new RuntimeException('Неверное целое значение настройки.', 422);
        return (int)$value;
    }

    private static function boolean(mixed $value): bool
    {
        if (!in_array($value, ['0','1'], true)) throw new RuntimeException('Неверное значение переключателя.', 422);
        return $value === '1';
    }

    public static function input(array $post): array
    {
        $value = $post['settings'] ?? null;
        if (!is_array($value)) throw new RuntimeException('Неверная структура настроек.', 422);
        foreach (['geography','filters','sources','limits','browser'] as $group) {
            if (!is_array($value[$group] ?? null)) throw new RuntimeException('Неверная группа настроек.', 422);
        }
        foreach (['office','parking'] as $kind) if (!is_array($value['filters'][$kind] ?? null)) throw new RuntimeException('Неверная структура фильтров.', 422);
        foreach (Market::SOURCES as $source) if (!is_array($value['sources'][$source] ?? null)) throw new RuntimeException('Неверная структура источников.', 422);
        $value['version'] = self::integer($value['version'] ?? null);
        $value['types'] ??= [];
        $value['geography']['nearby'] ??= [];
        if (!is_array($value['geography']['nearby']) || count($value['geography']['nearby']) > 20) throw new RuntimeException('Слишком много соседних адресов.', 422);
        $value['geography']['nearby'] = array_values($value['geography']['nearby']);
        foreach ($value['geography']['nearby'] as &$neighbor) {
            if (!is_array($neighbor)) throw new RuntimeException('Неверная структура соседнего адреса.', 422);
            $neighbor['include_subunits'] = self::boolean($neighbor['include_subunits'] ?? null);
        }
        unset($neighbor);
        foreach (['office','parking'] as $kind) foreach (['area_min','area_max','price_min','price_max'] as $field) {
            $raw = $value['filters'][$kind][$field] ?? null;
            $value['filters'][$kind][$field] = $raw === '' ? null : $raw;
        }
        foreach (Market::SOURCES as $source) {
            $value['sources'][$source]['enabled'] = self::boolean($value['sources'][$source]['enabled'] ?? null);
            foreach (['office','parking'] as $kind) {
                $raw = $value['sources'][$source][$kind.'_urls'] ?? '';
                if (!is_string($raw) || strlen($raw) > 25000) throw new RuntimeException('Неверный список ссылок.', 422);
                $value['sources'][$source][$kind.'_urls'] = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $raw)), static fn($line) => $line !== ''));
            }
        }
        foreach (['max_pages_per_seed','max_listings_per_scope'] as $field) $value['limits'][$field] = self::integer($value['limits'][$field] ?? null);
        foreach (['timeout_ms','pause_ms'] as $field) $value['browser'][$field] = self::integer($value['browser'][$field] ?? null);
        $value['browser']['headless'] = self::boolean($value['browser']['headless'] ?? null);
        return $value;
    }

    public static function index(): string
    {
        Auth::requirePermission('rental.read');
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            MarketSettingsStore::requireEdit();
            if ((int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 100000) throw new RuntimeException('Настройки превышают допустимый размер.', 413);
        }
        $heading = View::heading('Настройки рынка', 'Сохранение само по себе не запускает сбор.');
        $user = Auth::user();
        $tabs = '<div class="registry-ui-tabs">';
        foreach (['dashboard' => 'Объявления','runs' => 'Запуски','comparison' => 'Сравнение','history' => 'Динамика','settings' => 'Настройки'] as $key => $label) {
            if (empty($user['grants_configured']) || PagePolicy::level('auxiliary.market-'.$key, $user) >= 1) $tabs .= '<a class="btn btn-toolbar" href="/auxiliary/market/'.$key.'">'.View::e($label).'</a>';
        }
        $heading .= $tabs.'</div>';
        try { $store = MarketSettingsStore::configured(); $current = $store->load(); }
        catch (RuntimeException $error) {
            if ($error->getCode() !== 503) throw $error;
            http_response_code(503);
            return View::page('Настройки рынка', $heading.'<p>'.View::e($error->getMessage()).'</p>');
        }
        $error = '';
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            Http::checkCsrf();
            try {
                if (!is_string($_POST['expected_etag'] ?? null)) throw new RuntimeException('Неверная версия настроек.', 422);
                $saved = $store->save(self::input($_POST), $_POST['expected_etag']);
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'Market-Settings') Http::json(['saved' => true, 'etag' => $saved['etag']]);
                $_SESSION['market_settings_saved'] = true;
                Http::redirect('/auxiliary/market/settings');
            } catch (RuntimeException $failure) {
                if ($failure->getCode() === 403) throw $failure;
                if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'Market-Settings') Http::json(['error' => $failure->getMessage()], $failure->getCode() === 409 ? 409 : 422);
                http_response_code($failure->getCode() === 409 ? 409 : 422);
                $error = $failure->getMessage();
            }
        }
        $settings = $current['settings'];
        $editable = MarketSettingsStore::editable();
        $status = $error ?: (!empty($_SESSION['market_settings_saved']) ? 'Настройки сохранены' : ($editable ? 'Сохранённые настройки' : 'Только просмотр настроек'));
        unset($_SESSION['market_settings_saved']);
        $save = '<div class="demo-row"><button class="btn btn-primary" type="submit">Сохранить настройки</button><button class="btn btn-secondary" type="button" data-market-reload>Перечитать сохранённые</button><span class="registry-ui-secondary" role="status">'.View::e($status).'</span></div>';
        $html = '<form id="settings-form" class="demo-stack" method="post" action="/auxiliary/market/settings" data-market-settings><input type="hidden" name="_csrf" value="'.View::e(Http::csrf()).'"><input type="hidden" name="expected_etag" value="'.View::e($current['etag']).'"><input type="hidden" name="settings[version]" value="1">'.$save;
        $body = '<div class="demo-row">';
        foreach (['cian' => 'Циан','yandex' => 'Яндекс','morrow' => 'Morrow','fortex' => 'Fortex'] as $source => $label) $body .= self::checkbox($label, 'settings[sources]['.$source.'][enabled]', $settings['sources'][$source]['enabled']);
        foreach (['office' => 'Офисы','parking' => 'Машино-места'] as $kind => $label) $body .= '<label class="checkbox-inline"><input class="ui-checkbox" type="checkbox" name="settings[types][]" value="'.$kind.'"'.(in_array($kind, $settings['types'], true) ? ' checked' : '').'> '.$label.'</label>';
        $html .= self::section('Источники и типы', $body.'</div>');
        $body = self::field('Основной адрес', 'settings[geography][base_address]', $settings['geography']['base_address']).'<div class="demo-stack" data-market-neighbors>';
        foreach ($settings['geography']['nearby'] as $index => $neighbor) $body .= self::neighbor($index, $neighbor);
        $body .= '</div><div class="demo-row"><button class="btn btn-secondary" type="button" data-market-add-neighbor>Добавить адрес</button></div><p class="registry-ui-secondary">«Рядом» — только перечисленные дома. Флажок включает корпуса и строения; радиус не рассчитывается.</p>';
        $html .= self::section('Адреса', $body);
        foreach (['office' => 'Офисы · диапазоны','parking' => 'Машино-места · диапазоны'] as $kind => $label) {
            $body = '<div class="form-grid-2">';
            foreach (['area_min' => 'Площадь от, м²','area_max' => 'Площадь до, м²','price_min' => 'Аренда от, ₽/месяц','price_max' => 'Аренда до, ₽/месяц'] as $field => $name) $body .= self::field($name, 'settings[filters]['.$kind.']['.$field.']', $settings['filters'][$kind][$field], 'number');
            $html .= self::section($label, $body.'</div><p class="registry-ui-secondary">Пустое поле — без ограничения. Неизвестная цена не проходит активный ценовой фильтр.</p>');
        }
        $body = '<div class="form-grid-2">'.self::field('Страниц на исходную ссылку', 'settings[limits][max_pages_per_seed]', $settings['limits']['max_pages_per_seed'], 'number')
            .self::field('Записей на источник / тип / область', 'settings[limits][max_listings_per_scope]', $settings['limits']['max_listings_per_scope'], 'number')
            .self::field('Таймаут, мс', 'settings[browser][timeout_ms]', $settings['browser']['timeout_ms'], 'number')
            .self::field('Пауза, мс', 'settings[browser][pause_ms]', $settings['browser']['pause_ms'], 'number')
            .'<label class="field"><span class="field-label">Браузер</span><input type="hidden" name="settings[browser][channel]" value="'.View::e($settings['browser']['channel']).'"><select class="field-select" disabled>';
        foreach (['msedge' => 'Microsoft Edge','chrome' => 'Google Chrome','chromium' => 'Chromium'] as $key => $label) $body .= '<option value="'.$key.'"'.($settings['browser']['channel'] === $key ? ' selected' : '').'>'.$label.'</option>';
        $body .= '</select></label>'.self::field('Папка результатов', 'settings[output_root]', $settings['output_root'], 'text', true).'</div>'
            .self::checkbox('Работать без окна (headless)', 'settings[browser][headless]', $settings['browser']['headless'])
            .'<p class="registry-ui-secondary">Для Циана проверен обычный Edge с окном. У каждого источника свой временный профиль.</p><details><summary>Ссылки источников и robots</summary><div class="demo-stack"><label class="field"><span class="field-label">Недоступный robots.txt</span><select class="field-select" name="settings[browser][robots_unavailable_policy]">';
        foreach (['rfc9309' => 'RFC 9309 · ограниченная проверка при 4xx','strict_stop' => 'Строго остановиться при недоступности'] as $key => $label) $body .= '<option value="'.$key.'"'.($settings['browser']['robots_unavailable_policy'] === $key ? ' selected' : '').'>'.View::e($label).'</option>';
        $body .= '</select></label>';
        foreach (Market::SOURCES as $source) foreach (['office' => 'ссылки офисов','parking' => 'ссылки машино-мест'] as $kind => $label) $body .= self::field($source.' · '.$label.' (по одной в строке)', 'settings[sources]['.$source.']['.$kind.'_urls]', implode("\n", $settings['sources'][$source][$kind.'_urls']), 'textarea', false, $kind === 'parking' && in_array($source, ['morrow','fortex'], true));
        $html .= self::section('Запуск и сохранение', $body.'</div></details>')
            .'<div class="demo-row"><button class="btn btn-primary" type="submit">Сохранить настройки</button><span class="registry-ui-secondary">Сохранение само по себе не запускает сбор.</span></div></form>';
        if (!$editable) $html = preg_replace('/<(input|select|textarea|button)\b/', '<$1 disabled', $html);
        if ($editable) $html .= '<template data-market-neighbor-template>'.self::neighbor('__INDEX__', ['address' => '', 'include_subunits' => false]).'</template>'
            .'<dialog class="modal registry-ui-modal ui-detail-card" id="neighbor-dialog" aria-labelledby="neighbor-title"><div class="modal-head"><h2 class="modal-title" id="neighbor-title">Добавить соседний адрес</h2><button class="modal-close" id="close-neighbor" type="button" aria-label="Закрыть">×</button></div><div class="modal-body registry-ui-modal-grid"><label class="field"><span class="field-label">Адрес на Мосфильмовской</span><input class="field-input" required id="new-neighbor" placeholder="Москва, Мосфильмовская улица, 88"></label><label class="checkbox-inline"><input class="ui-checkbox" type="checkbox" id="new-neighbor-subunits"> Включать корпуса и строения</label></div><div class="modal-foot"><button class="btn btn-secondary" type="button" id="cancel-neighbor">Отмена</button><button class="btn btn-primary" type="button" id="add-neighbor">Добавить</button></div></dialog>';
        return View::page('Настройки рынка', $heading.$html, '', ['market-settings-ui.js']);
    }
}
