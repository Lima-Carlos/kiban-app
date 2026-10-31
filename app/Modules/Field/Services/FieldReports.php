<?php

namespace App\Modules\Field\Services;

use App\Models\User;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Enums\QuoteStatus;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The numbers of the Kiban Campo dashboard:
 * - today, now: where each technician is (at a service, on the way, late, free) and the day's orders;
 * - the month: done orders, revenue, time at the customer, punctuality, returns, the customers' stars and the
 *   quotes, for the company and for each technician;
 * - the nearest technicians to an order (free first), to pass on an order a technician handed back.
 */
class FieldReports
{
    /** A technician who arrives up to this many minutes after the time (plus the announced delay) is on time. */
    public const ON_TIME_MINUTES = 15;

    public function __construct(protected FieldAccess $access, protected DayPlanner $planner, protected WorkOrderService $orders) {}

    /**
     * Each technician right now, and the day's counts.
     *
     * @return array{technicians: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function today(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        [$start, $end] = $this->day($now);

        $orders = WorkOrder::query()->with(['customer', 'site'])
            ->whereBetween('scheduled_at', [$start, $end])
            ->whereNot('status', OrderStatus::Canceled->value)
            ->orderBy('scheduled_at')
            ->get();

        // An order started on another day and still open also counts as "at a service".
        $working = WorkOrder::query()->with(['customer', 'site'])
            ->where('status', OrderStatus::InProgress->value)
            ->get()
            ->keyBy('technician_id');

        $technicians = [];

        foreach ($this->access->technicians() as $technician) {
            $mine = $orders->where('technician_id', $technician->getKey())->values();
            $technicians[] = ['technician' => $technician, 'done' => $mine->where('status', OrderStatus::Done)->count(), 'total' => $mine->count()]
                + $this->state($working->get($technician->getKey()), $mine, $now);
        }

        $order = ['late' => 0, 'working' => 1, 'on_the_way' => 2, 'free' => 3, 'off' => 4];
        usort($technicians, fn (array $a, array $b): int => [$order[$a['state']], $a['technician']->name] <=> [$order[$b['state']], $b['technician']->name]);

        return [
            'technicians' => $technicians,
            'counts' => [
                'scheduled' => $orders->count(),
                'done' => $orders->where('status', OrderStatus::Done)->count(),
                'in_progress' => $orders->where('status', OrderStatus::InProgress)->count(),
                'late' => collect($technicians)->where('state', 'late')->count(),
                'unassigned' => WorkOrder::query()->where('status', OrderStatus::Open->value)->count(),
            ],
        ];
    }

    /**
     * Where a technician is: "working" (at a service), "late" (has not arrived after the time and the announced
     * delay), "on_the_way", "free" (with the next service, if any) or "off" (nothing more today).
     *
     * @param  Collection<int, WorkOrder>  $mine
     * @return array{state: string, order: ?WorkOrder, since: ?CarbonImmutable, minutes: ?int, next: ?WorkOrder}
     */
    protected function state(?WorkOrder $working, Collection $mine, CarbonImmutable $now): array
    {
        $waiting = $mine->filter(fn (WorkOrder $order): bool => in_array($order->status, [OrderStatus::Scheduled, OrderStatus::Paused], true));

        if ($working !== null) {
            return ['state' => 'working', 'order' => $working, 'since' => $working->checked_in_at ?? $working->started_at, 'minutes' => null, 'next' => $waiting->first()];
        }

        $late = $waiting->first(fn (WorkOrder $order): bool => $order->checked_in_at === null && $now->gt($this->expected($order)->addMinutes(self::ON_TIME_MINUTES)));

        if ($late !== null) {
            return ['state' => 'late', 'order' => $late, 'since' => null, 'minutes' => (int) $this->expected($late)->diffInMinutes($now), 'next' => null];
        }

        $going = $waiting->first(fn (WorkOrder $order): bool => $order->on_the_way_at !== null && $order->checked_in_at === null);

        if ($going !== null) {
            return ['state' => 'on_the_way', 'order' => $going, 'since' => $going->on_the_way_at, 'minutes' => null, 'next' => null];
        }

        return ['state' => $waiting->isEmpty() ? 'off' : 'free', 'order' => null, 'since' => null, 'minutes' => null, 'next' => $waiting->first()];
    }

