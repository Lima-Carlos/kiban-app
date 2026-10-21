<?php

namespace App\Modules\Fleet\Models;

use App\Modules\Fleet\Enums\DueStatus;
use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Enums\VehicleStatus;
use App\Modules\Fleet\Enums\VehicleType;
use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A vehicle of the company. The odometer is always the highest reading recorded (OdometerService).
 */
class Vehicle extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'fleet_vehicles';

    protected $fillable = [
        'plate', 'vin', 'registration', 'brand', 'model', 'manufacture_year', 'model_year', 'color', 'type', 'fuel_type',
        'tank_capacity', 'photo_path', 'status', 'notes',
        'next_oil_change_odometer', 'next_oil_change_on', 'oil_change_interval_distance', 'oil_change_interval_months',
        'next_revision_odometer', 'next_revision_on', 'revision_interval_distance', 'revision_interval_months',
    ];

    protected function casts(): array
    {
        return [
            'type' => VehicleType::class,
            'fuel_type' => FuelType::class,
            'status' => VehicleStatus::class,
            'tank_capacity' => 'decimal:2',
            'odometer' => 'integer',
            'odometer_updated_at' => 'datetime',
            'next_oil_change_odometer' => 'integer',
            'next_oil_change_on' => 'date',
            'next_revision_odometer' => 'integer',
            'next_revision_on' => 'date',
            'identity_locked_at' => 'datetime',
            'deactivated_at' => 'datetime',
        ];
    }

    /** "abc-1d23" and "ABC 1D23" are the same plate. */
    protected function plate(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => $value === null ? null : static::normalizePlate($value));
    }

    protected function vin(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => blank($value) ? null : strtoupper(preg_replace('/\s+/', '', $value)));
    }

    public static function normalizePlate(string $plate): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $plate));
    }

    /**
     * @return BelongsToMany<Driver, $this>
     */
    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class, 'fleet_driver_vehicle')->using(DriverVehicle::class)->withTimestamps();
    }

    /**
     * @return HasMany<OdometerReading, $this>
     */
    public function odometerReadings(): HasMany
    {
        return $this->hasMany(OdometerReading::class);
    }

    /**
     * @return HasMany<VehicleDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }

    /**
     * @return HasMany<FuelUp, $this>
     */
    public function fuelUps(): HasMany
    {
        return $this->hasMany(FuelUp::class);
    }

    /**
     * @return HasMany<Maintenance, $this>
     */
    public function maintenances(): HasMany
    {
        return $this->hasMany(Maintenance::class);
    }

    /**
     * @return HasMany<Wash, $this>
     */
    public function washes(): HasMany
    {
        return $this->hasMany(Wash::class);
    }

    /**
     * @return HasMany<Expense, $this>
     */
    public function expenses(): HasMany
    {
        return $this->hasMany(Expense::class);
    }

    /**
     * @return HasMany<Appointment, $this>
     */
    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /**
     * @return HasMany<ServiceRequest, $this>
     */
    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    /**
     * @return HasMany<Inspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    /**
     * @return HasMany<VehicleEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(VehicleEvent::class);
    }

    /**
     * @param  Builder<Vehicle>  $query
     */
    public function scopeCountingInLimit(Builder $query): void
    {
        $query->where('status', '!=', VehicleStatus::Inactive->value);
    }

    public function displayName(): string
    {
        return "{$this->plate} · {$this->brand} {$this->model}";
    }

    public function isActiveForRecords(): bool
    {
        return $this->status !== VehicleStatus::Inactive;
    }

    public function oilChangeStatus(?FleetSetting $settings = null): DueStatus
    {
        return $this->dueStatus($this->next_oil_change_odometer, $this->next_oil_change_on, $settings);
    }

    public function revisionStatus(?FleetSetting $settings = null): DueStatus
    {
        return $this->dueStatus($this->next_revision_odometer, $this->next_revision_on, $settings);
    }

    /**
     * Due by distance or by date, whichever comes first.
     */
    protected function dueStatus(?int $odometer, ?CarbonInterface $date, ?FleetSetting $settings): DueStatus
    {
        if ($odometer === null && $date === null) {
            return DueStatus::NotSet;
        }

        $settings ??= FleetSetting::current();
        $today = now()->startOfDay();

        if (($odometer !== null && $this->odometer >= $odometer) || ($date !== null && $date->lte($today))) {
            return DueStatus::Overdue;
        }

        if (($odometer !== null && $odometer - $this->odometer <= $settings->alert_distance)
            || ($date !== null && $date->lte($today->copy()->addDays($settings->alert_days)))) {
            return DueStatus::Soon;
        }

        return DueStatus::Ok;
    }
}
