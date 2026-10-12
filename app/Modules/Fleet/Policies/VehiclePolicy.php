<?php

namespace App\Modules\Fleet\Policies;

use App\Models\User;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\VehicleService;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class VehiclePolicy extends FleetPolicy
{
    protected ?string $limit = FleetModule::LIMIT_VEHICLES;

    protected ?string $model = Vehicle::class;

    /** Drivers see only the vehicles assigned to them. */
    public function view(User $user, Model $record): Response
    {
        $access = parent::view($user, $record);

        if ($access->denied()) {
            return $access;
        }

        $visible = $this->fleetAccess()->visibleVehicleIds($user);

        return $visible === null || in_array($record->getKey(), $visible, true)
            ? Response::allow()
            : Response::deny(__('fleet.denied.not_your_vehicle'));
    }

    /** Deactivated vehicles do not count in the plan. */
    protected function countForLimit(): int
    {
        return Vehicle::query()->countingInLimit()->count();
    }

    /** A vehicle with history is never deleted, only deactivated. */
    public function delete(User $user, Model $record): Response
    {
        $response = parent::delete($user, $record);

        if ($response->denied()) {
            return $response;
        }

        /** @var Vehicle $record */
        return app(VehicleService::class)->canDelete($record)
            ? Response::allow()
            : Response::deny(__('fleet.denied.vehicle_has_history'));
    }

    public function deleteAny(User $user): Response
    {
        // Bulk delete is not offered: each vehicle is checked one by one.
        return Response::deny(__('fleet.denied.vehicle_has_history'));
    }
}
