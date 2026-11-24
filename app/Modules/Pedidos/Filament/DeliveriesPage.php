<?php

namespace App\Modules\Pedidos\Filament;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Pedidos\Enums\Fulfillment;
use App\Modules\Pedidos\Enums\OrderStatus;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Models\PedidosSetting;
use App\Modules\Pedidos\Models\Run;
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
 * Deliveries: pick the ready orders that leave together and who takes them; the system suggests the visiting
 * order from the shop (the nearest first, then shortening the way, so the order of the calls never forces a longer
 * way) and gives the Google Maps link with the stops for the driver's phone. On the street: each stop's "delivered".
 */
class DeliveriesPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = PedidosModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'pedidos/entregas';

    protected string $view = 'pedidos.deliveries';

    /** @var array<int, int|string> */
    public array $selected = [];

    public ?int $driverId = null;

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->canWrite(PedidosModule::KEY) && app(PedidosAccess::class)->delivers();
    }

    public static function getNavigationLabel(): string
    {
        return __('pedidos.deliveries.page_title');
    }

    public function getTitle(): string
    {
        return __('pedidos.deliveries.page_title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $person = app(PedidosAccess::class)->personFor();

        if ($person !== null && $this->drivers()->contains('id', $person->getKey())) {
            $this->driverId = $person->getKey();
        }
    }

    /**
     * Ready deliveries not on the street yet, oldest first.
     *
     * @return Collection<int, Order>
     */
    public function ready(): Collection
    {
        return Order::query()->with(['client', 'site', 'lines'])
            ->where('status', OrderStatus::Ready->value)->where('fulfillment', Fulfillment::Delivery->value)->whereNull('run_id')
            ->orderBy('created_at')->get();
    }

    /**
     * The suggestion for the selected orders (or all ready ones when none is selected).
     *
     * @return array{orders: Collection<int, Order>, distance: int, missing: array<int, int>, maps_url: ?string}|null
     */
    public function suggestion(): ?array
    {
        $ready = $this->ready();
        $chosen = $this->selected === [] ? $ready : $ready->whereIn('id', array_map('intval', $this->selected));

        return $chosen->isEmpty() ? null : app(OrderService::class)->suggestRoute($chosen->values());
    }

    /**
     * @return Collection<int, Run>
     */
    public function runs(): Collection
    {
        return Run::query()->with(['driver', 'orders.client', 'orders.lines'])->where('status', Run::OPEN)->orderBy('started_at')->get();
    }

    /**
     * @return Collection<int, Person>
     */
    public function drivers(): Collection
    {
        return app(PedidosPeople::class)->team('delivers');
    }

    public function shopHasPoint(): bool
    {
        return PedidosSetting::current()->hasPoint();
    }

    public function runMapsUrl(Run $run): ?string
    {
        return app(OrderService::class)->runMapsUrl($run);
    }

    public function send(): void
    {
        abort_unless(static::canAccess(), 403);

        $suggestion = $this->suggestion();

        if ($suggestion === null) {
            Notification::make()->warning()->title(__('pedidos.errors.pick_orders'))->send();

            return;
        }

        try {
            $run = app(OrderService::class)->sendRun($suggestion['orders']->pluck('id')->all(), $this->driverId, auth()->user());
        } catch (PedidosException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        $this->selected = [];
        Notification::make()->success()->title(trans_choice('pedidos.deliveries.sent', $run->orders->count(), ['count' => $run->orders->count()]))->send();
    }

    public function delivered(int $id): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            app(OrderService::class)->deliver(Order::query()->findOrFail($id));
        } catch (PedidosException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }
}
