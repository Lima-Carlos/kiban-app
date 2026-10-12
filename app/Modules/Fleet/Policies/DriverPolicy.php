<?php

namespace App\Modules\Fleet\Policies;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Models\Driver;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class DriverPolicy extends FleetPolicy
{
    protected array $viewRoles = [FleetRole::Manager, FleetRole::Viewer];

    /** With fuel-ups it stays in the history: deactivate instead of deleting. */
    public function delete(User $user, Model $record): Response
    {
        $response = parent::delete($user, $record);

        if ($response->denied()) {
            return $response;
        }

        /** @var Driver $record */
        return $record->fuelUps()->exists() ? Response::deny(__('fleet.denied.has_history')) : Response::allow();
    }
}
