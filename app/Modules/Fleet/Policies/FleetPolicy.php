<?php

namespace App\Modules\Fleet\Policies;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\ModulePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base rules of the Frota screens: only managers change the registrations (vehicles, drivers, stations,
 * documents). Drivers and viewers only look. Fuel-ups and inspections (by drivers) have their own rules.
 */
abstract class FleetPolicy extends ModulePolicy
{
    protected string $module = FleetModule::KEY;

    /**
     * Roles that see this screen at all (e.g. drivers do not see the list of drivers).
     *
     * @var array<int, FleetRole>
     */
    protected array $viewRoles = [FleetRole::Manager, FleetRole::Mechanic, FleetRole::Driver, FleetRole::Viewer];

    public function viewAny(User $user): Response
    {
        $access = parent::viewAny($user);

        if ($access->allowed() && ! in_array($this->fleetAccess()->fleetRole($user), $this->viewRoles, true)) {
            return Response::deny(__('fleet.denied.manager'));
        }

        return $access;
    }

    public function create(User $user): Response
    {
        return $this->managerOnly($user, fn (): Response => parent::create($user));
    }

    public function update(User $user, Model $record): Response
    {
        return $this->managerOnly($user, fn (): Response => parent::update($user, $record));
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->managerOnly($user, fn (): Response => parent::delete($user, $record));
    }

    public function deleteAny(User $user): Response
    {
        return $this->managerOnly($user, fn (): Response => parent::deleteAny($user));
    }

    /**
     * @param  callable(): Response  $next
     */
    protected function managerOnly(User $user, callable $next): Response
    {
        $response = $next();

        if ($response->denied()) {
            return $response;
        }

        return $this->fleetAccess()->isManager($user) ? Response::allow() : Response::deny(__('fleet.denied.manager'));
    }

    protected function fleetAccess(): FleetAccess
    {
        return app(FleetAccess::class);
    }
}
