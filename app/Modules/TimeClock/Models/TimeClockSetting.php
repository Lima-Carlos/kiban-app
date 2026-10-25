<?php

namespace App\Modules\TimeClock\Models;

use App\Modules\TimeClock\Enums\LaborProfile;
use App\Support\Country;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Time clock settings of the company: country, labor rule profile ("Brazil (CLT)" or "Custom") and the
 * calculation rules (overtime, night hours, tolerance, hour bank, optional days off).
 * Brazil (CLT): the legal values; the company may only improve them (LEGAL limits).
 */
class TimeClockSetting extends Model
{
    use BelongsToTenant;

    protected $table = 'tc_settings';

    /** The CLT floors and ceilings: overtime at least 50% (100% on rest days and holidays), night 22:00–05:00 at least 20% with the 52m30s hour, tolerance up to 5 minutes per punch and 10 per day. */
    public const LEGAL = [
        'overtime_percent' => 50,
        'rest_day_overtime_percent' => 100,
        'night_start' => '22:00',
        'night_end' => '05:00',
        'night_percent' => 20,
        'tolerance_punch_minutes' => 5,
        'tolerance_day_minutes' => 10,
    ];

    /** Same as the database defaults (a new record already has them in memory). */
    protected $attributes = [
        'overtime_percent' => 50, 'rest_day_overtime_percent' => 100, 'night_start' => '22:00', 'night_end' => '05:00',
        'night_percent' => 20, 'night_reduced_hour' => true, 'tolerance_punch_minutes' => 5, 'tolerance_day_minutes' => 10,
        'hour_bank' => false, 'hour_bank_months' => 6, 'allow_punch_without_photo' => true,
    ];

    protected $fillable = [
        'country', 'labor_profile',
        'overtime_percent', 'rest_day_overtime_percent', 'night_start', 'night_end', 'night_percent', 'night_reduced_hour',
        'tolerance_punch_minutes', 'tolerance_day_minutes', 'hour_bank', 'hour_bank_months', 'optional_holidays',
    ];

    protected function casts(): array
    {
        return [
            'labor_profile' => LaborProfile::class,
            'configured_at' => 'datetime',
            'overtime_percent' => 'integer',
            'rest_day_overtime_percent' => 'integer',
            'night_percent' => 'integer',
            'night_reduced_hour' => 'boolean',
            'tolerance_punch_minutes' => 'integer',
            'tolerance_day_minutes' => 'integer',
            'hour_bank' => 'boolean',
            'hour_bank_months' => 'integer',
            'optional_holidays' => 'array',
            'last_nsr' => 'integer',
            'allow_punch_without_photo' => 'boolean',
        ];
    }

    public static function current(): self
    {
        $country = Country::current();

        return static::query()->first() ?? static::create([
            'country' => $country,
            'labor_profile' => LaborProfile::forCountry($country),
            // The 52m30s night hour is Brazilian.
            'night_reduced_hour' => Country::isBrazil($country),
        ]);
    }

    /** Brazilian rules: CPF and PIS required, official files, CLT values. */
    public function isBrazil(): bool
    {
        return $this->labor_profile === LaborProfile::BrazilClt;
    }
}
