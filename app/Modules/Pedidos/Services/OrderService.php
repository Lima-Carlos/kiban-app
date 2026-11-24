<?php

namespace App\Modules\Pedidos\Services;

use App\Models\User;
use App\Modules\Field\Models\Site;
use App\Modules\Pedidos\Enums\Fulfillment;
use App\Modules\Pedidos\Enums\OrderSource;
use App\Modules\Pedidos\Enums\OrderStatus;
use App\Modules\Pedidos\Enums\PaymentMethod;
use App\Modules\Pedidos\Models\Client;
use App\Modules\Pedidos\Models\MenuItem;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Models\PedidosSetting;
use App\Modules\Pedidos\Models\Run;
use App\People\Person;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Every change of an order passes here (the quick order screen, the board, the deliveries screen and, later, the
 * site): taking the order (prices in cents, copied from the menu), the way new → preparing → ready → out → delivered,
 * who prepared and who delivered (one tap, may be changed later), cancelling with the reason, and the delivery runs
 * in the suggested visiting order (RoutePlanner). Delivered and cancelled orders never change again.
 */
class OrderService
{
    public function __construct(protected RoutePlanner $planner) {}

    /**
     * @param  array<string, mixed>  $data  customer_id, site_id, fulfillment, source, payment_method, change_for_cents, delivery_fee_cents, notes,
     *                                      lines: [{menu_item_id?, name?, unit_price_cents?, quantity, notes?}]
     */
    public function create(array $data, ?User $by = null): Order
    {
        $fulfillment = Fulfillment::tryFrom((string) ($data['fulfillment'] ?? '')) ?? Fulfillment::Delivery;
        $source = OrderSource::tryFrom((string) ($data['source'] ?? '')) ?? OrderSource::Phone;
        $payment = PaymentMethod::tryFrom((string) ($data['payment_method'] ?? '')) ?? ($source === OrderSource::App ? PaymentMethod::App : null);
        $client = filled($data['customer_id'] ?? null) ? Client::query()->find((int) $data['customer_id']) : null;

        if (filled($data['customer_id'] ?? null) && $client === null) {
            throw new PedidosException(__('pedidos.errors.customer_missing'));
        }

        $lines = $this->lines((array) ($data['lines'] ?? []));
        $site = $fulfillment === Fulfillment::Delivery ? $this->site($client, $data['site_id'] ?? null) : null;

        if ($fulfillment === Fulfillment::Delivery && $site === null) {
            throw new PedidosException(__('pedidos.errors.no_address'));
        }

        $subtotal = (int) $lines->sum('total_cents');
        $fee = $fulfillment === Fulfillment::Delivery ? max(0, (int) ($data['delivery_fee_cents'] ?? PedidosSetting::current()->delivery_fee_cents)) : 0;
        $change = $payment === PaymentMethod::Cash && filled($data['change_for_cents'] ?? null) ? max(0, (int) $data['change_for_cents']) : null;

        return DB::transaction(function () use ($data, $client, $site, $fulfillment, $source, $payment, $lines, $subtotal, $fee, $change, $by): Order {
            // One number after the other in the company, even with two people taking orders at the same time.
            PedidosSetting::current();
            PedidosSetting::query()->lockForUpdate()->first();

            $order = Order::create([
                'number' => (int) Order::query()->max('number') + 1,
                'customer_id' => $client?->getKey(),
                'site_id' => $site?->getKey(),
                'address' => $site?->address(),
                'fulfillment' => $fulfillment,
                'source' => $source,
                'payment_method' => $payment,
                'change_for_cents' => $change,
                'status' => OrderStatus::New,
                'notes' => filled($data['notes'] ?? null) ? trim((string) $data['notes']) : null,
                'subtotal_cents' => $subtotal,
                'delivery_fee_cents' => $fee,
                'total_cents' => $subtotal + $fee,
                'created_by' => $by?->getKey(),
            ]);

            foreach ($lines as $line) {
                $order->lines()->create($line);
            }

            return $order->load('lines');
        });
    }

