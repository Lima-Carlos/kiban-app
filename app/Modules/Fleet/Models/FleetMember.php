<?php

namespace App\Modules\Fleet\Models;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Role given to a person in the "Fleet team" screen (manager, mechanic or viewer).
 */
class FleetMember extends Model
{
    use BelongsToTenant;

    protected $table = 'fleet_members';

    protected $fillable = ['user_id', 'role'];

    protected function casts(): array
    {
        return [
            'role' => FleetRole::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
