<?php

namespace App\Models;

use App\Access\AccessManager;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The role of a person in one module of the company (e.g. Frota → "manager"). No row = no access
 * (except owners and administrators, who have every module, and roles a module gives by itself).
 */
class ModuleAccess extends Model
{
    use BelongsToTenant;

    protected $table = 'module_access';

    protected $fillable = ['user_id', 'module', 'role'];

    protected static function booted(): void
    {
        // Roles are remembered during a request: a change must be seen right away.
        static::saved(fn () => app(AccessManager::class)->forget());
        static::deleted(fn () => app(AccessManager::class)->forget());
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
