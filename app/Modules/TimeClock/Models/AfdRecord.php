<?php

namespace App\Modules\TimeClock\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Brazil: a record of the AFD other than a punch (type 2 employer, type 5 employee), with its own number in the
 * same numbering as the punches (NSR). Like the punches, it never changes and is never deleted. See AfdLog.
 */
class AfdRecord extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    public const EMPLOYER = 2;

    public const EMPLOYEE = 5;

    protected $table = 'tc_afd_records';

    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'nsr' => 'integer',
            'type' => 'integer',
            'recorded_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('AFD records never change.'));
        static::deleting(fn () => throw new LogicException('AFD records are never deleted.'));
    }
}
