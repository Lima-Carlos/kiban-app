<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\City;
use App\Modules\TimeClock\Models\Holiday;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Finds Brazilian cities (IBGE list) and suggests their municipal holidays from the open base
 * joaopbini/feriados-brasil. It is only a suggestion: the company reviews it (the municipal law is what counts)
 * and, without internet or without data, registers the holidays by hand.
 */
class CityHolidays
{
    public const IBGE_URL = 'https://servicodados.ibge.gov.br/api/v1/localidades/municipios?orderBy=nome';

    public const DATA_URL = 'https://raw.githubusercontent.com/joaopbini/feriados-brasil/master/dados/feriados/municipal/json/';

    /**
     * Cities whose name contains the text (code => "Name - UF"), at most 40.
     *
     * @return array<int, string>
     */
    public function search(string $text): array
    {
        $needle = Str::of($text)->ascii()->lower()->trim()->toString();

        if (mb_strlen($needle) < 2) {
            return [];
        }

        $found = [];

        foreach ($this->municipalities() as $city) {
            if (str_contains($city['search'], $needle)) {
                $found[$city['id']] = $city['name'].' - '.$city['uf'];

                if (count($found) >= 40) {
                    break;
                }
            }
        }

        return $found;
    }

    /** @return array{name: string, uf: string}|null */
    public function find(int $code): ?array
    {
        foreach ($this->municipalities() as $city) {
            if ($city['id'] === $code) {
                return ['name' => $city['name'], 'uf' => $city['uf']];
            }
        }

        return null;
    }

    /**
     * Municipal holidays of the city in the year (date => name). Empty when there is no data or no internet.
     *
     * @return array<string, string>
     */
    public function suggestions(City $city, int $year): array
    {
        if (! $city->ibge_code) {
            return [];
        }

        $days = [];

        foreach ($this->year($year) as $row) {
            if ((int) ($row['codigo_ibge'] ?? 0) !== (int) $city->ibge_code) {
                continue;
            }

            $date = CarbonImmutable::createFromFormat('d/m/Y', (string) $row['data']);

            if ($date) {
                $days[$date->toDateString()] = (string) ($row['nome'] ?? '');
            }
        }

        ksort($days);

        return $days;
    }

    /**
     * Registers the suggested holidays that the city does not have yet (patron saint, city anniversary...). A
     * holiday on the same day and month in the year before too becomes "every year"; the others stay for that year.
     *
     * @return int how many were added
     */
    public function import(City $city, int $year): int
    {
        $added = 0;
        $before = collect($this->suggestions($city, $year - 1))->keys()->map(fn (string $date): string => substr($date, 5))->all();
        $existing = Holiday::query()->where('city_id', $city->getKey())->get();

        foreach ($this->suggestions($city, $year) as $date => $name) {
            $monthDay = substr($date, 5);
            $recurring = in_array($monthDay, $before, true);

            if ($existing->contains(fn (Holiday $holiday): bool => $holiday->date->toDateString() === $date || ($holiday->recurring && $holiday->date->format('m-d') === $monthDay))) {
                continue;
            }

            $existing->push(Holiday::query()->create(['date' => $date, 'name' => $name !== '' ? $name : __('timeclock.cities.municipal_holiday'), 'recurring' => $recurring, 'city_id' => $city->getKey()]));
            $added++;
        }

        return $added;
    }

    /** @return array<int, array{id: int, name: string, uf: string, search: string}> */
    protected function municipalities(): array
    {
        $cached = Cache::get('ibge-municipalities');

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $rows = Http::timeout(20)->get(self::IBGE_URL)->throw()->json();
        } catch (Throwable) {
            return [];
        }

        $list = collect((array) $rows)->map(fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['nome'],
            'uf' => (string) ($row['microrregiao']['mesorregiao']['UF']['sigla'] ?? ''),
            'search' => Str::of((string) $row['nome'])->ascii()->lower()->toString(),
        ])->all();

        if ($list !== []) {
            Cache::put('ibge-municipalities', $list, now()->addDays(30));
        }

        return $list;
    }

    /** @return array<int, array<string, mixed>> */
    protected function year(int $year): array
    {
        $cached = Cache::get('municipal-holidays-'.$year);

        if (is_array($cached) && $cached !== []) {
            return $cached;
        }

        try {
            $response = Http::timeout(30)->get(self::DATA_URL.$year.'.json');
            $rows = $response->successful() ? (array) $response->json() : [];
        } catch (Throwable) {
            return [];
        }

        if ($rows !== []) {
            Cache::put('municipal-holidays-'.$year, $rows, now()->addDay());
        }

        return $rows;
    }
}
