<?php

namespace App\Modules\Rotina\Models;

use App\Models\User;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** What one hour of a person costs the company (salary and charges ÷ hours). Only the manager sees it. */
class Rate extends Model
{
    use BelongsToTenant;

    protected $table = 'ro_rates';

    protected $fillable = ['user_id', 'hour_cents'];

    protected function casts(): array
    {
        return ['hour_cents' => 'integer'];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
