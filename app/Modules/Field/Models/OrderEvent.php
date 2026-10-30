<?php

namespace App\Modules\Field\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One line of a work order's history: opened, scheduled for someone at a time, taken off the agenda, status
 * changed, canceled with the reason, a note. Written once, never changed.
 */
class OrderEvent extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'fd_order_events';

    protected $fillable = ['order_id', 'type', 'data', 'user_id'];

    protected function casts(): array
    {
        return [
            'data' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('History never changes.'));
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
