<?php

namespace App\Modules\Field\Models;

use App\Modules\ReportsFirstSteps;
use App\Support\Country;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Kiban Campo settings of the company: its business (the ready model of types of service and materials comes
 * from it), the currency of the prices and whether the customer signs at the end of the work by default.
 */
class FieldSetting extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    public const BUSINESSES = ['isp', 'hvac', 'security', 'general', 'none'];

    protected $table = 'fd_settings';

    protected $fillable = ['business', 'currency', 'signature_required', 'configured_at', 'travel_speed_kmh', 'route_factor', 'leave_alert_minutes', 'return_days', 'base_latitude', 'base_longitude', 'quote_valid_days'];

    protected function casts(): array
    {
        return [
            'signature_required' => 'boolean',
            'configured_at' => 'datetime',
            'travel_speed_kmh' => 'integer',
            'route_factor' => 'float',
            'leave_alert_minutes' => 'integer',
            'return_days' => 'integer',
            'base_latitude' => 'float',
            'base_longitude' => 'float',
        ];
    }

    public static function current(): self
    {
        // Refreshed after creating: the defaults of the database (speed, alerts...) come back with it.
        return static::query()->first() ?? static::create(['currency' => Country::currency(), 'signature_required' => true])->refresh();
    }

    public function isConfigured(): bool
    {
        return $this->configured_at !== null;
    }
}
