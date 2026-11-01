<?php

namespace App\Modules\Agenda\Models;

use App\Modules\ReportsFirstSteps;
use App\Support\Country;
use App\Tenancy\BelongsToTenant;
use App\Tenancy\TenantContext;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Model;

/**
 * Kiban Core Agenda settings of the company: the business (ready services), the currency and the rules of the
 * public booking page (step between times, notice, days ahead, until when the client cancels by the link).
 */
class AgendaSetting extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    public const BUSINESSES = ['petshop', 'none'];

    protected $table = 'ag_settings';

    protected $fillable = ['business', 'currency', 'online_booking', 'slot_minutes', 'min_notice_minutes', 'booking_days', 'cancel_hours', 'no_show_flag', 'pickup_enabled', 'field_service_type_id', 'booking_message', 'configured_at'];

    protected function casts(): array
    {
        return [
            'online_booking' => 'boolean',
            'pickup_enabled' => 'boolean',
            'slot_minutes' => 'integer',
            'min_notice_minutes' => 'integer',
            'booking_days' => 'integer',
            'cancel_hours' => 'integer',
            'no_show_flag' => 'integer',
            'field_service_type_id' => 'integer',
            'configured_at' => 'datetime',
        ];
    }

    public static function current(): self
    {
        return static::query()->first() ?? static::create(['currency' => Country::currency()])->refresh();
    }

    public function isConfigured(): bool
    {
        return $this->configured_at !== null;
    }

    /** The company's time zone (from its license), used for every time of the agenda. */
    public static function timezone(): string
    {
        return app(TenantContext::class)->current()?->license()->tenant('timezone') ?: FilamentTimezone::get();
    }
}
