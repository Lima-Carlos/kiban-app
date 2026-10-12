<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\WashKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A wash of a vehicle (simple or complete), with the cost and, if typed, the odometer.
 */
class Wash extends Model
{
    use BelongsToTenant;

    protected $table = 'fleet_washes';

    protected $fillable = ['vehicle_id', 'appointment_id', 'kind', 'washed_on', 'odometer', 'cost_cents', 'place', 'performed_by', 'notes'];

    protected function casts(): array
    {
        return [
            'kind' => WashKind::class,
            'washed_on' => 'date',
            'odometer' => 'integer',
            'cost_cents' => 'integer',
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
    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
