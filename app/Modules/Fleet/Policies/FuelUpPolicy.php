<?php

namespace App\Modules\Fleet\Policies;

use App\Models\User;
use App\Modules\Fleet\Models\FuelUp;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Fuel-ups: everyone in the Frota sees the list (drivers only those of their vehicles or made by them).
 * Typing a fuel-up directly is for managers; generating codes and finishing have their own checks
 * (FuelService). Fuel-ups are never edited or deleted: an open one can be canceled with a reason.
 */
class FuelUpPolicy extends FleetPolicy
{
    public function view(User $user, Model $record): Response
    {
        $access = parent::view($user, $record);

        if ($access->denied()) {
            return $access;
        }

        /** @var FuelUp $record */
        $visible = $this->fleetAccess()->visibleVehicleIds($user);

        return $visible === null || in_array($record->vehicle_id, $visible, true) || $record->created_by === $user->getKey()
            ? Response::allow()
            : Response::deny(__('fleet.denied.not_your_vehicle'));
    }

    public function update(User $user, Model $record): Response
    {
        return Response::deny(__('fleet.denied.fuel_up_locked'));
    }

    public function delete(User $user, Model $record): Response
    {
        return Response::deny(__('fleet.denied.fuel_up_locked'));
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny(__('fleet.denied.fuel_up_locked'));
    }
}
