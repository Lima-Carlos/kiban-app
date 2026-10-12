<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * A driver of the company. When their e-mail is the same as a Kiban account that signed in here,
 * the login is linked to this driver (FleetAccess) and they see only their vehicles.
 */
class Driver extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'fleet_drivers';

    protected $fillable = ['name', 'email', 'phone', 'document', 'license_number', 'license_category', 'license_expires_on', 'is_active', 'notes'];

    protected function casts(): array
    {
        return [
            'license_expires_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    protected function email(): Attribute
    {
        return Attribute::set(fn (?string $value): ?string => blank($value) ? null : mb_strtolower(trim($value)));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsToMany<Vehicle, $this>
     */
    public function vehicles(): BelongsToMany
    {
        return $this->belongsToMany(Vehicle::class, 'fleet_driver_vehicle')->using(DriverVehicle::class)->withTimestamps();
    }
}
