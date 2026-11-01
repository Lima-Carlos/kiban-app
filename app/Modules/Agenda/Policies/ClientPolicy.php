<?php

namespace App\Modules\Agenda\Policies;

use App\Models\User;
use App\Modules\Agenda\Enums\AgendaRole;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\ClientPackage;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\WorkOrder;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Clients (tutors) and their pets: the manager and the front desk register and change them; professionals and the
 * viewer only look. A client with history (appointments, packages, Campo orders or quotes) is turned off, never deleted.
 */
class ClientPolicy extends AgendaPolicy
{
    protected array $viewRoles = [AgendaRole::Manager, AgendaRole::Desk, AgendaRole::Professional, AgendaRole::Viewer];

    protected array $editRoles = [AgendaRole::Manager, AgendaRole::Desk];

    public function delete(User $user, Model $record): Response
    {
        $id = $record->getKey();

        if (Appointment::query()->where('customer_id', $id)->exists() || ClientPackage::query()->where('customer_id', $id)->exists()
            || WorkOrder::query()->where('customer_id', $id)->exists() || Quote::query()->where('customer_id', $id)->exists()) {
            return Response::deny(__('agenda.denied.client_has_history'));
        }

        return parent::delete($user, $record);
    }
}