    /** The kitchen started it (who prepares, when known). */
    public function start(Order $order, ?int $personId = null): Order
    {
        $this->expect($order, [OrderStatus::New]);

        return $this->save($order, ['status' => OrderStatus::Preparing, 'preparing_at' => now(), 'prepared_by' => $this->person($personId) ?? $order->prepared_by]);
    }

    /** Ready to leave (or to be picked up). */
    public function ready(Order $order, ?int $personId = null): Order
    {
        $this->expect($order, [OrderStatus::New, OrderStatus::Preparing]);

        return $this->save($order, ['status' => OrderStatus::Ready, 'ready_at' => now(), 'preparing_at' => $order->preparing_at ?? now(), 'prepared_by' => $this->person($personId) ?? $order->prepared_by]);
    }

    /** Handed to the customer: picked up at the shop, or delivered at the address. */
    public function deliver(Order $order, ?int $personId = null): Order
    {
        $this->expect($order, [OrderStatus::Ready, OrderStatus::Out]);
        $delivered = $order->isDelivery() ? ($this->person($personId) ?? $order->delivered_by ?? $order->run?->driver_id) : $order->delivered_by;

        $order = $this->save($order, ['status' => OrderStatus::Delivered, 'delivered_at' => now(), 'delivered_by' => $delivered]);
        $this->closeRun($order);

        return $order;
    }

    public function cancel(Order $order, string $reason): Order
    {
        if ($order->status->isFinal()) {
            throw new PedidosException(__('pedidos.errors.final'));
        }

        if (blank(trim($reason))) {
            throw new PedidosException(__('pedidos.errors.reason_required'));
        }

        $order = $this->save($order, ['status' => OrderStatus::Cancelled, 'cancelled_at' => now(), 'cancel_reason' => trim($reason)]);
        $this->closeRun($order);

        return $order;
    }

    /** Change who prepared (one tap, also after the order is done). */
    public function setPreparer(Order $order, ?int $personId): Order
    {
        return $this->save($order, ['prepared_by' => $this->person($personId)]);
    }

    /** Change who delivered (one tap, also after the order is done). */
    public function setDeliverer(Order $order, ?int $personId): Order
    {
        return $this->save($order, ['delivered_by' => $this->person($personId)]);
    }

    /**
     * The suggested visiting order of ready deliveries, from the shop (no paid map service).
     *
     * @param  Collection<int, Order>  $orders
     * @return array{orders: Collection<int, Order>, distance: int, missing: array<int, int>, maps_url: ?string}
     */
    public function suggestRoute(Collection $orders): array
    {
        $orders = $orders->loadMissing('site')->keyBy('id');
        $settings = PedidosSetting::current();
        $origin = $settings->hasPoint() ? [$settings->latitude, $settings->longitude] : null;
        $stops = $orders->map(fn (Order $order): ?array => $order->site?->hasPoint() ? [$order->site->latitude, $order->site->longitude] : null)->all();
        $plan = $this->planner->plan($origin, $stops);
        $sorted = collect($plan['order'])->map(fn (int $id): Order => $orders->get($id))->values();
        $points = $sorted->filter(fn (Order $order): bool => (bool) $order->site?->hasPoint())->map(fn (Order $order): array => [$order->site->latitude, $order->site->longitude])->values()->all();

        return ['orders' => $sorted, 'distance' => $plan['distance'], 'missing' => $plan['missing'], 'maps_url' => $this->planner->mapsUrl($origin, $points)];
    }

    /**
     * The driver leaves with these ready deliveries, in the suggested order.
     *
     * @param  array<int, int>  $orderIds
     */
    public function sendRun(array $orderIds, ?int $driverId, ?User $by = null): Run
    {
        $orders = Order::query()->with('site')->whereKey($orderIds)->get();

        if ($orders->isEmpty() || $orders->count() !== count(array_unique($orderIds))) {
            throw new PedidosException(__('pedidos.errors.pick_orders'));
        }

        foreach ($orders as $order) {
            if (! $order->isDelivery() || $order->status !== OrderStatus::Ready || $order->run_id !== null) {
                throw new PedidosException(__('pedidos.errors.not_ready', ['number' => $order->number]));
            }
        }

        $driver = $this->person($driverId);
        $route = $this->suggestRoute($orders);

        return DB::transaction(function () use ($route, $driver, $by): Run {
            $run = Run::create(['driver_id' => $driver, 'status' => Run::OPEN, 'distance_m' => $route['distance'], 'started_at' => now(), 'created_by' => $by?->getKey()]);

            foreach ($route['orders'] as $position => $order) {
                $order->update(['status' => OrderStatus::Out, 'out_at' => now(), 'run_id' => $run->getKey(), 'run_position' => $position + 1, 'delivered_by' => $driver ?? $order->delivered_by]);
            }

            return $run->load('orders');
        });
    }

