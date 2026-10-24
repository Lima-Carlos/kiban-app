<?php

namespace App\Modules\TimeClock\Models;

use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A work schedule:
 * - weekly: each weekday its hours, with one break (e.g. Monday to Friday 8:00–18:00, lunch 12:00–13:00);
 * - cycle: work N days, rest M days, counted from a reference date (e.g. 12x36 = work 1, rest 1; 6x1).
 * A shift may cross midnight (end before start = next day). A schedule already given to someone is not
 * changed (past days keep their rules): it is duplicated and the new one is given from a date.
 */
class Schedule extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    public const WEEKLY = 'weekly';

    public const CYCLE = 'cycle';

    protected $table = 'tc_schedules';

    protected $fillable = ['name', 'type', 'week', 'work_days', 'off_days', 'start_time', 'end_time', 'break_minutes', 'anchor_date', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'week' => 'array',
            'work_days' => 'integer',
            'off_days' => 'integer',
            'break_minutes' => 'integer',
            'anchor_date' => 'date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<EmployeeSchedule, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EmployeeSchedule::class);
    }

    /** Given to someone already: changing it would change past days. */
    public function isInUse(): bool
    {
        return $this->assignments()->exists();
    }
}
