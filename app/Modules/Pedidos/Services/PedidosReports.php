<?php

namespace App\Modules\Pedidos\Services;

use App\Models\User;
use App\Modules\Pedidos\Enums\Fulfillment;
use App\Modules\Pedidos\Enums\OrderStatus;
use App\Modules\Pedidos\Filament\BoardPage;
use App\Modules\Pedidos\Filament\DeliveriesPage;
use App\Modules\Pedidos\Filament\MissingClientsPage;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Models\PedidosSetting;
use Carbon\CarbonImmutable;

/**
 * The numbers of the Pedidos: today on the start page and the "Attention" box (orders waiting for the kitchen,
 * ready deliveries waiting for a driver, customers who just stopped ordering).
 */
class PedidosReports
{
    /**
     * @return array{new: int, preparing: int, ready: int, out: int, delivered: int, orders: int, sales_cents: int}
     */
    public function today(): array
    {
        $start = CarbonImmutable::now(PedidosSetting::timezone())->startOfDay()->utc();
        $open = Order::query()->open()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $today = Order::query()->where('created_at', '>=', $start)->where('status', '!=', OrderStatus::Cancelled->value);

        return [
            'new' => (int) ($open[OrderStatus::New->value] ?? 0),
            'preparing' => (int) ($open[OrderStatus::Preparing->value] ?? 0),
            'ready' => (int) ($open[OrderStatus::Ready->value] ?? 0),
            'out' => (int) ($open[OrderStatus::Out->value] ?? 0),
            'delivered' => Order::query()->where('status', OrderStatus::Delivered->value)->where('delivered_at', '>=', $start)->count(),
            'orders' => (clone $today)->count(),
            'sales_cents' => (int) (clone $today)->sum('total_cents'),
        ];
    }

    /**
     * @return array<int, array{text: string, url: ?string, color: string}>
     */
    public function attention(User $user): array
    {
        $access = app(PedidosAccess::class);
        $items = [];

        if ($access->works($user)) {
            $waiting = Order::query()->where('status', OrderStatus::New->value)->count();

            if ($waiting > 0) {
                $items[] = ['text' => trans_choice('pedidos.attention.waiting', $waiting, ['count' => $waiting]), 'url' => BoardPage::getUrl(), 'color' => 'danger'];
            }
        }

        if ($access->delivers($user)) {
            $ready = Order::query()->where('status', OrderStatus::Ready->value)->where('fulfillment', Fulfillment::Delivery->value)->whereNull('run_id')->count();

            if ($ready > 0) {
                $items[] = ['text' => trans_choice('pedidos.attention.ready', $ready, ['count' => $ready]), 'url' => DeliveriesPage::getUrl(), 'color' => 'warning'];
            }
        }

        if ($access->takesOrders($user)) {
            $stopped = app(CustomerRhythm::class)->justStopped()->count();

            if ($stopped > 0) {
                $items[] = ['text' => trans_choice('pedidos.attention.stopped', $stopped, ['count' => $stopped]), 'url' => MissingClientsPage::getUrl(), 'color' => 'danger'];
            }
        }

        return $items;
    }
}
