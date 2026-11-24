<?php

namespace App\Modules\Pedidos\Services;

use App\Modules\Pedidos\Enums\OrderStatus;
use App\Modules\Pedidos\Enums\Rhythm;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Models\PedidosSetting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Each customer's rhythm: the average days between their orders (first to last order ÷ number of gaps, at least
 * one day). Against it, the days since the last order say where the customer stands:
 * - green (on rhythm): up to "late" percent of the rhythm (150% by default: ordering every 7 days, green until 10.5);
 * - yellow (late): up to "stopped" percent (300% by default: until 21 days);
 * - red (stopped): past that;
 * - new: fewer orders than needed to know the rhythm (3 by default).
 * Cancelled orders and counter sales without a customer do not count. Nothing is stored: it is read from the orders.
 */
class CustomerRhythm
{
    /**
     * @return Collection<int, array{customer_id: int, orders: int, first_at: CarbonImmutable, last_at: CarbonImmutable, average_days: ?float, days_since: float, rhythm: Rhythm, stopped_at: ?CarbonImmutable}>
     */
    public function all(?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();
        $settings = PedidosSetting::current();

        return Order::query()
            ->whereNotNull('customer_id')
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->groupBy('customer_id')
            ->selectRaw('customer_id, count(*) as orders, min(created_at) as first_at, max(created_at) as last_at')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row): array => [(int) $row->customer_id => $this->classify(
                (int) $row->customer_id, (int) $row->orders, CarbonImmutable::parse($row->first_at, 'UTC'), CarbonImmutable::parse($row->last_at, 'UTC'), $now, $settings,
            )]);
    }

    /**
     * @return array{customer_id: int, orders: int, first_at: CarbonImmutable, last_at: CarbonImmutable, average_days: ?float, days_since: float, rhythm: Rhythm, stopped_at: ?CarbonImmutable}|null
     */
    public function for(int $customerId, ?CarbonImmutable $now = null): ?array
    {
        return $this->all($now)->get($customerId);
    }

    /**
     * Customers who turned red in the last days (they reached "stopped" recently): the ones to call now.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function justStopped(int $days = 7, ?CarbonImmutable $now = null): Collection
    {
        $now ??= CarbonImmutable::now();

        return $this->all($now)->filter(fn (array $row): bool => $row['rhythm'] === Rhythm::Stopped && $row['stopped_at'] !== null && $row['stopped_at']->greaterThanOrEqualTo($now->subDays($days)));
    }

    /**
     * @return array{customer_id: int, orders: int, first_at: CarbonImmutable, last_at: CarbonImmutable, average_days: ?float, days_since: float, rhythm: Rhythm, stopped_at: ?CarbonImmutable}
     */
    public function classify(int $customerId, int $orders, CarbonImmutable $first, CarbonImmutable $last, CarbonImmutable $now, PedidosSetting $settings): array
    {
        $daysSince = max(0.0, $last->diffInSeconds($now) / 86400);
        $row = ['customer_id' => $customerId, 'orders' => $orders, 'first_at' => $first, 'last_at' => $last, 'average_days' => null, 'days_since' => $daysSince, 'rhythm' => Rhythm::New, 'stopped_at' => null];

        if ($orders < max(2, $settings->min_orders)) {
            return $row;
        }

        $average = max(1.0, $first->diffInSeconds($last) / 86400 / ($orders - 1));
        $late = $average * $settings->late_percent / 100;
        $stopped = $average * max($settings->late_percent, $settings->stopped_percent) / 100;

        $row['average_days'] = $average;
        $row['stopped_at'] = $last->addSeconds((int) round($stopped * 86400));
        $row['rhythm'] = match (true) {
            $daysSince <= $late => Rhythm::OnRhythm,
            $daysSince <= $stopped => Rhythm::Late,
            default => Rhythm::Stopped,
        };

        return $row;
    }
}
