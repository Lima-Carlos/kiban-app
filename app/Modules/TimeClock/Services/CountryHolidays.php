<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Holiday;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Suggests the national holidays of a country outside Brazil (Brazil is calculated by HolidayCalendar) from the
 * public Nager.Date list. Only a suggestion: the company reviews it. Without internet, nothing is added.
 */
class CountryHolidays
{
    public const URL = 'https://date.nager.at/api/v3/PublicHolidays/';

    /**
     * Public holidays of the country in the year (date => name, in the local language when it comes).
     *
     * @return array<string, string>
     */
    public function suggestions(string $country, int $year): array
    {
        try {
            $response = Http::timeout(15)->get(self::URL.$year.'/'.strtoupper($country));
        } catch (Throwable) {
            return [];
        }

        if (! $response->successful()) {
            return [];
        }

        $days = [];

        foreach ((array) $response->json() as $row) {
            // Only holidays of the whole country (regional ones are the company's to register).
            if (($row['global'] ?? true) === false || blank($row['date'] ?? null)) {
                continue;
            }

            $days[(string) $row['date']] = (string) ($row['localName'] ?? $row['name'] ?? '');
        }

        ksort($days);

        return $days;
    }

    /**
     * Registers the ones the company does not have yet for that day.
     *
     * @return int how many were added
     */
    public function import(string $country, int $year): int
    {
        $added = 0;

        foreach ($this->suggestions($country, $year) as $date => $name) {
            if ($name === '' || Holiday::query()->whereDate('date', $date)->whereNull('workplace_id')->whereNull('city_id')->exists()) {
                continue;
            }

            Holiday::query()->create(['date' => $date, 'name' => $name, 'recurring' => false]);
            $added++;
        }

        return $added;
    }
}
