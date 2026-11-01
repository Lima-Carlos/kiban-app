<?php

namespace App\Modules\Agenda\Policies;

use App\Models\User;
use App\Modules\Agenda\Enums\AgendaRole;
use App\Modules\Agenda\Models\Appointment;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Packages sold: the manager and the front desk sell them; one already used is never deleted.
 */
class ClientPackagePolicy extends AgendaPolicy
{
    protected array $editRoles = [AgendaRole::Manager, AgendaRole::Desk];

    public function delete(User $user, Model $record): Response
    {
        if (Appointment::query()->where('client_package_id', $record->getKey())->exists()) {
            return Response::deny(__('agenda.denied.package_used'));
        }

        return parent::delete($user, $record);
    }
}
