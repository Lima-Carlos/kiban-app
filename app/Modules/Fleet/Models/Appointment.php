<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\AppointmentStatus;
use App\Modules\Fleet\Enums\ServiceKind;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Scheduled maintenance or wash. Finishing it records the maintenance (or the wash) with the odometer.
 */
class Appointment extends Model
{
    use BelongsToTenant;

    protected $table = 'fleet_appointments';

    protected $fillable = ['vehicle_id', 'kind', 'scheduled_at', 'workshop_id', 'location', 'assigned_to', 'request_id', 'notes'];

    protected $attributes = ['status' => 'scheduled'];

    protected function casts(): array
    {
        return [
            'kind' => ServiceKind::class,
            'status' => AppointmentStatus::class,
            'scheduled_at' => 'datetime',
            'completed_at' => 'datetime',
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
     * @return BelongsTo<Workshop, $this>
     */
    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /**
     * @return BelongsTo<ServiceRequest, $this>
     */
    public function request(): BelongsTo
    {
        return $this->belongsTo(ServiceRequest::class, 'request_id');
    }

    /**
     * @return HasOne<Maintenance, $this>
     */
    public function maintenance(): HasOne
    {
        return $this->hasOne(Maintenance::class);
    }

    /**
     * @return HasOne<Wash, $this>
     */
    public function wash(): HasOne
    {
        return $this->hasOne(Wash::class);
    }

    /**
     * @param  Builder<Appointment>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', AppointmentStatus::Scheduled->value);
    }

    public function isOpen(): bool
    {
        return $this->status === AppointmentStatus::Scheduled;
    }

    /** Where: the workshop, or the place typed. */
    public function place(): ?string
    {
        return $this->workshop?->name ?? $this->location;
    }
}
