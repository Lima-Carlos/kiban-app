<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * A person signed in through the Kiban Core. The same person can belong to several companies.
 */
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    public const ROLE_OWNER = 'owner';

    public const ROLE_ADMIN = 'admin';

    public const ROLE_MEMBER = 'member';

    protected $fillable = ['core_id', 'name', 'email', 'phone', 'locale', 'app_locale', 'last_login_at'];

    protected $hidden = ['remember_token'];

    protected function casts(): array
    {
        return [
            'core_id' => 'integer',
            'last_login_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class)->withPivot(['role', 'last_seen_at'])->withTimestamps();
    }

    public function belongsToTenant(Tenant $tenant): bool
    {
        return $this->roleIn($tenant) !== null;
    }

    public function roleIn(?Tenant $tenant): ?string
    {
        if ($tenant === null) {
            return null;
        }

        // Asked many times per page (menu, buttons): read once per request.
        $role = $this->roles[$tenant->getKey()] ??= $this->tenants()->whereKey($tenant->getKey())->first()?->pivot->role ?? '';

        return $role === '' ? null : $role;
    }

    /** @var array<int, string> */
    protected array $roles = [];

    public function forgetRoles(): void
    {
        $this->roles = [];
    }

    /** Owner or administrator of the current company: may manage its settings and records of others. */
    public function canManageTenant(): bool
    {
        return in_array($this->roleIn(app(TenantContext::class)->current()), [self::ROLE_OWNER, self::ROLE_ADMIN], true);
    }

    public function canAccessPanel(Panel $panel): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $this->belongsToTenant($tenant);
    }
}
