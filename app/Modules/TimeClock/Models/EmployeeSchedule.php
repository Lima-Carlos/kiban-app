<?php

namespace App\Modules\TimeClock\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "This employee follows this schedule from this day on." The history is kept: a change never touches the
 * days before it.
 */
class EmployeeSchedule extends Model
{
    use BelongsToTenant;

    protected $table = 'tc_employee_schedules';

    protected $fillable = ['employee_id', 'schedule_id', 'starts_on'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Schedule, $this>
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
