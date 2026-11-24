<?php

namespace App\Modules\Pedidos\Services;

use App\Access\AccessManager;
use App\Models\User;
use App\Modules\Pedidos\Enums\PedidosRole;
use App\Modules\Pedidos\Models\Staff;
use App\Modules\Pedidos\PedidosModule;
use App\People\Person;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;

/**
 * Who the signed-in person is in the Pedidos: manager, desk, kitchen, delivery, viewer or none. Owners and
 * administrators are managers; a person marked "works in the kitchen" or "delivers" with a login gets that role.
 */
class PedidosAccess
{
    public function __construct(protected TenantContext $context) {}

    public function role(?User $user = null): PedidosRole
    {
        $user ??= Auth::user();

        if (! $user instanceof User || $this->context->current() === null) {
            return PedidosRole::None;
        }

        return PedidosRole::tryFrom((string) app(AccessManager::class)->role($user, PedidosModule::KEY)) ?? PedidosRole::None;
    }

    public function isManager(?User $user = null): bool
    {
        return $this->role($user) === PedidosRole::Manager;
    }

    public function takesOrders(?User $user = null): bool
    {
        return $this->role($user)->takesOrders();
    }

    public function works(?User $user = null): bool
    {
        return $this->role($user)->works();
    }

    public function delivers(?User $user = null): bool
    {
        return $this->role($user)->delivers();
    }

    public function sees(?User $user = null): bool
    {
        return $this->role($user) !== PedidosRole::None;
    }

    /** The kitchen/delivery record of this login, or null. */
    public function staffFor(?User $user = null): ?Staff
    {
        $user ??= Auth::user();

        if (! $user instanceof User || $this->context->current() === null) {
            return null;
        }

        $person = Person::query()->where('user_id', $user->getKey())->first();

        return $person === null ? null : Staff::query()->where('person_id', $person->getKey())->first();
    }

    /** The person of this login (to fill "who prepared" with one tap), or null. */
    public function personFor(?User $user = null): ?Person
    {
        $user ??= Auth::user();

        return $user instanceof User && $this->context->current() !== null ? Person::query()->where('user_id', $user->getKey())->first() : null;
    }
}
