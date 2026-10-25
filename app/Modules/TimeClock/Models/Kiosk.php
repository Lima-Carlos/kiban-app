<?php

namespace App\Modules\TimeClock\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A company tablet used as a time clock (no login): the employee types the PIN and a photo is taken.
 * The tablet keeps a secret (only its hash is stored); the manager activates and deactivates it.
 */
class Kiosk extends Model
{
    use BelongsToTenant;

    protected $table = 'tc_kiosks';

    protected $fillable = ['name', 'workplace_id', 'is_active'];

    protected $attributes = ['is_active' => true];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return [
            'activated_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Workplace, $this>
     */
    public function workplace(): BelongsTo
    {
        return $this->belongsTo(Workplace::class);
    }

    /**
     * @return HasMany<Punch, $this>
     */
    public function punches(): HasMany
    {
        return $this->hasMany(Punch::class);
    }

    public function isActivated(): bool
    {
        return $this->token_hash !== null;
    }
}
