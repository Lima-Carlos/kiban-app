<?php

namespace App\Modules\Fleet\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fuel station the company works with. The driver picks one when generating a fuel code.
 */
class FuelStation extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'fleet_fuel_stations';

    protected $fillable = ['name', 'document', 'address', 'city', 'phone', 'is_active'];

    /** New ones start active (same default as the database). */
    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<FuelUp, $this>
     */
    public function fuelUps(): HasMany
    {
        return $this->hasMany(FuelUp::class, 'station_id');
    }
}
