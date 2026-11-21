<?php
declare(strict_types=1);

namespace Modules\Rental;

use App\View;

final class RegistryDisclosure
{
    public static function cell(?array $detail): string
    {
        $button = $detail ? self::button($detail['id'], $detail['label']) : '';
        return '<td class="registry-ui-disclosure-cell">'.$button.'</td>';
    }

    public static function button(string $id, string $label, string $attributes = ''): string
    {
        return '<button class="btn btn-secondary registry-ui-disclosure" type="button" data-registry-disclosure '.$attributes
            .' aria-controls="'.View::e($id).'" aria-expanded="false" aria-label="'.View::e($label).'">'
            .'<svg aria-hidden="true" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            .' stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"/></svg></button>';
    }

    public static function row(array $detail, int $columns): string
    {
        return '<tr id="'.View::e($detail['id']).'" hidden><td colspan="'.$columns.'" class="registry-ui-disclosure-content">'
            .'<div class="registry-ui-nested-table-scroll">'.$detail['body'].'</div></td></tr>';
    }
}
