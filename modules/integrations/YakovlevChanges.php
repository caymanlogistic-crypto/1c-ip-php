<?php
declare(strict_types=1);

namespace Modules\Integrations;

final class YakovlevChanges
{
    public static function document(array $raw): array
    {
        $record = $raw['fields']['record'] ?? [];
        unset($record['DataVersion']);
        return $record;
    }

    public static function fields(array $before,array $after): array
    {
        $old = self::document($before);
        $new = self::document($after);
        $changes = [];
        $labels = json_decode(file_get_contents(__DIR__.'/defaults/field-labels.json'),true);
        foreach (array_unique([...array_keys($old),...array_keys($new)]) as $key) {
            if (array_key_exists($key,$old) === array_key_exists($key,$new)
                && Support::canonical($old[$key]??null) === Support::canonical($new[$key]??null)) continue;
            $changes[] = [$labels[$key]??$key,self::display($old,$key),self::display($new,$key)];
        }
        return $changes;
    }

    private static function display(array $row,string $key): string
    {
        if (!array_key_exists($key,$row)) return 'Нет поля';
        $value = $row[$key];
        return match(true) {
            $value===null => 'Не указано',
            is_bool($value) => $value?'Да':'Нет',
            is_array($value) => mb_strimwidth(Support::json($value),0,350,'…'),
            default => mb_strimwidth((string)$value,0,350,'…'),
        };
    }

    public static function render(\PDO $db,PrivateStore $store,array $document): string
    {
        if (!in_array($document['account_id'],['parties','invoices'],true)) return '';
        $query = $db->prepare('SELECT version,created_at,raw_path,raw_sha256 FROM external_document_versions WHERE document_id=? ORDER BY version DESC LIMIT 21');
        $query->execute([$document['id']]);
        $versions = $query->fetchAll(\PDO::FETCH_ASSOC);
        $html = '<section class="panel-section"><h3 class="section-title">Изменения из 1С</h3>';
        if (count($versions)<2) return $html.'<p class="registry-ui-secondary">Изменений пока нет.</p></section>';
        $e = Support::e(...);
        $next = null;
        foreach ($versions as $index=>$version) {
            $previous = Support::decode($store->read($version['raw_path'],$version['raw_sha256']));
            if ($index>0) {
                $changes = self::fields($previous,$next);
                $current = $versions[$index-1];
                $html .= '<details class="field"><summary class="field-label">'.$e(date('d.m.Y H:i:s',strtotime($current['created_at'])))
                    .' · Версия '.$e($current['version']).'</summary>';
                if (!$changes) $html .= '<p class="registry-ui-secondary">Изменились только служебные поля.</p>';
                foreach ($changes as [$label,$from,$to]) {
                    $html .= '<div class="field"><span class="field-label">'.$e($label).'</span>'
                        .'<span class="registry-ui-modal-text">Было: '.$e($from).'</span>'
                        .'<span class="registry-ui-modal-text">Стало: '.$e($to).'</span></div>';
                }
                $html .= '</details>';
            }
            $next = $previous;
        }
        if (count($versions)===21 && (int)end($versions)['version']>1) {
            $html .= '<p class="registry-ui-secondary">Показаны 20 последних изменений.</p>';
        }
        return $html.'</section>';
    }
}
