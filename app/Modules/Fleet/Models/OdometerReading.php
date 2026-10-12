<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One odometer reading: from the vehicle registration, a fuel-up, a maintenance, an inspection, a wash,
 * typed by hand or a correction. Created only by OdometerService.
 */
class OdometerReading extends Model
{
    use BelongsToTenant;

    protected $table = 'fleet_odometer_readings';

    protected $fillable = ['vehicle_id', 'value', 'source', 'record_type', 'record_id', 'read_at', 'user_id', 'reason'];

    protected function casts(): array
    {
        return [
            'value' => 'integer',
            'source' => OdometerSource::class,
            'read_at' => 'datetime',
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

    /**
     * @return MorphTo<Model, $this>
     */
    public function record(): MorphTo
    {
        return $this->morphTo();
    }
}
