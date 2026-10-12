<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\VehicleEventType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Important change of a vehicle (identity, deactivation, odometer correction): who, when and why.
 * Never edited or deleted.
 */
class VehicleEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'fleet_vehicle_events';

    protected $fillable = ['vehicle_id', 'type', 'data', 'reason', 'user_id'];

    protected function casts(): array
    {
        return [
            'type' => VehicleEventType::class,
            'data' => 'array',
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
