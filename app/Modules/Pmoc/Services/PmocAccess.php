<?php

namespace App\Modules\Pmoc\Services;

use App\Access\AccessManager;
use App\Models\User;
use App\Modules\Pmoc\PmocModule;

/**
 * Who does what in the PMOC: the manager keeps the plans (owners, administrators, the Campo office); the viewer
 * only looks.
 */
class PmocAccess
{
    public function role(?User $user = null): ?string
    {
        $user ??= auth()->user();

        return $user === null ? null : app(AccessManager::class)->role($user, PmocModule::KEY);
    }

    public function isManager(?User $user = null): bool
    {
        return $this->role($user) === PmocModule::MANAGER;
    }

    public function canView(?User $user = null): bool
    {
        return in_array($this->role($user), [PmocModule::MANAGER, PmocModule::VIEWER], true);
    }
}
