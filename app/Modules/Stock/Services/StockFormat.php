<?php

namespace App\Modules\Stock\Services;

/** Quantities as people read them: "12 un", "3,5 m", "1.250 m" (no trailing zeros). */
class StockFormat
{
    public static function quantity(float $quantity, string $unit): string
    {
        $english = app()->getLocale() === 'en';
        $text = number_format(round($quantity, 3), 3, $english ? '.' : ',', $english ? ',' : '.');
        $text = rtrim(rtrim($text, '0'), $english ? '.' : ',');

        return $text.' '.$unit;
    }
}
