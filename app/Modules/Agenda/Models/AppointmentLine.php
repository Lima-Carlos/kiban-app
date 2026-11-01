<?php

namespace App\Modules\Agenda\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One service of an appointment, with the name, time, price and commission of that moment (prices may change later).
 */
class AppointmentLine extends Model
{
    use BelongsToTenant;

    protected $table = 'ag_appointment_lines';

    protected $fillable = ['appointment_id', 'service_id', 'name', 'duration_minutes', 'price_cents', 'commission_bp'];

    protected function casts(): array
    {
        return ['duration_minutes' => 'integer', 'price_cents' => 'integer', 'commission_bp' => 'integer'];
    }

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** The professional's part of this line, in cents (rounded). */
    public function commissionCents(): int
    {
        return (int) round($this->price_cents * $this->commission_bp / 10000);
    }
}
