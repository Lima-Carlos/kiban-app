<?php

namespace App\Modules\Field\Policies;

use App\Models\User;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\ClientPackage;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\WorkOrder;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Customers: the manager and the service desk register and change them; the viewer only looks.
 * A customer with work orders is turned off, never deleted (the history stays).
 */
class CustomerPolicy extends FieldPolicy
{
    protected array $editRoles = [FieldRole::Manager, FieldRole::Dispatcher];

    public function delete(User $user, Model $record): Response
    {
        /** @var Customer $record */
        if (WorkOrder::query()->where('customer_id', $record->getKey())->exists()) {
            return Response::deny(__('field.denied.customer_has_orders'));
        }

        // The same customer may have a history in the Agenda (appointments, packages): it stays too.
        if (Appointment::query()->where('customer_id', $record->getKey())->exists() || ClientPackage::query()->where('customer_id', $record->getKey())->exists()) {
            return Response::deny(__('agenda.denied.client_has_history'));
        }

        return parent::delete($user, $record);
    }
}
