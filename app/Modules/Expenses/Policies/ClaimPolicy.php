<?php

namespace App\Modules\Expenses\Policies;

use App\Models\User;
use App\Modules\Expenses\ExpensesModule;
use App\Modules\Expenses\Models\Claim;
use App\Modules\Expenses\Services\ExpenseAccess;
use App\Modules\ModulePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Claims: everyone claims their own and changes it while pending; the manager sees, approves and pays all.
 * The plan limits how many people claim.
 */
class ClaimPolicy extends ModulePolicy
{
    protected string $module = ExpensesModule::KEY;

    public function create(User $user): Response
    {
        $access = parent::create($user);

        if ($access->denied()) {
            return $access;
        }

        $max = $this->license()->limitWithTolerance(ExpensesModule::KEY, ExpensesModule::LIMIT_PEOPLE);
        $people = Claim::query()->distinct()->pluck('user_id');

        if ($max !== null && ! $people->contains($user->getKey()) && $people->count() >= $max) {
            return Response::deny(__('expenses.denied.limit', ['limit' => $this->license()->limit(ExpensesModule::KEY, ExpensesModule::LIMIT_PEOPLE)]));
        }

        return $access;
    }

    public function update(User $user, Model $record): Response
    {
        $write = $this->write();

        if ($write->denied()) {
            return $write;
        }

        if ((int) $record->user_id !== (int) $user->getKey()) {
            return Response::deny(__('expenses.denied.not_yours'));
        }

        return $record->status === Claim::PENDING ? Response::allow() : Response::deny(__('expenses.denied.decided'));
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->update($user, $record);
    }

    /** Approve, refuse and pay: the manager. */
    public function decide(User $user): Response
    {
        $write = $this->write();

        if ($write->denied()) {
            return $write;
        }

        return app(ExpenseAccess::class)->isManager($user) ? Response::allow() : Response::deny(__('expenses.denied.manager'));
    }
}
