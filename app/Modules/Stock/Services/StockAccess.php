<?php

namespace App\Modules\Stock\Services;

use App\Access\AccessManager;
use App\Models\User;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\StockModule;

/**
 * Who does what in the stock: the manager moves everything; the viewer looks; a technician sees only the stock
 * they carry ("My stock").
 */
class StockAccess
{
    public function role(?User $user = null): ?string
    {
        $user ??= auth()->user();

        return $user === null ? null : app(AccessManager::class)->role($user, StockModule::KEY);
    }

    public function isManager(?User $user = null): bool
    {
        return $this->role($user) === StockModule::MANAGER;
    }

    public function seesEverything(?User $user = null): bool
    {
        return in_array($this->role($user), [StockModule::MANAGER, StockModule::VIEWER], true);
    }

    /** The place of the technician (the stock they carry), if any. */
    public function ownLocation(?User $user = null): ?Location
    {
        $user ??= auth()->user();

        return $user === null ? null : Location::query()->active()->where('kind', Location::TECHNICIAN)->where('user_id', $user->getKey())->first();
    }
}
