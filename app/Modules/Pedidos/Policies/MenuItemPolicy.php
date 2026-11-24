<?php

namespace App\Modules\Pedidos\Policies;

use App\Models\User;
use App\Modules\Pedidos\Enums\PedidosRole;
use App\Modules\Pedidos\Models\OrderLine;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * The menu: the manager changes it; the desk, the kitchen and the viewer look. An item already ordered is turned
 * off ("offered now"), never deleted.
 */
class MenuItemPolicy extends PedidosPolicy
{
    protected array $viewRoles = [PedidosRole::Manager, PedidosRole::Desk, PedidosRole::Kitchen, PedidosRole::Viewer];

    public function delete(User $user, Model $record): Response
    {
        if (OrderLine::query()->where('menu_item_id', $record->getKey())->exists()) {
            return Response::deny(__('pedidos.denied.item_ordered'));
        }

        return parent::delete($user, $record);
    }
}
