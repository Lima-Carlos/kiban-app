<?php

namespace App\Modules\Agenda\Policies;

use App\Models\User;
use App\Modules\Agenda\Enums\AgendaRole;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Services\AgendaAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Appointments: the manager and the front desk book, move and cancel; a professional sees and moves along only
 * their own (arrived, in service, ready); the viewer only looks. Appointments are never deleted (canceled instead).
 * Booking waits for a service and a professional.
 */
class AppointmentPolicy extends AgendaPolicy
{
    protected array $viewRoles = [AgendaRole::Manager, AgendaRole::Desk, AgendaRole::Professional, AgendaRole::Viewer];

    protected array $editRoles = [AgendaRole::Manager, AgendaRole::Desk];

    protected array $requiresSteps = ['services', 'professionals'];

    public function view(User $user, Model $record): Response
    {
        $access = $this->viewAny($user);

        /** @var Appointment $record */
        if ($access->allowed() && app(AgendaAccess::class)->role($user) === AgendaRole::Professional
            && (int) app(AgendaAccess::class)->professionalFor($user)?->getKey() !== (int) $record->professional_id) {
            return Response::deny(__('agenda.denied.not_yours'));
        }

        return $access;
    }

    /** Move along the way (arrived, in service, ready, delivered): the desk, or the professional of the appointment. */
    public function work(User $user, Appointment $record): Response
    {
        $write = $this->write();

        if ($write->denied()) {
            return $write;
        }

        $access = app(AgendaAccess::class);

        if ($access->runsTheDesk($user)) {
            return Response::allow();
        }

        return $access->role($user) === AgendaRole::Professional && (int) $access->professionalFor($user)?->getKey() === (int) $record->professional_id
            ? Response::allow()
            : Response::deny(__('agenda.denied.manager'));
    }

    public function delete(User $user, Model $record): Response
    {
        return Response::deny(__('agenda.denied.appointment_delete'));
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny(__('agenda.denied.appointment_delete'));
    }
}
