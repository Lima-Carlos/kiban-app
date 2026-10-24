<?php

namespace App\Access;

use App\Models\AccessProfile;
use App\Models\ModuleAccess;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * One system, many modules: who sees which module, and with which role.
 *
 * 1. Owners and administrators of the company: every module, as its manager.
 * 2. The role given to the person for that module (People screen / access profile).
 * 3. A role the module gives by itself (e.g. Frota: someone linked to a driver record is a driver).
 * 4. Otherwise: no access — the module does not even appear in the menu.
 */
class AccessManager
{
    /** "No access" in forms and profiles. */
    public const NONE = 'none';

    /** @var array<string, ?string> roles already read in this request */
    protected array $cache = [];

    public function __construct(
        protected ModuleRegistry $modules,
        protected TenantContext $context,
    ) {}

    public function role(?User $user, string $moduleKey): ?string
    {
        $user ??= Auth::user();
        $tenant = $this->context->current();

        if (! $user instanceof User || $tenant === null) {
            return null;
        }

        $cacheKey = $tenant->getKey().':'.$user->getKey().':'.$moduleKey;

        if (array_key_exists($cacheKey, $this->cache)) {
            return $this->cache[$cacheKey];
        }

        $module = $this->modules->get($moduleKey);

        $role = match (true) {
            $user->canManageTenant() => $module->managerRole(),
            default => ModuleAccess::query()->where('user_id', $user->getKey())->where('module', $moduleKey)->value('role')
                ?? $module->implicitRole($user),
        };

        return $this->cache[$cacheKey] = $role;
    }

    public function hasAccess(?User $user, string $moduleKey): bool
    {
        return $this->role($user, $moduleKey) !== null;
    }

    /**
     * Modules of the company (license) that the person can open.
     *
     * @return array<string, Module>
     */
    public function modulesFor(?User $user, Tenant $tenant): array
    {
        return array_filter($this->modules->enabledFor($tenant), fn (Module $module): bool => $this->hasAccess($user, $module->key()));
    }

    /**
     * Sets (or removes, with null / "none") the person's role in a module.
     */
    public function set(User $user, string $moduleKey, ?string $role): void
    {
        $this->forget();

        if ($role === null || $role === self::NONE) {
            ModuleAccess::query()->where('user_id', $user->getKey())->where('module', $moduleKey)->delete();

            return;
        }

        ModuleAccess::query()->updateOrCreate(['user_id' => $user->getKey(), 'module' => $moduleKey], ['role' => $role]);
    }

    /** Remembers which profile the person got (shown in the People list). */
    public function rememberProfile(User $user, ?AccessProfile $profile): void
    {
        $user->tenants()->updateExistingPivot($this->context->get()->getKey(), ['access_profile_id' => $profile?->getKey()]);
    }

    public function profileOf(User $user): ?AccessProfile
    {
        $id = $user->tenants()->whereKey($this->context->get()->getKey())->first()?->pivot?->access_profile_id;

        return $id ? AccessProfile::query()->find($id) : null;
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    /**
     * Creates the suggested profiles (Logistics, Driver, Mechanic, HR...) with the systems the company has.
     * A suggestion appears once: when the company gets a new system (e.g. the time clock brings "HR"); one the
     * company deleted is not offered again. The company can rename, change or delete them.
     */
    public function ensureSuggestedProfiles(): void
    {
        $enabled = $this->modules->enabledFor($this->context->get());
        $offered = AccessProfile::withTrashed()->whereNotNull('suggestion')->pluck('suggestion')->all();

        foreach ((array) config('kiban.access_profiles') as $key => $profile) {
            $roles = array_intersect_key((array) $profile['roles'], $enabled);

            if ($roles === [] || in_array($key, $offered, true)) {
                continue;
            }

            AccessProfile::query()->create([
                'name' => __($profile['name']),
                'description' => __($profile['description']),
                'roles' => $roles,
                'suggestion' => $key,
            ]);
        }
    }
}
