<?php

namespace App\Modules\Agenda\Policies;

use App\Models\User;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Models\Professional;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Professionals: the manager sets hours, services, commission and time off. They are added (and turned off) in the
 * "People" screen; the plan limits the active ones. Never deleted (the history stays).
 */
class ProfessionalPolicy extends AgendaPolicy
{
    protected ?string $limit = AgendaModule::LIMIT_PROFESSIONALS;

    protected ?string $model = Professional::class;

    protected function countForLimit(): int
    {
        return Professional::query()->active()->count();
    }

    public function delete(User $user, Model $record): Response
    {
        return Response::deny(__('agenda.denied.professional_delete'));
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny(__('agenda.denied.professional_delete'));
    }
}
