<?php

namespace App\Modules\TimeClock\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Someone's acknowledgement of the privacy notice of the punch photos (LGPD): which version, when, where
 * (phone or which tablet) and on which device. It is a record of fact: never changed, never deleted.
 */
class PhotoNotice extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $table = 'tc_photo_notices';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'retention_months' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('An acknowledgement never changes.'));
        static::deleting(fn () => throw new LogicException('An acknowledgement is never deleted.'));
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * @return BelongsTo<Kiosk, $this>
     */
    public function kiosk(): BelongsTo
    {
        return $this->belongsTo(Kiosk::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
