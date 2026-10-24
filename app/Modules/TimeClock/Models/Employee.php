<?php

namespace App\Modules\TimeClock\Models;

use App\Modules\ReportsFirstSteps;
use App\People\Person;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Someone who clocks in. The name, mobile, document and team are in the person's record (People screen);
 * here only what the time clock needs. Active employees count in the plan limit ("employees").
 */
class Employee extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    protected $table = 'tc_employees';

    protected $fillable = ['registration', 'pis', 'admitted_on', 'terminated_on', 'job_title', 'workplace_id', 'is_external', 'is_active'];

    protected $attributes = ['is_active' => true, 'is_external' => false];

    protected function casts(): array
    {
        return [
            'admitted_on' => 'date',
            'terminated_on' => 'date',
            'is_external' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Person, $this>
     */
    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }

    /**
     * @return BelongsTo<Workplace, $this>
     */
    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    /**
     * @param  Builder<Employee>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
