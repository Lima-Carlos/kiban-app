<?php

namespace App\Modules\Fleet\Policies;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Models\ChecklistItem;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The inspection checklist: only managers see and change it. Ready items are turned off, not deleted.
 */
class ChecklistItemPolicy extends FleetPolicy
{
    protected array $viewRoles = [FleetRole::Manager];

    public function delete(User $user, Model $record): Response
    {
        /** @var ChecklistItem $record */
        if (filled($record->key)) {
            return Response::deny(__('fleet.denied.ready_item'));
        }

        return parent::delete($user, $record);
    }
}
