<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\FleetMember;
use App\Modules\Fleet\Models\Vehicle;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Who the signed-in person is in the Frota (FleetRole), in this order:
 *
 * 1. owner or administrator of the company (Kiban account) → manager;
 * 2. role given in "Fleet team" (manager or mechanic);
 * 3. linked to an active driver record (same e-mail) → driver;
 * 4. anyone else (or "viewer" in the team) → viewer: only looks.
 */
class FleetAccess
{
    public const MANAGER = 'manager';

    public const DRIVER = 'driver';

    public const MECHANIC = 'mechanic';

    public const VIEWER = 'viewer';

    public function __construct(protected TenantContext $context) {}

    public function role(?User $user = null): string
    {
        return $this->fleetRole($user)->value;
    }

    public function fleetRole(?User $user = null): FleetRole
    {
        $user ??= Auth::user();

        if (! $user instanceof User || $this->context->current() === null) {
            return FleetRole::Viewer;
        }

        if ($user->canManageTenant()) {
            return FleetRole::Manager;
        }

        $assigned = FleetMember::query()->where('user_id', $user->getKey())->value('role');
        $assigned = $assigned instanceof FleetRole ? $assigned : FleetRole::tryFrom((string) $assigned);

        if ($assigned === FleetRole::Manager || $assigned === FleetRole::Mechanic) {
            return $assigned;
        }

        return $this->driverFor($user) !== null ? FleetRole::Driver : FleetRole::Viewer;
    }

    public function isManager(?User $user = null): bool
    {
        return $this->fleetRole($user) === FleetRole::Manager;
    }

    public function isDriver(?User $user = null): bool
    {
        return $this->fleetRole($user) === FleetRole::Driver;
    }

    public function isMechanic(?User $user = null): bool
    {
        return $this->fleetRole($user) === FleetRole::Mechanic;
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
     * Vehicles the person may see: all for managers, mechanics and viewers, only their own for drivers.
     *
     * @return array<int, int>|null null = no restriction
     */
    public function visibleVehicleIds(?User $user = null): ?array
    {
        if (! $this->isDriver($user)) {
            return null;
        }

        $driver = $this->driverFor($user);

        // Backup driver: any active vehicle.
        if ($driver?->any_vehicle) {
            return Vehicle::query()->countingInLimit()->pluck('id')->all();
        }

        return $driver?->vehicles()->pluck('fleet_vehicles.id')->all() ?? [];
    }
}
