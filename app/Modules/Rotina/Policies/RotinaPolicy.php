<?php

namespace App\Modules\Rotina\Policies;

use App\Models\User;
use App\Modules\ModulePolicy;
use App\Modules\Rotina\RotinaModule;
use App\Modules\Rotina\Services\RotinaAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Tasks and hours: everyone writes their own; the manager changes any. Projects: only the manager.
 */
class RotinaPolicy extends ModulePolicy
{
    protected string $module = RotinaModule::KEY;

    public function update(User $user, Model $record): Response
    {
        $write = $this->write();

        if ($write->denied()) {
            return $write;
        }

        return $this->mine($user, $record) ? Response::allow() : Response::deny(__('rotina.denied.not_yours'));
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->update($user, $record);
    }

    protected function mine(User $user, Model $record): bool
    {
        return app(RotinaAccess::class)->isManager($user) || (int) $record->user_id === (int) $user->getKey();
    }
}
