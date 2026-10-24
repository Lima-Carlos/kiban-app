<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Holiday;
use App\Modules\TimeClock\Models\TimeClockSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Holidays of the company:
 * - Brazil: the national holidays are calculated for any year (nothing to register), and the company
 *   chooses the optional days off (Good Friday on by default; Carnival and Corpus Christi off);
 * - other countries: no automatic list yet (the company registers its holidays);
 * - state, city and company holidays are registered by the company, for everyone or one workplace,
 *   once or every year.
 */
class HolidayCalendar
{
    /** Brazilian national holidays (month-day => key of the name). */
    public const BRAZIL_FIXED = [
        '01-01' => 'new_year',
        '04-21' => 'tiradentes',
        '05-01' => 'labor_day',
        '09-07' => 'independence',
        '10-12' => 'aparecida',
        '11-02' => 'all_souls',
        '11-15' => 'republic',
        '11-20' => 'black_consciousness',
        '12-25' => 'christmas',
    ];

    /** Optional days off in Brazil and whether they are on for a new company. */
    public const OPTIONAL = [
        'good_friday' => true,
        'carnival' => false,
        'corpus_christi' => false,
    ];

    /**
     * Calculated holidays of the country in the year (date => name), including the optional ones turned on.
     *
     * @return array<string, string>
     */
    public function national(int $year, ?TimeClockSetting $settings = null): array
    {
        $settings ??= TimeClockSetting::current();

        if (strtoupper((string) $settings->country) !== 'BR') {
            return [];
        }

        $days = [];

        foreach (self::BRAZIL_FIXED as $monthDay => $key) {
            // Black Consciousness Day is a national holiday since 2024 (Law 14.759/2023).
            if ($key === 'black_consciousness' && $year < 2024) {
                continue;
            }

            $days["{$year}-{$monthDay}"] = __('timeclock.holidays.national.'.$key);
        }

        foreach ($this->optional($year) as $key => $dates) {
            if ($this->optionalIsOn($key, $settings)) {
                foreach ($dates as $date) {
                    $days[$date] = __('timeclock.holidays.optional.'.$key);
                }
            }
        }

        ksort($days);

        return $days;
    }

    /**
     * Dates of the optional days off in the year, by key (they move with Easter).
     *
     * @return array<string, array<int, string>>
     */
    public function optional(int $year): array
    {
        $easter = static::easter($year);

        return [
            'good_friday' => [$easter->subDays(2)->toDateString()],
            'carnival' => [$easter->subDays(48)->toDateString(), $easter->subDays(47)->toDateString()],
            'corpus_christi' => [$easter->addDays(60)->toDateString()],
        ];
    }

    public function optionalIsOn(string $key, TimeClockSetting $settings): bool
    {
        return (bool) ((array) $settings->optional_holidays + self::OPTIONAL)[$key];
    }

    /**
     * Every holiday between two days for a workplace (date => name).
     *
     * @return array<string, string>
     */
    public function between(CarbonInterface $from, CarbonInterface $to, ?int $workplaceId = null): array
    {
        $from = CarbonImmutable::parse($from->toDateString());
        $to = CarbonImmutable::parse($to->toDateString());
        $settings = TimeClockSetting::current();
        $days = [];

        for ($year = $from->year; $year <= $to->year; $year++) {
            $days += $this->national($year, $settings);
        }

        $company = Holiday::query()
            ->where(fn ($query) => $query->whereNull('workplace_id')->when($workplaceId, fn ($query) => $query->orWhere('workplace_id', $workplaceId)))
            ->where(fn ($query) => $query->where('recurring', true)->orWhereBetween('date', [$from->toDateString(), $to->toDateString()]))
            ->get();

        foreach ($company as $holiday) {
            if (! $holiday->recurring) {
                $days[$holiday->date->toDateString()] = $holiday->name;

                continue;
            }

            for ($year = $from->year; $year <= $to->year; $year++) {
                // 29 February only in leap years.
                if (! checkdate($holiday->date->month, $holiday->date->day, $year)) {
                    continue;
                }

                $days[sprintf('%04d-%02d-%02d', $year, $holiday->date->month, $holiday->date->day)] = $holiday->name;
            }
        }

        $from = $from->toDateString();
        $to = $to->toDateString();
        $days = array_filter($days, fn (string $date): bool => $date >= $from && $date <= $to, ARRAY_FILTER_USE_KEY);
        ksort($days);

        return $days;
    }

    public function holidayOn(CarbonInterface $date, ?int $workplaceId = null): ?string
    {
        return $this->between($date, $date, $workplaceId)[$date->toDateString()] ?? null;
    }

    /** Easter Sunday (Gregorian calendar, "anonymous" algorithm): the moving holidays come from it. */
    public static function easter(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day);
    }
}
