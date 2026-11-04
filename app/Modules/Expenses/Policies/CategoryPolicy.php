<?php

namespace App\Modules\Expenses\Policies;

use App\Models\User;
use App\Modules\Expenses\ExpensesModule;
use App\Modules\Expenses\Services\ExpenseAccess;
use App\Modules\ModulePolicy;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/** Categories: only the manager sees and changes them. */
class CategoryPolicy extends ModulePolicy
{
    protected string $module = ExpensesModule::KEY;

    public function viewAny(User $user): Response
    {
        return $this->manager($user, parent::viewAny($user));
    }

    public function create(User $user): Response
    {
        return $this->manager($user, parent::create($user));
    }

    public function update(User $user, Model $record): Response
    {
        return $this->manager($user, parent::update($user, $record));
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->manager($user, parent::delete($user, $record));
    }

    protected function manager(User $user, Response $response): Response
    {
        if ($response->denied()) {
            return $response;
        }

        return app(ExpenseAccess::class)->isManager($user) ? Response::allow() : Response::deny(__('expenses.denied.manager'));
    }
}
