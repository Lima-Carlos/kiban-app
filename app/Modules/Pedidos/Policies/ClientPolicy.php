<?php

namespace App\Modules\Pedidos\Policies;

use App\Models\User;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Pedidos\Enums\PedidosRole;
use App\Modules\Pedidos\Models\Order;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Customers: the manager and the desk register and change them; the viewer only looks. A customer with history
 * (orders here, or Campo/Agenda records) is turned off, never deleted.
 */
class ClientPolicy extends PedidosPolicy
{
    protected array $editRoles = [PedidosRole::Manager, PedidosRole::Desk];

    public function delete(User $user, Model $record): Response
    {
        $id = $record->getKey();

        if (Order::query()->where('customer_id', $id)->exists() || WorkOrder::query()->where('customer_id', $id)->exists()
            || Quote::query()->where('customer_id', $id)->exists() || Appointment::query()->where('customer_id', $id)->exists()) {
            return Response::deny(__('pedidos.denied.client_has_history'));
        }

        return parent::delete($user, $record);
    }
}
