<?php

namespace App\Modules\TimeClock\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * A punch (clock in/out). It never changes and is never deleted: corrections are recorded apart, with the
 * reason (part 4). The only change allowed is removing an old photo (retention). Each punch has a sequential
 * number (NSR) and the hash of the previous one, so any change in the history would show.
 */
class Punch extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const PHONE = 'phone';

    public const KIOSK = 'kiosk';

    public const OUTSIDE_RADIUS = 'outside_radius';

    public const NO_LOCATION = 'no_location';

    public const NO_PHOTO = 'no_photo';

    /** Without a photo by the person's choice ("Punch without photo"). */
    public const PHOTO_DECLINED = 'photo_declined';

    /** Taken on the phone without signal and sent later (the time is when it was taken; created_at, when it arrived). */
    public const OFFLINE = 'offline';

    protected $table = 'tc_punches';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'nsr' => 'integer',
            'punched_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'latitude' => 'float',
            'longitude' => 'float',
            'accuracy_meters' => 'integer',
            'distance_meters' => 'integer',
            'flags' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (Punch $punch): void {
            // Only the old photo may go away (retention); nothing else of a punch changes.
            if (array_keys($punch->getDirty()) !== ['photo_path'] || $punch->photo_path !== null) {
                throw new LogicException('A punch never changes.');
            }
        });

        static::deleting(fn () => throw new LogicException('A punch is never deleted.'));
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

    /** The time of the punch where it happened (time zone of the workplace). */
    public function localTime(): CarbonImmutable
    {
        return $this->punched_at->setTimezone($this->timezone);
    }

    public function hasFlag(string $flag): bool
    {
        return in_array($flag, (array) $this->flags, true);
    }
}
