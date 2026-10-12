<?php

namespace App\Modules\Fleet\Policies;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Models\Workshop;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class WorkshopPolicy extends FleetPolicy
{
    protected array $viewRoles = [FleetRole::Manager, FleetRole::Mechanic, FleetRole::Viewer];

    /** With maintenances it stays in the history: deactivate instead. */
    public function delete(User $user, Model $record): Response
    {
        $response = parent::delete($user, $record);

        if ($response->denied()) {
            return $response;
        }

        /** @var Workshop $record */
        return $record->maintenances()->exists() ? Response::deny(__('fleet.denied.has_history')) : Response::allow();
    }
}
