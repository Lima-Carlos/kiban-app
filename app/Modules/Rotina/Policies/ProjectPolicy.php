<?php

namespace App\Modules\Rotina\Policies;

use App\Models\User;
use App\Modules\Rotina\Services\RotinaAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Projects: everyone sees them (to choose one), only the manager changes them.
 */
class ProjectPolicy extends RotinaPolicy
{
    public function create(User $user): Response
    {
        return $this->manager(parent::create($user));
    }

    public function update(User $user, Model $record): Response
    {
        return $this->manager($this->write());
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->manager($this->write());
    }

    public function deleteAny(User $user): Response
    {
        return $this->manager($this->write());
    }

    protected function manager(Response $response): Response
    {
        if ($response->denied()) {
            return $response;
        }

        return app(RotinaAccess::class)->isManager() ? Response::allow() : Response::deny(__('rotina.denied.manager'));
    }
}
