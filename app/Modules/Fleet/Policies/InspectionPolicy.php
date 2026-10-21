<?php

namespace App\Modules\Fleet\Policies;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Models\Inspection;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Inspections: drivers (their vehicles), managers and mechanics do them; viewers only look.
 * They are the vehicle's history: never changed or deleted.
 */
class InspectionPolicy extends FleetPolicy
{
    public function view(User $user, Model $record): Response
    {
        $access = parent::view($user, $record);

        if ($access->denied()) {
            return $access;
        }

        /** @var Inspection $record */
        $visible = $this->fleetAccess()->visibleVehicleIds($user);

        return $visible === null || in_array($record->vehicle_id, $visible, true)
            ? Response::allow()
            : Response::deny(__('fleet.denied.not_your_vehicle'));
    }

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

    public function update(User $user, Model $record): Response
    {
        return Response::deny(__('fleet.denied.history_locked'));
    }

    public function delete(User $user, Model $record): Response
    {
        return Response::deny(__('fleet.denied.history_locked'));
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny(__('fleet.denied.history_locked'));
    }
}
