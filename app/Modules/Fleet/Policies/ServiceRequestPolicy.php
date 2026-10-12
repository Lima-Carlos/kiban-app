<?php

namespace App\Modules\Fleet\Policies;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Models\ServiceRequest;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Requests for maintenance or wash: drivers ask (for their vehicles) and see their own; managers and
 * mechanics see all and handle them (schedule, reject).
 */
class ServiceRequestPolicy extends WorkPolicy
{
    protected array $viewRoles = [FleetRole::Manager, FleetRole::Mechanic, FleetRole::Driver, FleetRole::Viewer];

    public function view(User $user, Model $record): Response
    {
        $access = parent::view($user, $record);

        if ($access->denied()) {
            return $access;
        }

        /** @var ServiceRequest $record */
        $visible = $this->fleetAccess()->visibleVehicleIds($user);

        return $visible === null || in_array($record->vehicle_id, $visible, true) || $record->requested_by === $user->getKey()
            ? Response::allow()
            : Response::deny(__('fleet.denied.not_your_vehicle'));
    }

    /** Drivers ask too. */
    public function create(User $user): Response
    {
        $write = $this->write();

        if ($write->denied()) {
            return $write;
        }

        return in_array($this->fleetAccess()->fleetRole($user), [FleetRole::Manager, FleetRole::Mechanic, FleetRole::Driver], true)
            ? Response::allow()
            : Response::deny(__('fleet.denied.worker'));
    }
}
