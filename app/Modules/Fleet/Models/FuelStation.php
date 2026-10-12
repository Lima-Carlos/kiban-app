<?php

namespace App\Modules\Fleet\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Fuel station the company works with. The driver picks one when generating a fuel code.
 */
class FuelStation extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'fleet_fuel_stations';

    protected $fillable = ['name', 'document', 'address', 'city', 'phone', 'is_active'];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
