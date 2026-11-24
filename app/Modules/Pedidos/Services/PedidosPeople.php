<?php

namespace App\Modules\Pedidos\Services;

use App\Access\AccessManager;
use App\Models\ModuleAccess;
use App\Modules\Pedidos\Enums\PedidosRole;
use App\Modules\Pedidos\Models\Staff;
use App\Modules\Pedidos\PedidosModule;
use App\People\PeopleForm;
use App\People\Person;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Illuminate\Support\Collection;

/**
 * The Pedidos part of the "People" screen: works in the kitchen, delivers (with or without a login: they are the
 * one-tap buttons "who prepared" and "who delivered") and, for people with a login, their role.
 */
class PedidosPeople
{
    public function __construct(protected PedidosAccess $access) {}

    /**
     * @return array<int, mixed>
     */
    public function fields(): array
    {
        return [
            Toggle::make('cooks')
                ->label(__('pedidos.people.cooks'))
                ->helperText(__('pedidos.people.cooks_help')),
            Toggle::make('delivers')
                ->label(__('pedidos.people.delivers'))
                ->helperText(__('pedidos.people.delivers_help')),
            Select::make('role')
                ->label(__('pedidos.people.role'))
                ->helperText(__('pedidos.people.role_help'))
                ->options(collect([PedidosRole::None, ...PedidosRole::assignable()])->mapWithKeys(fn (PedidosRole $role): array => [$role->value => $role->getLabel()])->all())
                ->default(PedidosRole::None->value)
                ->visible(PeopleForm::hasLogin()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(Person $person): array
    {
        $staff = Staff::query()->where('person_id', $person->getKey())->first();
        $role = $person->user ? ModuleAccess::query()->where('user_id', $person->user_id)->where('module', PedidosModule::KEY)->value('role') : null;

        return [
            'cooks' => (bool) $staff?->cooks,
            'delivers' => (bool) $staff?->delivers,
            'role' => PedidosRole::tryFrom((string) $role)?->value ?? PedidosRole::None->value,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(Person $person, array $data): void
    {
        $cooks = (bool) ($data['cooks'] ?? false);
        $delivers = (bool) ($data['delivers'] ?? false);
        $staff = Staff::query()->where('person_id', $person->getKey())->first();

        if ($staff !== null || $cooks || $delivers) {
            ($staff ?? new Staff(['person_id' => $person->getKey()]))->fill(['cooks' => $cooks, 'delivers' => $delivers])->save();
        }

        $user = $person->user;

        if ($user !== null && ! $user->canManageTenant() && array_key_exists('role', $data)) {
            $role = PedidosRole::tryFrom((string) $data['role']) ?? PedidosRole::None;
            app(AccessManager::class)->set($user, PedidosModule::KEY, $role === PedidosRole::None ? null : $role->value);
        }
    }

    public function remove(Person $person): void
    {
        Staff::query()->where('person_id', $person->getKey())->update(['cooks' => false, 'delivers' => false]);

        if ($person->user !== null) {
            app(AccessManager::class)->set($person->user, PedidosModule::KEY, null);
        }
    }

    public function roleLabel(Person $person): ?string
    {
        $role = $person->user ? $this->access->role($person->user) : PedidosRole::None;

        if ($role !== PedidosRole::None) {
            return $role->getLabel();
        }

        $staff = Staff::query()->where('person_id', $person->getKey())->first();

        return match (true) {
            (bool) $staff?->cooks => PedidosRole::Kitchen->getLabel(),
            (bool) $staff?->delivers => PedidosRole::Delivery->getLabel(),
            default => null,
        };
    }

    /**
     * The one-tap buttons: active people marked for the kitchen (or delivering). When nobody is marked yet, every
     * active person of the company, so the button works from day one.
     *
     * @return Collection<int, Person>
     */
    public function team(string $kind): Collection
    {
        $ids = Staff::query()->where($kind === 'delivers' ? 'delivers' : 'cooks', true)->pluck('person_id');
        $people = Person::query()->active()->when($ids->isNotEmpty(), fn ($query) => $query->whereKey($ids->all()))->orderBy('name')->get();

        return $people->toBase();
    }
}
