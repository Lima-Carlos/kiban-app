<?php

namespace App\Modules\Pedidos;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Module;
use App\Modules\Pedidos\Enums\PedidosRole;
use App\Modules\Pedidos\Filament\BoardPage;
use App\Modules\Pedidos\Filament\Clients\ClientResource;
use App\Modules\Pedidos\Filament\ClientsMapPage;
use App\Modules\Pedidos\Filament\DeliveriesPage;
use App\Modules\Pedidos\Filament\MenuItems\MenuItemResource;
use App\Modules\Pedidos\Filament\MissingClientsPage;
use App\Modules\Pedidos\Filament\OrderDeskPage;
use App\Modules\Pedidos\Filament\Orders\OrderResource;
use App\Modules\Pedidos\Filament\PedidosSettingsPage;
use App\Modules\Pedidos\Filament\TodayWidget;
use App\Modules\Pedidos\Models\Client;
use App\Modules\Pedidos\Models\MenuItem;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Models\PedidosSetting;
use App\Modules\Pedidos\Models\Staff;
use App\Modules\Pedidos\Policies\ClientPolicy;
use App\Modules\Pedidos\Policies\MenuItemPolicy;
use App\Modules\Pedidos\Policies\OrderPolicy;
use App\Modules\Pedidos\Services\PedidosAccess;
use App\Modules\Pedidos\Services\PedidosPeople;
use App\Modules\Pedidos\Services\PedidosReports;
use App\Modules\Step;
use App\People\Person;

/**
 * Kiban Core Pedidos (working name; "Orders" in English): orders by phone and WhatsApp for small food businesses
 * (pizza, burger, sushi, lunch boxes...), with the focus on not losing customers. The quick order screen (find the
 * customer by the phone, "the usual"), the board of the kitchen, the delivery runs in the suggested visiting order and
 * each customer's rhythm (green, yellow, red) with the map and the "come back" WhatsApp message.
 * Customers are the company's customers (the same register as the Campo and the Agenda). Product code in the Core:
 * "pedidos". Ready, not sold yet (no price, not in the Core's catalog).
 */
class PedidosModule extends Module
{
    public const KEY = 'pedidos';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('pedidos.module');
    }

    public function icon(): string
    {
        return 'heroicon-o-shopping-bag';
    }

    public function steps(): array
    {
        return [
            new Step(
                key: 'shop',
                labelKey: 'pedidos.steps.shop',
                descriptionKey: 'pedidos.steps.shop_help',
                done: fn (Tenant $tenant): bool => PedidosSetting::query()->whereNotNull('configured_at')->exists(),
                url: fn (): string => PedidosSettingsPage::getUrl(),
            ),
            new Step(
                key: 'menu',
                labelKey: 'pedidos.steps.menu',
                descriptionKey: 'pedidos.steps.menu_help',
                done: fn (Tenant $tenant): bool => MenuItem::query()->active()->exists(),
                url: fn (): string => MenuItemResource::getUrl('index'),
            ),
            new Step(
                key: 'order',
                labelKey: 'pedidos.steps.order',
                descriptionKey: 'pedidos.steps.order_help',
                requires: ['menu'],
                done: fn (Tenant $tenant): bool => Order::query()->exists(),
                url: fn (): string => OrderDeskPage::getUrl(),
            ),
        ];
    }

    public function resources(): array
    {
        return [OrderResource::class, ClientResource::class, MenuItemResource::class];
    }

    public function pages(): array
    {
        return [OrderDeskPage::class, BoardPage::class, DeliveriesPage::class, MissingClientsPage::class, ClientsMapPage::class, PedidosSettingsPage::class];
    }

    public function homeWidgets(User $user): array
    {
        return app(PedidosAccess::class)->sees($user) ? [TodayWidget::class] : [];
    }

    public function attention(User $user): array
    {
        return app(PedidosReports::class)->attention($user);
    }

    public function showsSetupTo(User $user): bool
    {
        return app(PedidosAccess::class)->isManager($user);
    }

    public function roles(): array
    {
        return collect(PedidosRole::assignable())->mapWithKeys(fn (PedidosRole $role): array => [$role->value => $role->getLabel()])->all();
    }

    /** A person with a login marked "works in the kitchen" or "delivers" gets that role by itself. */
    public function implicitRole(User $user): ?string
    {
        $staff = app(PedidosAccess::class)->staffFor($user);

        return match (true) {
            (bool) $staff?->cooks => PedidosRole::Kitchen->value,
            (bool) $staff?->delivers => PedidosRole::Delivery->value,
            default => null,
        };
    }

    public function managesPeople(User $user): bool
    {
        return app(PedidosAccess::class)->isManager($user);
    }

    public function personFields(): array
    {
        return app(PedidosPeople::class)->fields();
    }

    public function personData(Person $person): array
    {
        return app(PedidosPeople::class)->data($person);
    }

    public function savePerson(Person $person, array $data): void
    {
        app(PedidosPeople::class)->save($person, $data);
    }

    public function removePerson(Person $person): void
    {
        app(PedidosPeople::class)->remove($person);
    }

    public function personRoleLabel(Person $person): ?string
    {
        return app(PedidosPeople::class)->roleLabel($person);
    }

    public function exportPerson(Person $person): array
    {
        $staff = Staff::query()->where('person_id', $person->getKey())->first();

        return $staff === null ? [] : [
            'cooks' => $staff->cooks,
            'delivers' => $staff->delivers,
            'orders_prepared' => Order::query()->where('prepared_by', $person->getKey())->count(),
            'orders_delivered' => Order::query()->where('delivered_by', $person->getKey())->count(),
        ];
    }

    public function policies(): array
    {
        return [
            MenuItem::class => MenuItemPolicy::class,
            Client::class => ClientPolicy::class,
            Order::class => OrderPolicy::class,
        ];
    }
}
