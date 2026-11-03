<?php

namespace App\Modules\Pmoc\Models;

use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Models\WorkOrder;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A visit of a plan on a due date, with the level of activities that falls on it (1 monthly, 3 quarterly,
 * 6 half-yearly, 12 yearly) and the Campo work order created for it.
 */
class Visit extends Model
{
    use BelongsToTenant;

    protected $table = 'pm_visits';

    protected $fillable = ['plan_id', 'due_on', 'level', 'work_order_id'];

    protected function casts(): array
    {
        return [
            'due_on' => 'date',
            'level' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function isDone(): bool
    {
        return $this->workOrder?->status === OrderStatus::Done;
    }
}
