<?php

namespace App\Modules\Fleet\Models;

use App\Modules\Fleet\Enums\DocumentType;
use App\Modules\Fleet\Enums\DueStatus;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vehicle document with expiry date (registration, insurance, tax...) and the file.
 */
class VehicleDocument extends Model
{
    use BelongsToTenant;

    protected $table = 'fleet_vehicle_documents';

    protected $fillable = ['vehicle_id', 'type', 'number', 'expires_on', 'file_path', 'notes'];

    protected function casts(): array
    {
        return [
            'type' => DocumentType::class,
            'expires_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    public function dueStatus(?FleetSetting $settings = null): DueStatus
    {
        if ($this->expires_on === null) {
            return DueStatus::NotSet;
        }

        $today = now()->startOfDay();

        return match (true) {
            $this->expires_on->lt($today) => DueStatus::Overdue,
            $this->expires_on->lte($today->copy()->addDays(($settings ?? FleetSetting::current())->alert_days)) => DueStatus::Soon,
            default => DueStatus::Ok,
        };
    }
}
