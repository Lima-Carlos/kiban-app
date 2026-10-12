<?php

namespace App\Modules\Fleet\Services;

use App\Modules\Fleet\Models\FleetSetting;
use Illuminate\Support\Number;

/**
 * Numbers shown with the company units and the person's language: "12.345 km", "45,5 l", "R$ 250,00".
 */
class FleetFormat
{
    public static function number(int|float $value, int $decimals = 0): string
    {
        return (string) Number::format($value, precision: $decimals, locale: str_replace('_', '-', app()->getLocale()));
    }

    public static function distance(?int $value, ?FleetSetting $settings = null): string
    {
        if ($value === null) {
            return '—';
        }

        return static::number($value).' '.($settings ?? FleetSetting::current())->distance_unit;
    }

    public static function volume(?float $value, ?FleetSetting $settings = null): string
    {
        if ($value === null) {
            return '—';
        }

        return static::number($value, 2).' '.($settings ?? FleetSetting::current())->volume_unit;
    }

    /**
     * @param  int|null  $cents  amount in cents
     */
    public static function money(?int $cents, ?FleetSetting $settings = null): string
    {
        if ($cents === null) {
            return '—';
        }

        return (string) Number::currency($cents / 100, ($settings ?? FleetSetting::current())->currency, str_replace('_', '-', app()->getLocale()));
    }

    /** "12,4 km/l". */
    public static function consumption(?float $value, ?FleetSetting $settings = null): string
    {
        if ($value === null) {
            return '—';
        }

        $settings ??= FleetSetting::current();

        return static::number($value, 2).' '.$settings->distance_unit.'/'.$settings->volume_unit;
    }
}
