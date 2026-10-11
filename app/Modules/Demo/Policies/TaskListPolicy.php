<?php

namespace App\Modules\Demo\Policies;

use App\Models\User;
use App\Modules\ModulePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

class TaskListPolicy extends ModulePolicy
{
    protected string $module = 'demo';

    /** Removing a list removes its tasks: only the company owner or administrators. */
    public function delete(User $user, Model $record): Response
    {
        $write = parent::delete($user, $record);

        if ($write->denied()) {
            return $write;
        }

        return $user->canManageTenant() ? Response::allow() : Response::deny(__('app.denied.manager'));
    }

    public function deleteAny(User $user): Response
    {
        $write = parent::deleteAny($user);

        if ($write->denied()) {
            return $write;
        }

        return $user->canManageTenant() ? Response::allow() : Response::deny(__('app.denied.manager'));
    }
}
