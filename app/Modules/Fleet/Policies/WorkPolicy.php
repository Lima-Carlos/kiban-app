<?php

namespace App\Modules\Fleet\Policies;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Maintenance, washes and appointments: managers and mechanics record and change them; viewers only look.
 * Records are not deleted (they are the vehicle's history).
 */
abstract class WorkPolicy extends FleetPolicy
{
    protected array $viewRoles = [FleetRole::Manager, FleetRole::Mechanic, FleetRole::Viewer];

    public function create(User $user): Response
    {
        return $this->workerOnly($user, fn (): Response => $this->write());
    }

    public function update(User $user, Model $record): Response
    {
        return $this->workerOnly($user, fn (): Response => $this->write());
    }

    public function delete(User $user, Model $record): Response
    {
        return Response::deny(__('fleet.denied.history_locked'));
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny(__('fleet.denied.history_locked'));
    }

    /**
     * @param  callable(): Response  $next
     */
    protected function workerOnly(User $user, callable $next): Response
    {
        $response = $next();

        if ($response->denied()) {
            return $response;
        }

        return in_array($this->fleetAccess()->fleetRole($user), [FleetRole::Manager, FleetRole::Mechanic], true)
            ? Response::allow()
            : Response::deny(__('fleet.denied.worker'));
    }
}
