<?php

namespace App\Modules\Rotina\Services;

use App\Access\AccessManager;
use App\Models\User;
use App\Modules\Rotina\RotinaModule;

/**
 * Who does what in the Rotina: the manager (owners, administrators and who was given the role) sees everyone's
 * tasks and hours and keeps the projects; a member sees and changes only their own.
 */
class RotinaAccess
{
    public function isManager(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && ($user->canManageTenant() || app(AccessManager::class)->role($user, RotinaModule::KEY) === RotinaModule::MANAGER);
    }
}
