<?php

namespace App\Modules\TimeClock\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A correction of a working day, apart from the punches and with the reason (in Brazil, the "tratamento"):
 * - add_punch: a forgotten punch, at the moment informed;
 * - ignore_punch: a wrong punch left out of the calculation (it stays in the history);
 * - excuse: excused time (medical certificate...), some minutes or the whole day.
 * Only approved corrections count.
 */
class Adjustment extends Model
{
    use BelongsToTenant;

    public const ADD_PUNCH = 'add_punch';

    public const IGNORE_PUNCH = 'ignore_punch';

    public const EXCUSE = 'excuse';

    public const TYPES = [self::ADD_PUNCH, self::IGNORE_PUNCH, self::EXCUSE];

    public const PENDING = 'pending';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    protected $table = 'tc_adjustments';

    protected $fillable = ['employee_id', 'date', 'type', 'punch_at', 'punch_id', 'minutes', 'reason', 'attachment_path'];

    protected $attributes = ['status' => self::PENDING];

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'punch_at' => 'immutable_datetime',
            'minutes' => 'integer',
            'reviewed_at' => 'datetime',
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
     * @return BelongsTo<Punch, $this>
     */
    public function punch(): BelongsTo
    {
        return $this->belongsTo(Punch::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @param  Builder<Adjustment>  $query
     */
    public function scopeApproved(Builder $query): void
    {
        $query->where('status', self::APPROVED);
    }

    public function isPending(): bool
    {
        return $this->status === self::PENDING;
    }
}
