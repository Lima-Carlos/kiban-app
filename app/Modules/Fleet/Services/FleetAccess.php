<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Fleet\Models\Driver;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Who the signed-in person is in the Frota:
 *
 * - manager: owner or administrator of the company (in the Kiban account): sees and changes everything;
 * - driver: a member linked to a driver (same e-mail): sees only their vehicles, records fuel and inspections;
 * - viewer: any other member: can look, cannot change.
 */
class FleetAccess
{
    public const MANAGER = 'manager';

    public const DRIVER = 'driver';

    public const VIEWER = 'viewer';

    public function __construct(protected TenantContext $context) {}

    public function role(?User $user = null): string
    {
        $user ??= Auth::user();

        if (! $user instanceof User) {
            return self::VIEWER;
        }

        if ($user->canManageTenant()) {
            return self::MANAGER;
        }

        return $this->driverFor($user) !== null ? self::DRIVER : self::VIEWER;
    }

    public function isManager(?User $user = null): bool
    {
        return $this->role($user) === self::MANAGER;
    }

    public function isDriver(?User $user = null): bool
    {
        return $this->role($user) === self::DRIVER;
    }

    /**
     * The active driver record of this person, linking it by e-mail the first time.
     */
    public function driverFor(?User $user = null): ?Driver
    {
        $user ??= Auth::user();

        if (! $user instanceof User || $this->context->current() === null) {
            return null;
        }

        $driver = Driver::query()->where('user_id', $user->getKey())->first();

        if ($driver === null && filled($user->email)) {
            $driver = Driver::query()->whereNull('user_id')->where('email', mb_strtolower($user->email))->first();
            $driver?->forceFill(['user_id' => $user->getKey()])->save();
        }

        return $driver?->is_active ? $driver : null;
    }

    /**
     * Vehicles the person may see: all for managers and viewers, only their own for drivers.
     *
     * @return array<int, int>|null null = no restriction
     */
    public function visibleVehicleIds(?User $user = null): ?array
    {
        if (! $this->isDriver($user)) {
            return null;
        }

        return $this->driverFor($user)?->vehicles()->pluck('fleet_vehicles.id')->all() ?? [];
    }
}
