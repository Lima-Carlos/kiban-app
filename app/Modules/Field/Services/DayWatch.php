<?php

namespace App\Modules\Field\Services;

use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Filament\ExecuteOrderPage;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\WorkOrder;
use App\Notices\Notifier;
use App\Support\PhoneAlerts;
use Carbon\CarbonImmutable;

/**
 * Watches the technicians' day of the current company (every minute, by kiban:field). Each warning only once:
 * - the technician at a service gets the alert on the phone to leave for the next one (some minutes before);
 * - the office is told when the technician is still at a service after the time to leave ("risk of being late");
 * - the office (and the technician) are told when a technician has not arrived 15 minutes after the time.
 */
class DayWatch
{
    /** Minutes after the time when a technician who has not arrived is reported. */
    public const NOT_ARRIVED_AFTER = 15;

    public function __construct(protected DayPlanner $planner, protected PhoneAlerts $phones, protected FieldNotices $notices, protected Notifier $notifier) {}

    /** Returns how many warnings went out. */
    public function scan(): int
    {
        $now = CarbonImmutable::now();
        $sent = 0;
        $timezone = OrderResource::timezone();

        $working = WorkOrder::query()->with(['customer', 'site', 'technician'])
            ->where('status', OrderStatus::InProgress->value)
            ->whereBetween('scheduled_at', [$now->subDay(), $now->addDay()])
            ->get();

        foreach ($working as $order) {
            $plan = $this->planner->plan($order);

            if ($plan === null) {
                continue;
            }

            $next = $plan['next'];

            if ($now->gte($plan['alert_at']) && $now->lt($plan['finish_by']->addMinutes(30)) && $this->once($order, 'leave-'.$next->getKey())) {
                $this->phones->send($order->technician, __('field.alerts.leave_title', [], $order->technician->preferredLocale()), __('field.alerts.leave_body', [
                    'time' => $plan['finish_by']->setTimezone($timezone)->format('H:i'),
                    'customer' => $next->customer->displayName(),
                    'next' => $next->scheduled_at->setTimezone($timezone)->format('H:i'),
                ], $order->technician->preferredLocale()), ExecuteOrderPage::getUrl(['order' => $order->getKey()]), 'leave-'.$order->getKey());
                $sent++;
            }

            if ($now->gte($plan['finish_by']) && $this->once($order, 'risk-'.$next->getKey())) {
                $this->notices->office($order, 'field.notices.risk', ['technician' => $order->technician->name, 'customer' => $next->customer->displayName()],
                    'field.notices.risk_body', ['order' => $order->label(), 'next' => $next->label(), 'time' => $next->scheduled_at->setTimezone($timezone)->format('H:i')],
                    'heroicon-o-exclamation-triangle', 'warning');
                $sent++;
            }
        }

        $late = WorkOrder::query()->with(['customer', 'technician'])
            ->where('status', OrderStatus::Scheduled->value)
            ->whereNull('checked_in_at')
            ->whereNotNull('technician_id')
            ->whereBetween('scheduled_at', [$now->subHours(12), $now->subMinutes(self::NOT_ARRIVED_AFTER)])
            ->get();

        foreach ($late as $order) {
            // An announced delay that is still running is not "late" (checked again in the next minutes).
            if ($order->delay_minutes && $now->lt($order->scheduled_at->addMinutes($order->delay_minutes + self::NOT_ARRIVED_AFTER))) {
                continue;
            }

            if (! $this->once($order, 'not-arrived')) {
                continue;
            }

            $time = $order->scheduled_at->addMinutes((int) $order->delay_minutes)->setTimezone($timezone)->format('H:i');

            $this->notices->office($order, 'field.notices.not_arrived', ['technician' => $order->technician->name, 'customer' => $order->customer->displayName()],
                'field.notices.not_arrived_body', ['order' => $order->label(), 'time' => $time], 'heroicon-o-map-pin', 'danger');
            $sent++;
        }

        return $sent;
    }

    /** True only the first time for this order and this kind of warning. */
    protected function once(WorkOrder $order, string $kind): bool
    {
        return $this->notifier->firstTime((int) $order->tenant_id, 'field-'.$kind.'-'.$order->getKey());
    }
}
