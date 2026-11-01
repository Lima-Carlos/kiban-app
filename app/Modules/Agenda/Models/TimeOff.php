<?php

namespace App\Modules\Agenda\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A time the professional does not attend (a day off, vacation, a doctor): no booking falls in it.
 */
class TimeOff extends Model
{
    use BelongsToTenant;

    protected $table = 'ag_time_off';

    protected $fillable = ['professional_id', 'starts_at', 'ends_at', 'reason'];

    protected function casts(): array
    {
        return ['starts_at' => 'immutable_datetime', 'ends_at' => 'immutable_datetime'];
    }

    /**
     * @return BelongsTo<Professional, $this>
     */
    public function professional(): BelongsTo
    {
        return $this->belongsTo(Professional::class);
    }
}
