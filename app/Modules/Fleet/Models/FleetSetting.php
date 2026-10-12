<?php

namespace App\Modules\Fleet\Models;

use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Frota settings of the company: units (km/mi, l/gal), currency and how early to warn
 * about oil changes and revisions. Created with the defaults of the company's country.
 */
class FleetSetting extends Model
{
    use BelongsToTenant;

    protected $fillable = ['distance_unit', 'volume_unit', 'currency', 'alert_distance', 'alert_days'];

    /** Countries that use miles / gallons by default. */
    protected const MILES = ['US', 'GB', 'LR', 'MM'];

    protected const GALLONS = ['US', 'LR', 'MM'];

    protected const CURRENCIES = [
        'BR' => 'BRL', 'US' => 'USD', 'PT' => 'EUR', 'ES' => 'EUR', 'FR' => 'EUR', 'DE' => 'EUR', 'IT' => 'EUR',
        'MX' => 'MXN', 'AR' => 'ARS', 'CL' => 'CLP', 'CO' => 'COP', 'PE' => 'PEN', 'UY' => 'UYU', 'PY' => 'PYG',
        'GB' => 'GBP', 'CA' => 'CAD',
    ];

    /** Currencies the company can choose. */
    public const CURRENCY_CODES = ['BRL', 'USD', 'EUR', 'GBP', 'CAD', 'MXN', 'ARS', 'CLP', 'COP', 'PEN', 'UYU', 'PYG'];

    public const DISTANCE_UNITS = ['km', 'mi'];

    public const VOLUME_UNITS = ['l', 'gal'];

    protected function casts(): array
    {
        return [
            'alert_distance' => 'integer',
            'alert_days' => 'integer',
        ];
    }

    /**
     * Settings of the current company (created on first use).
     */
    public static function current(): self
    {
        $tenant = app(TenantContext::class)->get();

        return static::query()->first() ?? static::create(static::defaultsFor((string) $tenant->license()->tenant('country', 'BR')));
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaultsFor(string $country): array
    {
        $country = strtoupper($country);

        return [
            'distance_unit' => in_array($country, self::MILES, true) ? 'mi' : 'km',
            'volume_unit' => in_array($country, self::GALLONS, true) ? 'gal' : 'l',
            'currency' => self::CURRENCIES[$country] ?? 'USD',
            'alert_distance' => in_array($country, self::MILES, true) ? 300 : 500,
            'alert_days' => 15,
        ];
    }

    /**
     * Units can only change while there is nothing recorded (values are stored as they were typed).
     */
    public function unitsLocked(): bool
    {
        return Vehicle::query()->exists();
    }
}
