<?php

namespace App\Modules\TimeClock\Filament;

use App\Modules\TimeClock\Models\City;
use App\Modules\TimeClock\Services\CityHolidays;
use Filament\Forms\Components\Select;

/**
 * "Holiday city" field (workplace, employee, holiday): the cities already registered, and by typing, any city of
 * Brazil (IBGE list). A city picked from the IBGE list is saved as "ibge:<code>" and registered on save
 * (City::resolve), so nobody needs to open Ponto → Cities first.
 */
class HolidayCitySelect
{
    public static function make(string $name = 'city_id'): Select
    {
        return Select::make($name)
            ->searchable()
            ->preload()
            ->options(fn (): array => static::registered())
            ->getSearchResultsUsing(fn (string $search): array => static::search($search))
            ->getOptionLabelUsing(fn ($value): ?string => static::label($value));
    }

    /** @return array<int|string, string> */
    protected static function registered(): array
    {
        return City::query()->orderBy('name')->get()->mapWithKeys(fn (City $city): array => [$city->getKey() => $city->label()])->all();
    }

    /** @return array<int|string, string> */
    protected static function search(string $search): array
    {
        $needle = mb_strtolower(trim($search));
        $found = collect(static::registered())->filter(fn (string $label): bool => str_contains(mb_strtolower($label), $needle))->all();
        $known = City::query()->whereNotNull('ibge_code')->pluck('ibge_code')->map(fn ($code): int => (int) $code)->all();

        foreach (app(CityHolidays::class)->search($search) as $code => $label) {
            if (! in_array((int) $code, $known, true)) {
                $found['ibge:'.$code] = $label;
            }
        }

        return $found;
    }

    protected static function label(mixed $value): ?string
    {
        if (is_string($value) && str_starts_with($value, 'ibge:')) {
            $city = app(CityHolidays::class)->find((int) substr($value, 5));

            return $city ? $city['name'].' - '.$city['uf'] : null;
        }

        return City::query()->find($value)?->label();
    }
}
