<?php

namespace App\Modules\Expenses\Services;

use App\Access\AccessManager;
use App\Models\User;
use App\Modules\Expenses\ExpensesModule;

/** Who does what: the manager approves and pays everyone's claims; everyone else claims their own. */
class ExpenseAccess
{
    public function isManager(?User $user = null): bool
    {
        $user ??= auth()->user();

        return $user !== null && app(AccessManager::class)->role($user, ExpensesModule::KEY) === ExpensesModule::MANAGER;
    }

    /** @return \Illuminate\Support\Collection<int, User> */
    public function managers(): \Illuminate\Support\Collection
    {
        return app(\App\Tenancy\TenantContext::class)->get()->users()->get()->filter(fn (User $user): bool => $this->isManager($user))->values()->toBase();
    }
}
