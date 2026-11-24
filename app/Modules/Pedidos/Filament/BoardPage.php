<?php

namespace App\Modules\Pedidos\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Pedidos\Enums\OrderStatus;
use App\Modules\Pedidos\Filament\Orders\OrderResource;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\PedidosModule;
use App\Modules\Pedidos\Services\OrderService;
use App\Modules\Pedidos\Services\PedidosAccess;
use App\Modules\Pedidos\Services\PedidosException;
use App\Modules\Pedidos\Services\PedidosPeople;
use App\People\Person;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * The board of the orders now (kitchen and desk): columns New, Preparing, Ready and On the way. One tap moves an
 * order: "start" with the name of who prepares, "ready", "picked up" for pickups. Ready deliveries leave from the
 * deliveries screen. Refreshes by itself.
 */
class BoardPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = PedidosModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFire;

    protected static ?int $navigationSort = 15;

    protected static ?string $slug = 'pedidos/agora';

    protected string $view = 'pedidos.board';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->hasModule(PedidosModule::KEY) && app(PedidosAccess::class)->sees();
    }

    public static function getNavigationLabel(): string
    {
        return __('pedidos.board.page_title');
    }

    public function getTitle(): string
    {
        return __('pedidos.board.page_title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);
    }

    /**
     * Open orders by status, oldest first.
     *
     * @return Collection<string, Collection<int, Order>>
     */
    public function columns(): Collection
    {
        $orders = Order::query()->with(['client', 'lines', 'preparer', 'deliverer', 'run.driver'])->open()->orderBy('created_at')->get();

        return collect([OrderStatus::New, OrderStatus::Preparing, OrderStatus::Ready, OrderStatus::Out])
            ->mapWithKeys(fn (OrderStatus $status): array => [$status->value => $orders->where('status', $status)->values()]);
    }

    /**
     * The kitchen's one-tap buttons.
     *
     * @return Collection<int, Person>
     */
    public function cooks(): Collection
    {
        return app(PedidosPeople::class)->team('cooks');
    }

    public function canWork(): bool
    {
        return OrderResource::can('work');
    }

    public function start(int $id, ?int $personId = null): void
    {
        $this->change($id, fn (Order $order) => app(OrderService::class)->start($order, $personId ?? app(PedidosAccess::class)->personFor()?->getKey()));
    }

    public function ready(int $id): void
    {
        $this->change($id, fn (Order $order) => app(OrderService::class)->ready($order));
    }

    public function deliver(int $id): void
    {
        $this->change($id, fn (Order $order) => app(OrderService::class)->deliver($order));
    }

    protected function change(int $id, callable $change): void
    {
        abort_unless($this->canWork(), 403);

        try {
            $change(Order::query()->findOrFail($id));
        } catch (PedidosException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }
}
