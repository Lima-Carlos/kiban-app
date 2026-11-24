<?php

namespace App\Modules\Pedidos\Policies;

use App\Models\User;
use App\Modules\Pedidos\Enums\PedidosRole;
use App\Modules\Pedidos\Services\PedidosAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Orders: everyone of the Pedidos sees them; the manager and the desk take and cancel them; the kitchen moves them
 * (preparing, ready) and the delivery people deliver them ("work"). Orders are never deleted.
 */
class OrderPolicy extends PedidosPolicy
{
    protected array $viewRoles = [PedidosRole::Manager, PedidosRole::Desk, PedidosRole::Kitchen, PedidosRole::Delivery, PedidosRole::Viewer];

    protected array $editRoles = [PedidosRole::Manager, PedidosRole::Desk];

    protected array $requiresSteps = ['menu'];

    public function delete(User $user, Model $record): Response
    {
        return Response::deny(__('pedidos.denied.never_deleted'));
    }

    public function deleteAny(User $user): Response
    {
        return Response::deny(__('pedidos.denied.never_deleted'));
    }

    /** Moves the order along (kitchen and delivery people too). */
    public function work(User $user, ?Model $record = null): Response
    {
        $write = $this->write();

        if ($write->denied()) {
            return $write;
        }

        $role = app(PedidosAccess::class)->role($user);

        return $role->works() || $role->delivers() ? Response::allow() : Response::deny(__('pedidos.denied.no_screen'));
    }
}
