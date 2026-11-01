<?php

namespace App\Modules\Agenda\Services;

use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Field\Services\FieldFormat;
use Carbon\CarbonInterface;
use Illuminate\Support\Number;

/**
 * How the Agenda shows money, times and dates: the company's currency and time zone, the person's language.
 */
class AgendaFormat
{
    public static function money(?int $cents): string
    {
        if ($cents === null) {
            return '—';
        }

        return (string) Number::currency($cents / 100, AgendaSetting::current()->currency, str_replace('_', '-', app()->getLocale()));
    }

    /** "1 h 30 min" (the same as the Campo). */
    public static function duration(int $minutes): string
    {
        return FieldFormat::duration($minutes);
    }

    /** "14:30" in the company's time zone. */
    public static function time(?CarbonInterface $moment): string
    {
        return $moment ? $moment->setTimezone(AgendaSetting::timezone())->format('H:i') : '';
    }

    /** "terça, 03/11 às 14:30" in the person's language. */
    public static function when(CarbonInterface $moment): string
    {
        $local = $moment->setTimezone(AgendaSetting::timezone())->locale(app()->getLocale());

        return __('agenda.format.when', ['day' => $local->translatedFormat(__('agenda.format.day')), 'time' => $local->format('H:i')]);
    }

    /** "30%" from hundredths of a percent (3000), with decimals only when there are. */
    public static function percent(int $basisPoints): string
    {
        $value = $basisPoints / 100;

        return (fmod($value, 1.0) === 0.0 ? (string) (int) $value : number_format($value, 2, __('field.decimal_separator'), '')).'%';
    }
}
