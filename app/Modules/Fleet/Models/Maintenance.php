<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\MaintenanceItem;
use App\Modules\Fleet\Enums\MaintenanceType;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A maintenance done (preventive or corrective): services, odometer, workshop or mechanic, costs and invoice.
 * Recorded by MaintenanceService (odometer and next oil change / revision).
 */
class Maintenance extends Model
{
    use BelongsToTenant;

    protected $table = 'fleet_maintenances';

    protected $fillable = ['vehicle_id', 'appointment_id', 'type', 'services', 'description', 'performed_on', 'odometer', 'workshop_id', 'performed_by', 'parts_cents', 'labor_cents', 'invoice_path'];

    protected function casts(): array
    {
        return [
            'type' => MaintenanceType::class,
            'services' => 'array',
            'performed_on' => 'date',
            'odometer' => 'integer',
            'parts_cents' => 'integer',
            'labor_cents' => 'integer',
            'total_cents' => 'integer',
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
    public function mechanic(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }

    /**
     * @return array<int, MaintenanceItem>
     */
    public function items(): array
    {
        return array_values(array_filter(array_map(fn ($item): ?MaintenanceItem => MaintenanceItem::tryFrom((string) $item), (array) $this->services)));
    }

    public function has(MaintenanceItem $item): bool
    {
        return in_array($item, $this->items(), true);
    }
}