    /** Google Maps with the stops of a run still to visit, in order. */
    public function runMapsUrl(Run $run): ?string
    {
        $settings = PedidosSetting::current();
        $points = $run->orders()->with('site')->where('status', OrderStatus::Out->value)->get()
            ->filter(fn (Order $order): bool => (bool) $order->site?->hasPoint())
            ->map(fn (Order $order): array => [$order->site->latitude, $order->site->longitude])->values()->all();

        return $this->planner->mapsUrl($settings->hasPoint() ? [$settings->latitude, $settings->longitude] : null, $points);
    }

    /**
     * @param  array<int, mixed>  $lines
     * @return Collection<int, array{menu_item_id: ?int, name: string, unit_price_cents: int, quantity: int, notes: ?string, total_cents: int}>
     */
    protected function lines(array $lines): Collection
    {
        $items = MenuItem::query()->whereKey(collect($lines)->pluck('menu_item_id')->filter()->all())->get()->keyBy('id');

        $result = collect($lines)->map(function ($line) use ($items): ?array {
            $line = (array) $line;
            $quantity = (int) ($line['quantity'] ?? 1);

            if ($quantity < 1) {
                return null;
            }

            if ($quantity > 999) {
                throw new PedidosException(__('pedidos.errors.quantity'));
            }

            $item = filled($line['menu_item_id'] ?? null) ? $items->get((int) $line['menu_item_id']) : null;

            if (filled($line['menu_item_id'] ?? null) && $item === null) {
                throw new PedidosException(__('pedidos.errors.item_missing'));
            }

            $name = $item?->name ?? trim((string) ($line['name'] ?? ''));

            if ($name === '') {
                return null;
            }

            $price = $item?->price_cents ?? max(0, (int) ($line['unit_price_cents'] ?? 0));

            return [
                'menu_item_id' => $item?->getKey(),
                'name' => mb_substr($name, 0, 120),
                'unit_price_cents' => $price,
                'quantity' => $quantity,
                'notes' => filled($line['notes'] ?? null) ? mb_substr(trim((string) $line['notes']), 0, 255) : null,
                'total_cents' => $price * $quantity,
            ];
        })->filter()->values();

        if ($result->isEmpty()) {
            throw new PedidosException(__('pedidos.errors.no_items'));
        }

        return $result;
    }

    protected function site(?Client $client, mixed $siteId): ?Site
    {
        if ($client === null) {
            return null;
        }

        return filled($siteId)
            ? Site::query()->where('customer_id', $client->getKey())->find((int) $siteId)
            : Site::query()->where('customer_id', $client->getKey())->where('is_main', true)->first();
    }

    protected function person(?int $personId): ?int
    {
        if ($personId === null) {
            return null;
        }

        return Person::query()->find($personId)?->getKey() ?? throw new PedidosException(__('pedidos.errors.person_missing'));
    }

    /**
     * @param  array<int, OrderStatus>  $allowed
     */
    protected function expect(Order $order, array $allowed): void
    {
        if (! in_array($order->status, $allowed, true)) {
            throw new PedidosException($order->status->isFinal() ? __('pedidos.errors.final') : __('pedidos.errors.step', ['status' => $order->status->getLabel()]));
        }
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    protected function save(Order $order, array $changes): Order
    {
        $order->update($changes);

        return $order->refresh();
    }

    /** A run is done when none of its orders is still on the street. */
    protected function closeRun(Order $order): void
    {
        $run = $order->run;

        if ($run !== null && $run->status === Run::OPEN && ! $run->orders()->where('status', OrderStatus::Out->value)->exists()) {
            $run->update(['status' => Run::DONE, 'finished_at' => now()]);
        }
    }
}