    /** When the technician should arrive: the time plus the delay they announced. */
    protected function expected(WorkOrder $order): CarbonImmutable
    {
        return $order->scheduled_at->addMinutes((int) $order->delay_minutes);
    }

    /**
     * The month: the company's totals and one line per technician (most done orders first).
     *
     * @return array{totals: array<string, mixed>, technicians: array<int, array<string, mixed>>}
     */
    public function month(CarbonImmutable $month): array
    {
        [$start, $end] = $this->monthRange($month);
        $done = $this->doneIn($month);

        $quotes = Quote::query()->whereBetween('sent_at', [$start, $end])->get();

        $technicians = $done->groupBy('technician_id')
            ->map(fn (Collection $orders, $id): array => ['technician' => $this->access->technicians()->firstWhere('id', (int) $id) ?? User::query()->find($id)] + $this->totals($orders))
            ->filter(fn (array $row): bool => $row['technician'] !== null)
            ->sortByDesc('done')
            ->values()
            ->all();

        return [
            'totals' => $this->totals($done) + [
                'quotes_sent' => $quotes->count(),
                'quotes_approved' => $quotes->filter(fn (Quote $quote): bool => $quote->status === QuoteStatus::Approved)->count(),
                'handoffs' => WorkOrder::query()->whereBetween('handoff_at', [$start, $end])->count(),
            ],
            'technicians' => $technicians,
        ];
    }

    /**
     * Orders done in the month (company time zone).
     *
     * @return Collection<int, WorkOrder>
     */
    public function doneIn(CarbonImmutable $month): Collection
    {
        return WorkOrder::query()
            ->where('status', OrderStatus::Done->value)
            ->whereBetween('finished_at', $this->monthRange($month))
            ->get();
    }

    /**
     * A month in the company time zone (UTC limits).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function monthRange(CarbonImmutable $month): array
    {
        $timezone = OrderResource::timezone();
        $start = $month->shiftTimezone($timezone)->startOfMonth()->utc();

        return [$start, $start->setTimezone($timezone)->endOfMonth()->utc()];
    }

    /**
     * @param  Collection<int, WorkOrder>  $orders
     * @return array{done: int, revenue: int, average_minutes: ?int, on_time: ?int, returns: int, rating: ?float, ratings: int}
     */
    public function totals(Collection $orders): array
    {
        $minutes = $orders
            ->map(fn (WorkOrder $order): ?int => ($from = $order->checked_in_at ?? $order->started_at) && $order->finished_at ? (int) $from->diffInMinutes($order->finished_at) : null)
            ->filter(fn (?int $minutes): bool => $minutes !== null);

        $arrivals = $orders->filter(fn (WorkOrder $order): bool => $order->checked_in_at !== null && $order->scheduled_at !== null);
        $onTime = $arrivals->filter(fn (WorkOrder $order): bool => $order->checked_in_at->lte($this->expected($order)->addMinutes(self::ON_TIME_MINUTES)));
        $rated = $orders->whereNotNull('rating');

        return [
            'done' => $orders->count(),
            'revenue' => (int) $orders->sum('price_cents'),
            'average_minutes' => $minutes->isEmpty() ? null : (int) round($minutes->avg()),
            'on_time' => $arrivals->isEmpty() ? null : (int) round($onTime->count() * 100 / $arrivals->count()),
            'returns' => $orders->whereNotNull('return_of_id')->count(),
            'rating' => $rated->isEmpty() ? null : round((float) $rated->avg('rating'), 1),
            'ratings' => $rated->count(),
        ];
    }

