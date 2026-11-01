<?php

namespace App\Modules\Agenda\Policies;

use App\Models\User;
use App\Modules\Agenda\Models\AppointmentLine;
use App\Modules\Agenda\Models\Package;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Services: the manager keeps them; the desk and the viewer see them. A service already used is turned off, never deleted.
 */
class ServicePolicy extends AgendaPolicy
{
    protected array $requiresSteps = ['business'];

    public function delete(User $user, Model $record): Response
    {
        if (AppointmentLine::query()->where('service_id', $record->getKey())->exists() || Package::query()->where('service_id', $record->getKey())->exists()) {
            return Response::deny(__('agenda.denied.service_used'));
        }

        return parent::delete($user, $record);
    }
}
