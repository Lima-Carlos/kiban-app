<?php

namespace App\Modules\TimeClock\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place where people work (office, store, warehouse). Optional point on the map and radius:
 * a punch outside it is flagged for the manager, never blocked.
 */
class Workplace extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'tc_workplaces';

    protected $fillable = ['name', 'address', 'city', 'state', 'timezone', 'latitude', 'longitude', 'radius_meters', 'is_active'];

    protected $attributes = ['is_active' => true];

    /** Radius choices on the form (meters). */
    public const RADII = [100, 200, 300, 500, 1000, 2000];

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
