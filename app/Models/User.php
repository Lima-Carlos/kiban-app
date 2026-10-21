<?php

namespace App\Models;

use App\Tenancy\TenantContext;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Notifications\Notifiable;

/**
 * A person signed in through the Kiban Core. The same person can belong to several companies.
 */
class User extends Authenticatable implements FilamentUser, HasLocalePreference
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
        return $this->belongsToMany(Tenant::class)->withPivot(['role', 'last_seen_at', 'access_profile_id'])->withTimestamps();
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

    /**
     * Warnings of the bell: only the current company's (the same person can be in several companies).
     *
     * @return MorphMany<DatabaseNotification, $this>
     */
    public function notifications(): MorphMany
    {
        $query = $this->morphMany(DatabaseNotification::class, 'notifiable')->latest();
        $tenant = app(TenantContext::class)->current();

        if ($tenant !== null) {
            $query->where('tenant_id', $tenant->getKey());
        }

        return $query;
    }

    /** Warnings are written in the person's language (same order as the pages). */
    public function preferredLocale(): ?string
    {
        $supported = array_keys(config('kiban.locales'));

        foreach ([$this->app_locale, $this->locale, app(TenantContext::class)->current()?->license()->tenant('locale')] as $locale) {
            if (in_array($locale, $supported, true)) {
                return $locale;
            }
        }

        return null;
    }

    public function canAccessPanel(Panel $panel): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $this->belongsToTenant($tenant);
    }
}
