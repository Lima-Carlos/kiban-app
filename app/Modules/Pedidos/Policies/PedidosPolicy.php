<?php

namespace App\Modules\Pedidos\Policies;

use App\Models\User;
use App\Modules\ModulePolicy;
use App\Modules\Pedidos\Enums\PedidosRole;
use App\Modules\Pedidos\PedidosModule;
use App\Modules\Pedidos\Services\PedidosAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Base rules of the Pedidos screens: who may see them (viewRoles) and who may change them (editRoles).
 */
abstract class PedidosPolicy extends ModulePolicy
{
    protected string $module = PedidosModule::KEY;

    /** @var array<int, PedidosRole> */
    protected array $viewRoles = [PedidosRole::Manager, PedidosRole::Desk, PedidosRole::Viewer];

    /** @var array<int, PedidosRole> */
    protected array $editRoles = [PedidosRole::Manager];

    public function viewAny(User $user): Response
    {
        $access = parent::viewAny($user);

        if ($access->allowed() && ! in_array(app(PedidosAccess::class)->role($user), $this->viewRoles, true)) {
            return Response::deny(__('pedidos.denied.no_screen'));
        }

        return $access;
    }

    public function create(User $user): Response
    {
        return $this->editors($user, fn (): Response => parent::create($user));
    }

    public function update(User $user, Model $record): Response
    {
        return $this->editors($user, fn (): Response => parent::update($user, $record));
    }

    public function delete(User $user, Model $record): Response
    {
        return $this->editors($user, fn (): Response => parent::delete($user, $record));
    }

    public function deleteAny(User $user): Response
    {
        return $this->editors($user, fn (): Response => parent::deleteAny($user));
    }

    /**
     * @param  callable(): Response  $next
     */
    protected function editors(User $user, callable $next): Response
    {
        $response = $next();

        if ($response->allowed() && ! in_array(app(PedidosAccess::class)->role($user), $this->editRoles, true)) {
            return Response::deny(__('pedidos.denied.manager'));
        }

        return $response;
    }
}
