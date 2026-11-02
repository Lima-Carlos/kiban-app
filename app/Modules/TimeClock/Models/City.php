<?php

namespace App\Modules\TimeClock\Models;

use App\Modules\TimeClock\Services\CityHolidays;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * A city of the company (for the municipal holidays). The IBGE code (Brazil) finds the suggested holidays;
 * the company always confirms them.
 */
class City extends Model
{
    use BelongsToTenant;

    protected $table = 'tc_cities';

    protected $fillable = ['name', 'state', 'ibge_code'];

    /**
     * The city id for a "holiday city" field: a registered city (id), a city picked from the IBGE list
     * ("ibge:<code>", registered now) or, when the field is empty, the city typed on the workplace (found among the
     * registered ones or in the IBGE list by name and state; otherwise registered with the typed name).
     */
    public static function resolve(mixed $value, ?string $typedName = null, ?string $typedState = null): ?int
    {
        if (is_string($value) && str_starts_with($value, 'ibge:')) {
            $code = (int) substr($value, 5);
            $found = app(CityHolidays::class)->find($code);

            return $found === null ? null : (int) static::query()->firstOrCreate(['ibge_code' => $code], ['name' => $found['name'], 'state' => $found['uf']])->getKey();
        }

        if (filled($value)) {
            return static::query()->whereKey($value)->exists() ? (int) $value : null;
        }

        $name = trim((string) $typedName);

        if ($name === '') {
            return null;
        }

        $state = mb_strtoupper(trim((string) $typedState));
        $same = fn (string $a, string $b): bool => Str::of($a)->ascii()->lower()->trim()->toString() === Str::of($b)->ascii()->lower()->trim()->toString();

        $registered = static::query()->get()->first(fn (City $city): bool => $same($city->name, $name) && ($state === '' || $same((string) $city->state, $state)));

        if ($registered !== null) {
            return (int) $registered->getKey();
        }

        foreach (app(CityHolidays::class)->search($name) as $code => $label) {
            [$ibgeName, $uf] = array_pad(explode(' - ', $label, 2), 2, '');

            if ($same($ibgeName, $name) && ($state === '' || $same($uf, $state))) {
                return (int) static::query()->firstOrCreate(['ibge_code' => $code], ['name' => $ibgeName, 'state' => $uf])->getKey();
            }
        }

        return (int) static::query()->create(['name' => $name, 'state' => $state !== '' ? $state : null])->getKey();
    }

    /**
     * A Brazilian city just registered brings its municipal holidays (patron saint, anniversary...) as a starting
     * list that the company reviews in Ponto → Holidays. Without internet nothing is added.
     */
    protected static function booted(): void
    {
        static::created(function (City $city): void {
            if ($city->ibge_code) {
                $year = (int) now()->year;
                app(CityHolidays::class)->import($city, $year);
                app(CityHolidays::class)->import($city, $year + 1);
            }
        });
    }

    public function label(): string
    {
        return $this->state ? $this->name.' - '.$this->state : $this->name;
    }

    /**
     * @return HasMany<Holiday, $this>
     */
    public function holidays(): HasMany
    {
        return $this->hasMany(Holiday::class);
    }
}
