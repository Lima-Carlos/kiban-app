<?php

namespace App\Modules\TimeClock\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place where people work (office, store, warehouse). Optional point on the map and radius:
 * a punch outside it is flagged for the manager, never blocked.
 */
class Workplace extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'tc_workplaces';

    protected $fillable = ['name', 'address', 'city', 'state', 'timezone', 'latitude', 'longitude', 'radius_meters', 'is_active', 'city_id'];

    protected $attributes = ['is_active' => true];

    /** Radius choices on the form (meters). */
    public const RADII = [100, 200, 300, 500, 1000, 2000];

    /** The holiday city: the one chosen, or else the city typed on the workplace (City::resolve). */
    protected static function booted(): void
    {
        static::saving(function (Workplace $workplace): void {
            $workplace->city_id = City::resolve($workplace->city_id, $workplace->city, $workplace->state);
        });
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'float',
            'longitude' => 'float',
            'radius_meters' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<City, $this>
     */
    public function holidayCity(): BelongsTo
    {
        return $this->belongsTo(City::class, 'city_id');
    }

    /**
     * @return HasMany<Employee, $this>
     */
    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function hasPoint(): bool
    {
        return $this->latitude !== null && $this->longitude !== null;
    }
}
