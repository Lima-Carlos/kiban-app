<?php

namespace App\Modules\Field\Services;

use App\Modules\Field\Models\FieldSetting;
use Illuminate\Support\Number;

/**
 * How Kiban Campo shows money and times: in the company's currency and the person's language.
 */
class FieldFormat
{
    public static function money(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }

        return (string) Number::currency($cents / 100, FieldSetting::current()->currency, str_replace('_', '-', app()->getLocale()));
    }

    /** "35 m", "2,5 kg", "3" (units are not written). */
    public static function quantity(float|string $quantity, string $unit): string
    {
        $number = rtrim(rtrim(number_format((float) $quantity, 2, __('field.decimal_separator'), ''), '0'), __('field.decimal_separator'));

        return $unit === 'un' ? $number : $number.' '.__('field.units_short.'.$unit);
    }

    /** "1 h 30 min", "45 min". */
    public static function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours > 0 ? $hours.' h ' : '').($rest > 0 || $hours === 0 ? $rest.' min' : ''));
    }
}
