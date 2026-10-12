<?php

namespace App\Modules\Fleet\Models;

use App\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * Which vehicles each driver uses. The company is filled in automatically, and the database checks that
 * the driver and the vehicle belong to that same company.
 */
class DriverVehicle extends Pivot
{
    protected $table = 'fleet_driver_vehicle';

    public $incrementing = true;

    protected static function booted(): void
    {
        static::creating(function (DriverVehicle $pivot): void {
            $pivot->tenant_id = app(TenantContext::class)->get()->getKey();
        });
    }
}
