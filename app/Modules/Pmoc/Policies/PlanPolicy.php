<?php

namespace App\Modules\Pmoc\Policies;

use App\Models\User;
use App\Modules\Field\FieldModule;
use App\Modules\ModulePolicy;
use App\Modules\Pmoc\PmocModule;
use App\Modules\Pmoc\Services\PmocAccess;
use App\Tenancy\TenantContext;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Maintenance plans: they need the Campo (customers and work orders); the manager changes, the viewer looks.
 */
class PlanPolicy extends ModulePolicy
{
    protected string $module = PmocModule::KEY;

    public function viewAny(User $user): Response
    {
        $access = parent::viewAny($user);

        if ($access->denied()) {
            return $access;
        }

        if (! app(TenantContext::class)->get()->license()->hasModule(FieldModule::KEY)) {
            return Response::deny(__('pmoc.denied.needs_field'));
        }

        return app(PmocAccess::class)->canView($user) ? Response::allow() : Response::deny(__('pmoc.denied.manager'));
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

    public function deleteAny(User $user): Response
    {
        return $this->manager($user, parent::deleteAny($user));
    }

    protected function manager(User $user, Response $response): Response
    {
        if ($response->denied()) {
            return $response;
        }

        $view = $this->viewAny($user);

        if ($view->denied()) {
            return $view;
        }

        return app(PmocAccess::class)->isManager($user) ? Response::allow() : Response::deny(__('pmoc.denied.manager'));
    }
}
