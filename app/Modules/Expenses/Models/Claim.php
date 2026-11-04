<?php

namespace App\Modules\Expenses\Models;

use App\Models\User;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\ReportsFirstSteps;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An expense a person paid for the company, to be paid back: date, category, value, what it was, the receipt
 * photo and, optionally, the Campo work order or the Frota vehicle it was for. Pending → approved or refused →
 * paid. Changed only by ExpenseService.
 */
class Claim extends Model
{
    use BelongsToTenant, ReportsFirstSteps;

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const PAID = 'paid';

    public const STATUSES = [self::PENDING, self::APPROVED, self::REJECTED, self::PAID];

    protected $table = 'ex_claims';

    protected $fillable = ['user_id', 'category_id', 'spent_on', 'amount_cents', 'currency', 'description', 'receipt_path', 'work_order_id', 'vehicle_id'];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'spent_on' => 'date',
            'amount_cents' => 'integer',
            'decided_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Category, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    /**
     * @return BelongsTo<WorkOrder, $this>
     */
    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
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
    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /**
     * @param  Builder<Claim>  $query
     */
    public function scopeStatus(Builder $query, string $status): void
    {
        $query->where('status', $status);
    }

    /** Above the category's limit (shown to the manager; never blocked). */
    public function overLimit(): bool
    {
        return $this->category?->limit_cents !== null && $this->amount_cents > $this->category->limit_cents;
    }
}
