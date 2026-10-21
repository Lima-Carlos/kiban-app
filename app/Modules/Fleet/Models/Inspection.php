<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Inspection of a vehicle (phone-first): photos of the 4 sides and the dashboard, odometer and the answer
 * of each checklist item as it was at that moment. Never edited or deleted.
 */
class Inspection extends Model
{
    use BelongsToTenant;

    protected $table = 'fleet_inspections';

    /** The photos asked in every inspection. */
    public const PHOTOS = ['front', 'back', 'left', 'right', 'dashboard'];

    protected $fillable = ['vehicle_id', 'performed_at', 'odometer', 'photos', 'answers', 'notes'];

    protected function casts(): array
    {
        return [
            'performed_at' => 'datetime',
            'odometer' => 'integer',
            'photos' => 'array',
            'answers' => 'array',
            'problems' => 'integer',
            'has_critical' => 'boolean',
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
    public function inspector(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * Maintenance requests opened by the problems of this inspection.
     *
     * @return HasMany<ServiceRequest, $this>
     */
    public function serviceRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class);
    }

    /**
     * @return array<int, array{item_id: ?int, label: string, critical: bool, ok: bool, note: ?string, photo: ?string}>
     */
    public function problemAnswers(): array
    {
        return array_values(array_filter((array) $this->answers, fn (array $answer): bool => ! $answer['ok']));
    }
}
