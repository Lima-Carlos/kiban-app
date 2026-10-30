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

    /** "1 h 30 min", "45 min". */
    public static function duration(int $minutes): string
    {
        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;

        return trim(($hours > 0 ? $hours.' h ' : '').($rest > 0 || $hours === 0 ? $rest.' min' : ''));
    }
}
