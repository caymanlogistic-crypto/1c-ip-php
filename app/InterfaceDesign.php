<?php
declare(strict_types=1);
namespace App;

use RuntimeException;

final class InterfaceDesign
{
    public const URL = '/administration/interface-designer';
    public const SCREENS = [
        'rental.objects' => ['Объекты', '/rental/objects'],
        'rental.invoice-list' => ['Счета и оплаты', '/rental/invoice-list'],
        'rental.invoices' => ['Счета контрагентов', '/rental/invoices'],
        'rental.new-contract' => ['Новый договор', '/rental/new-contract'],
    ];
    private const INVOICE_COLUMNS = [
        'invoice'=>'Счёт', 'party'=>'Контрагент', 'contract'=>'Договор', 'kind'=>'Вид',
        'due'=>'Оплатить до', 'amount'=>'Сумма', 'paid'=>'Оплачено', 'debt'=>'Остаток', 'state'=>'Состояние',
    ];
    public const SECTIONS = ['party'=>'Контрагент','dates'=>'Номер и даты','objects'=>'Объекты и НДС','parts'=>'Части помещений'];
    public const FIELDS = ['number'=>'Номер договора','document_date'=>'Дата договора','start_date'=>'Начало аренды','end_date'=>'Окончание аренды'];
    public const ACTIONS = ['close'=>'Закрыть','draft'=>'Сохранить в черновиках','submit'=>'Отправить на согласование'];

    public static function columns(string $id): array
    {
        return match ($id) {
            'rental.objects' => [
                'name'=>'Объект аренды','type'=>'Тип','floor'=>'Этаж','area'=>'Площадь, м²',
                'occupancy'=>'Занятость','until'=>'Договор до','comment'=>'Комментарий',
            ],
            'rental.invoice-list', 'rental.invoices' => self::INVOICE_COLUMNS,
            default => throw new RuntimeException('Неизвестный экран.',404),
        };
    }

    public static function defaults(string $id): array
    {
        if ($id !== 'rental.new-contract') {
            $columns = [];
            foreach (self::columns($id) as $key=>$label) $columns[]=['key'=>$key,'label'=>$label,'width'=>0];
            return ['columns'=>$columns];
        }
        return [
            'window'=>['width'=>0,'height'=>0,'x'=>0,'y'=>0],
            'sections'=>self::items(self::SECTIONS,12),
            'fields'=>self::items(self::FIELDS,6),
            'actions'=>self::items(self::ACTIONS,0),
        ];
    }

    private static function items(array $labels, int $width): array
    {
        return array_map(static fn($key)=>['key'=>$key,'width'=>$width],array_keys($labels));
    }

    public static function exportLayout(string $id, array $state): array
    {
        return ['schema'=>1,'screen'=>$id,'layout'=>self::normalize($id,$state['applied'] ?? self::defaults($id))];
    }

    public static function importLayout(string $id, mixed $document): array
    {
        if (!is_array($document) || count($document)!==3 || ($document['schema']??null)!==1) {
            throw new RuntimeException('Версия файла компоновки не поддерживается.',422);
        }
        if (($document['screen']??null)!==$id) throw new RuntimeException('Файл предназначен для другого экрана.',422);
        return self::normalize($id,$document['layout']??null);
    }

    public static function normalize(string $id, mixed $input): array
    {
        $result = self::defaults($id);
        foreach ($result as $group=>$defaults) {
            $value = $input[$group] ?? null;
            if (!is_array($value)) self::invalid();
            if ($group === 'window') {
                $result[$group] = [
                    'width'=>self::size($value['width']??null,320,1600),
                    'height'=>self::size($value['height']??null,280,1200),
                    'x'=>self::size($value['x']??null,-1600,1600),
                    'y'=>self::size($value['y']??null,-1200,1200),
                ];
                continue;
            }
            $keys = array_column($defaults,'key');
            if (!array_is_list($value) || count($value) !== count($keys)) self::invalid();
            $seen = [];
            $result[$group] = [];
            foreach ($value as $item) {
                $key = $item['key'] ?? null;
                if (!is_string($key) || !in_array($key,$keys,true) || isset($seen[$key])) self::invalid();
                $seen[$key] = true;
                $width = match ($group) {
                    'columns' => self::size($item['width']??null,48,1000),
                    'actions' => self::size($item['width']??null,64,480),
                    default => self::size($item['width']??null,1,12,false),
                };
                $entry = ['key'=>$key,'width'=>$width];
                if ($group === 'columns') {
                    $label = $item['label'] ?? null;
                    if (!is_string($label) || trim($label)==='' || mb_strlen($label)>80 || preg_match('/[\p{Cc}]/u',$label)) self::invalid();
                    $entry['label'] = trim($label);
                }
                $result[$group][] = $entry;
            }
        }
        return $result;
    }

    private static function size(mixed $value, int $min, int $max, bool $auto=true): int
    {
        if (!is_int($value) || ((!$auto || $value!==0) && ($value<$min || $value>$max))) self::invalid();
        return $value;
    }

    private static function invalid(): never
    {
        throw new RuntimeException('Проверьте состав и размеры элементов компоновки.',422);
    }

    public static function canEdit(): bool
    {
        return (Auth::user()['role_id'] ?? null) === 'admin';
    }

    public static function attributes(string $id, ?array $state=null): string
    {
        $state ??= InterfaceDesignStore::read($id);
        $admin = self::canEdit();
        $data = ['id'=>$id,'applied'=>$state['applied'] ?? self::defaults($id)];
        if ($admin) {
            $data += ['revision'=>$state['revision'],'draft'=>$state['draft'],'previous'=>$state['previous']!==null,'csrf'=>Http::csrf()];
            if ($id === 'rental.new-contract') $data['labels']=['sections'=>self::SECTIONS,'fields'=>self::FIELDS,'actions'=>self::ACTIONS];
        }
        return ' data-interface-design="'.View::e(json_encode($data,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)).'"';
    }
}
