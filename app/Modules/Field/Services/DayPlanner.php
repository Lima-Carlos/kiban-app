<?php

namespace App\Modules\Field\Services;

use App\Models\User;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\WorkOrder;
use App\Support\Geo;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The technician's day, with no paid map service:
 * - travel time between two places = straight-line distance × the road factor ÷ the average speed in town
 *   (both set by the company), rounded up to 5 minutes; without the points, no estimate;
 * - "finish by": the next service's time minus the travel to it; the alert to leave comes some minutes before;
 * - the suggested order of a day: always the nearest service next (from the base), with times one after the
 *   other (each service's time plus the travel), starting at the day's first time.
 */
class DayPlanner
{
    public function travelMinutes(?float $fromLatitude, ?float $fromLongitude, ?float $toLatitude, ?float $toLongitude): ?int
    {
        if ($fromLatitude === null || $fromLongitude === null || $toLatitude === null || $toLongitude === null) {
            return null;
        }

        $settings = FieldSetting::current();
        $meters = Geo::distance($fromLatitude, $fromLongitude, $toLatitude, $toLongitude) * (float) $settings->route_factor;
        $minutes = $meters / 1000 / max(5, (int) $settings->travel_speed_kmh) * 60;

        return max(5, (int) ceil($minutes / 5) * 5);
    }

    /** The technician's next service of the same day, after this one. */
    public function next(WorkOrder $order): ?WorkOrder
    {
        if ($order->technician_id === null || $order->scheduled_at === null) {
            return null;
        }

        $end = $order->scheduled_at->setTimezone(OrderResource::timezone())->endOfDay()->utc();

        return WorkOrder::query()
            ->with(['customer', 'site'])
            ->where('technician_id', $order->technician_id)
            ->whereIn('status', [OrderStatus::Scheduled->value, OrderStatus::Paused->value])
            ->whereKeyNot($order->getKey())
            ->where('scheduled_at', '>', $order->scheduled_at)
            ->where('scheduled_at', '<=', $end)
            ->orderBy('scheduled_at')
            ->first();
    }

    /**
     * For the technician at a service: the next one, the travel to it, until when to finish here and when to
     * be warned to leave. Null when there is no next service today.
     *
     * @return array{next: WorkOrder, travel: ?int, finish_by: CarbonImmutable, alert_at: CarbonImmutable}|null
     */
    public function plan(WorkOrder $order): ?array
    {
        $next = $this->next($order);

        if ($next === null) {
            return null;
        }

        $travel = $this->travelMinutes($order->site?->latitude, $order->site?->longitude, $next->site?->latitude, $next->site?->longitude);
        $finishBy = $next->scheduled_at->subMinutes($travel ?? 0);

        return [
            'next' => $next,
            'travel' => $travel,
            'finish_by' => $finishBy,
            'alert_at' => $finishBy->subMinutes(FieldSetting::current()->leave_alert_minutes),
        ];
    }

    /**
     * The suggested order of a technician's day (not saved): nearest next from the base, times one after the
     * other from the day's first time (or 08:00).
     *
     * @return array<int, array{order: WorkOrder, at: CarbonImmutable, travel: ?int}>
     */
    public function suggest(User $technician, CarbonInterface $day): array
    {
        $timezone = OrderResource::timezone();
        $date = CarbonImmutable::instance($day)->setTimezone($timezone)->startOfDay();
        $orders = WorkOrder::query()
            ->with(['customer', 'site'])
            ->where('technician_id', $technician->getKey())
            ->where('status', OrderStatus::Scheduled->value)
            ->whereBetween('scheduled_at', [$date->utc(), $date->endOfDay()->utc()])
            ->orderBy('scheduled_at')
            ->get();

        if ($orders->count() < 2) {
            return [];
        }

        $settings = FieldSetting::current();
        $start = $orders->first()->scheduled_at->setTimezone($timezone);
        $here = [$settings->base_latitude, $settings->base_longitude];
        $left = $orders->values();
        $plan = [];
        $at = $start;

        while ($left->isNotEmpty()) {
            $pick = $this->nearest($left, $here);
            $travel = $here[0] !== null ? $this->travelMinutes($here[0], $here[1], $pick->site?->latitude, $pick->site?->longitude) : null;

            // The first service keeps its time; the others come after the previous one plus the travel.
            if ($plan !== []) {
                $at = $at->addMinutes(end($plan)['order']->duration_minutes + ($travel ?? 0));
                $at = $at->addMinutes((5 - $at->minute % 5) % 5);
            }

            $plan[] = ['order' => $pick, 'at' => $at, 'travel' => $travel];
            $here = [$pick->site?->latitude, $pick->site?->longitude];
            $left = $left->reject(fn (WorkOrder $order): bool => $order->is($pick))->values();
        }

        return $plan;
    }

    /**
     * Puts the suggested times on the technician's agenda (each change goes to the order's history).
     *
     * @param  array<int, array{order: WorkOrder, at: CarbonImmutable, travel: ?int}>  $plan
     */
    public function apply(array $plan, User $by): void
    {
        $orders = app(WorkOrderService::class);

        foreach ($plan as $step) {
            if (! $step['order']->scheduled_at->eq($step['at'])) {
                $orders->schedule($step['order']->fresh(), $step['order']->technician, $step['at'], null, $by);
            }
        }
    }

    /**
     * @param  Collection<int, WorkOrder>  $orders
     * @param  array{0: ?float, 1: ?float}  $here
     */
    protected function nearest(Collection $orders, array $here): WorkOrder
    {
        if ($here[0] === null || $here[1] === null) {
            return $orders->first();
        }

        return $orders->sortBy(fn (WorkOrder $order): float => $order->site?->hasPoint()
            ? Geo::distance($here[0], $here[1], $order->site->latitude, $order->site->longitude)
            : PHP_FLOAT_MAX)->first();
    }
}