    /**
     * The technicians nearest to an order, free ones first: where each one is now (the service they are at, or
     * the last one they went to today, or the company base) and the travel to the order. "Busy" = at a service
     * now or with another service at the order's time.
     *
     * @return array<int, array{technician: User, travel: ?int, free: bool}>
     */
    public function nearest(WorkOrder $order, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        [$start] = $this->day($now);
        $settings = FieldSetting::current();
        $site = $order->site;
        $rows = [];

        foreach ($this->access->technicians() as $technician) {
            $last = WorkOrder::query()->with('site')
                ->where('technician_id', $technician->getKey())
                ->whereNotNull('checked_in_at')
                ->where('checked_in_at', '>=', $start)
                ->latest('checked_in_at')
                ->first();

            [$latitude, $longitude] = $last !== null
                ? [$last->check_in_latitude ?? $last->site?->latitude, $last->check_in_longitude ?? $last->site?->longitude]
                : [$settings->base_latitude, $settings->base_longitude];

            $busy = $last?->status === OrderStatus::InProgress
                || ($order->scheduled_at !== null && $this->orders->clashes($technician, $order->scheduled_at, (int) ($order->duration_minutes ?: 60), $order)->isNotEmpty());

            $rows[] = [
                'technician' => $technician,
                'travel' => $this->planner->travelMinutes($latitude !== null ? (float) $latitude : null, $longitude !== null ? (float) $longitude : null, $site?->latitude, $site?->longitude),
                'free' => ! $busy,
            ];
        }

        usort($rows, fn (array $a, array $b): int => [! $a['free'], $a['travel'] ?? PHP_INT_MAX, $a['technician']->name] <=> [! $b['free'], $b['travel'] ?? PHP_INT_MAX, $b['technician']->name]);

        return $rows;
    }

    /**
     * Things the office must solve now, for the start page's "Attention" box.
     *
     * @return array<int, array{text: string, url: ?string, color: string}>
     */
    public function attention(): array
    {
        $items = [];
        $today = $this->today();
        $agenda = OrderResource::getUrl('agenda');

        foreach ($today['technicians'] as $row) {
            if ($row['state'] === 'late') {
                $items[] = [
                    'text' => __('field.attention.late', ['technician' => $row['technician']->name, 'customer' => $row['order']->customer->displayName(), 'time' => FieldFormat::duration($row['minutes'])]),
                    'url' => OrderResource::getUrl('view', ['record' => $row['order']]),
                    'color' => 'danger',
                ];
            }
        }

        $handedOff = WorkOrder::query()->where('status', OrderStatus::Open->value)->whereNotNull('handoff_at')->count();

        if ($handedOff > 0) {
            $items[] = ['text' => trans_choice('field.attention.handed_off', $handedOff, ['count' => $handedOff]), 'url' => $agenda, 'color' => 'warning'];
        }

        $soon = WorkOrder::query()->where('status', OrderStatus::Open->value)->whereNull('handoff_at')->whereIn('priority', ['high', 'urgent'])->count();

        if ($soon > 0) {
            $items[] = ['text' => trans_choice('field.attention.urgent', $soon, ['count' => $soon]), 'url' => $agenda, 'color' => 'warning'];
        }

        return $items;
    }

    /**
     * The last 12 months for the filter ("2026-10" => "Outubro 2026").
     *
     * @return array<string, string>
     */
    public function monthOptions(): array
    {
        $options = [];
        $month = CarbonImmutable::now(OrderResource::timezone())->startOfMonth();

        for ($i = 0; $i < 12; $i++) {
            $options[$month->format('Y-m')] = $this->monthLabel($month);
            $month = $month->subMonth();
        }

        return $options;
    }

    public function monthLabel(CarbonImmutable $month): string
    {
        return ucfirst(CarbonImmutable::parse($month->format('Y-m-01'))->translatedFormat('F Y'));
    }

    /**
     * The company's day (start and end in UTC) around a moment.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function day(CarbonImmutable $now): array
    {
        $local = $now->setTimezone(OrderResource::timezone());

        return [$local->startOfDay()->utc(), $local->endOfDay()->utc()];
    }
}
