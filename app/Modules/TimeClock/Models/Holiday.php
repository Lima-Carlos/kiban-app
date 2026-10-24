<?php

namespace App\Modules\TimeClock\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A holiday registered by the company (state, city or its own). National holidays are calculated by
 * HolidayCalendar and are not stored. "Recurring" repeats on the same day every year; a holiday can be
 * only for one workplace (e.g. the anniversary of the city of a branch).
 */
class Holiday extends Model
{
    use BelongsToTenant;

    protected $table = 'tc_holidays';

    protected $fillable = ['date', 'name', 'recurring', 'workplace_id'];

    protected $attributes = ['recurring' => false];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'recurring' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Workplace, $this>
     */
    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }
}
