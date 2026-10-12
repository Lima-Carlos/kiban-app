<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\RequestStatus;
use App\Modules\Fleet\Enums\ServiceKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * "Request maintenance or wash" from a driver: what is needed and an optional photo.
 * The manager or the mechanic schedules it (Appointment), rejects it or marks it done.
 */
class ServiceRequest extends Model
{
    use BelongsToTenant;

    protected $table = 'fleet_service_requests';

    protected $fillable = ['vehicle_id', 'kind', 'description', 'photo_path'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return [
            'kind' => ServiceKind::class,
            'status' => RequestStatus::class,
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
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return HasOne<Appointment, $this>
     */
    public function appointment(): HasOne
    {
        return $this->hasOne(Appointment::class, 'request_id');
    }
}
