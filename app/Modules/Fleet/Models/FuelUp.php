<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Enums\FuelUpFlag;
use App\Modules\Fleet\Enums\FuelUpStatus;
use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A fuel-up. Usually: the driver generates a code (station + odometer + dashboard photo), the station
 * checks the code, and the driver finishes it with volume, amount and the receipt photo (FuelService).
 */
class FuelUp extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'fleet_fuel_ups';

    protected $fillable = ['vehicle_id', 'driver_id', 'station_id', 'odometer', 'odometer_photo_path', 'fuel', 'volume', 'total_cents', 'full_tank', 'receipt_path', 'fueled_at', 'notes', 'reason'];

    protected function casts(): array
    {
        return [
            'status' => FuelUpStatus::class,
            'fuel' => FuelType::class,
            'code_expires_at' => 'datetime',
            'station_checked_at' => 'datetime',
            'odometer' => 'integer',
            'volume' => 'decimal:3',
            'total_cents' => 'integer',
            'unit_price' => 'decimal:3',
            'full_tank' => 'boolean',
            'distance' => 'integer',
            'segment_volume' => 'decimal:3',
            'consumption' => 'decimal:3',
            'flags' => 'array',
            'fueled_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<Driver, $this>
     */
    public function driver(): BelongsTo
    {
        return $this->belongsTo(Driver::class);
    }

    /**
     * @return BelongsTo<FuelStation, $this>
     */
    public function station(): BelongsTo
    {
        return $this->belongsTo(FuelStation::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<FuelUp>  $query
     */
    public function scopeCompleted(Builder $query): void
    {
        $query->where('status', FuelUpStatus::Completed->value);
    }

    public function isOpen(): bool
    {
        return $this->status === FuelUpStatus::Authorized;
    }

    /** The code can still be used at the station. */
    public function codeIsValid(): bool
    {
        return $this->isOpen() && $this->code_expires_at?->isFuture();
    }

    /**
     * @return array<int, FuelUpFlag>
     */
    public function flagList(): array
    {
        return array_values(array_filter(array_map(fn (string $flag): ?FuelUpFlag => FuelUpFlag::tryFrom($flag), (array) $this->flags)));
    }
}
