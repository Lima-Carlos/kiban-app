<?php

namespace App\Modules\TimeClock\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The closing of a month for an employee: the frozen copy of the calculation, the employee's electronic
 * signature (or disagreement) and, when reopened, who and why (the old closing stays in the history).
 */
class Closing extends Model
{
    use BelongsToTenant;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    public const SIGNED = 'signed';

    public const DISPUTED = 'disputed';

    protected $table = 'tc_closings';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'month' => 'date',
            'days' => 'array',
            'totals' => 'array',
            'bank' => 'array',
            'closed_at' => 'datetime',
            'signed_at' => 'datetime',
            'disputed_at' => 'datetime',
            'reopened_at' => 'datetime',
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
     * @return BelongsTo<User, $this>
     */
    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function signer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'signed_by');
    }

    /** The closings in force (not reopened). */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereNull('reopened_at');
    }

    public function status(): string
    {
        return match (true) {
            $this->signed_at !== null => self::SIGNED,
            $this->disputed_at !== null => self::DISPUTED,
            default => self::CLOSED,
        };
    }

    public function isSigned(): bool
    {
        return $this->signed_at !== null;
    }
}
