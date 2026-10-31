<?php

namespace App\Connections\Bridges;

use App\Models\User;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\TimeClock\Filament\PunchPage;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Services\DayCalculator;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\Services\TimesheetDocument;
use App\Modules\TimeClock\TimeClockModule;
use App\People\Person;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Kiban Campo + Kiban Ponto (part O7 of the Campo):
 * - on the technician's order screen: "you have not clocked in yet" at the first order of the day and "clock out
 *   now" after the last one. Only a reminder with a link: the punch stays an act of the employee, with the selfie
 *   and the location the company asks for (Portaria 671); nothing here records a punch;
 * - on the Campo dashboard: hours on the time clock × hours at the customers ("utilization") per technician.
 * Only when the company has both systems.
 */
class FieldTimeClock
{
    public function __construct(protected TenantContext $context, protected TimeClockAccess $clock) {}

    public function enabled(): bool
    {
        $license = $this->context->get()->license();

        return $license->hasModule(FieldModule::KEY) && $license->hasModule(TimeClockModule::KEY);
    }

    /**
     * What the technician's order screen needs for the reminder, or null (no time clock, does not clock in).
     * "punches" = today's punches already on the server; the phone adds the ones still in its own queue.
     *
     * @return array{url: string, punches: int, first: bool, last: bool}|null
     */
    public function reminder(User $technician, WorkOrder $order): ?array
    {
        if (! $this->enabled() || ! $this->context->get()->license()->canWrite(TimeClockModule::KEY)) {
            return null;
        }

        $employee = $this->clock->employeeFor($technician);

        if ($employee === null) {
            return null;
        }

        [$start, $end] = $this->day();
        $others = WorkOrder::query()->where('technician_id', $technician->getKey())->whereKeyNot($order->getKey());

        return [
            'url' => PunchPage::getUrl(),
            'punches' => $this->punchesToday($employee),
            // No other order of the day had an arrival yet: this is the first one.
            'first' => ! (clone $others)->whereBetween('checked_in_at', [$start, $end])->exists(),
            // No other order of the day is still waiting: this is the last one.
            'last' => ! (clone $others)->whereBetween('scheduled_at', [$start, $end])
                ->whereIn('status', [OrderStatus::Scheduled->value, OrderStatus::InProgress->value, OrderStatus::Paused->value])
                ->exists(),
        ];
    }

    public function punchesToday(Employee $employee): int
    {
        $today = CarbonImmutable::now(app(DayCalculator::class)->timezone($employee));

        return Punch::query()->where('employee_id', $employee->getKey())
            ->whereBetween('punched_at', [$today->startOfDay()->utc(), $today->endOfDay()->utc()])
            ->count();
    }

    /**
     * Utilization in a month, per technician who clocks in: minutes worked on the time clock (closed months use
     * the frozen copy), minutes at the customers (arrival to the end of each done order) and the percentage.
     *
     * @param  Collection<int, WorkOrder>|null  $done  the month's done orders (read here when not given)
     * @return array<int, array{worked: int, service: int, percent: ?int}> by technician (user id)
     */
    public function utilization(CarbonImmutable $month, ?Collection $done = null): array
    {
        if (! $this->enabled()) {
            return [];
        }

        $timezone = OrderResource::timezone();
        $start = $month->shiftTimezone($timezone)->startOfMonth()->utc();
        $end = $start->setTimezone($timezone)->endOfMonth()->utc();

        $done ??= WorkOrder::query()->where('status', OrderStatus::Done->value)->whereBetween('finished_at', [$start, $end])->get();
        $service = $done->groupBy('technician_id')->map(fn (Collection $orders): int => (int) $orders->sum(
            fn (WorkOrder $order): int => ($from = $order->checked_in_at ?? $order->started_at) && $order->finished_at ? max(0, (int) $from->diffInMinutes($order->finished_at)) : 0
        ));

        $technicians = app(FieldAccess::class)->technicians()->pluck('id')->merge($service->keys())->filter()->unique();
        $people = Person::query()->whereIn('user_id', $technicians)->pluck('id', 'user_id');
        $result = [];

        foreach ($technicians as $userId) {
            $personId = $people->get($userId);
            $employee = $personId ? Employee::query()->where('person_id', $personId)->latest('id')->first() : null;

            if ($employee === null) {
                continue;
            }

            $worked = (int) (app(TimesheetDocument::class)->month($employee, $month)['totals']['worked'] ?? 0);
            $minutes = (int) $service->get($userId, 0);

            if ($worked === 0 && $minutes === 0) {
                continue;
            }

            $result[(int) $userId] = ['worked' => $worked, 'service' => $minutes, 'percent' => $worked > 0 ? (int) round($minutes * 100 / $worked) : null];
        }

        return $result;
    }

    /**
     * The company's day now (UTC limits).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function day(): array
    {
        $local = CarbonImmutable::now(OrderResource::timezone());

        return [$local->startOfDay()->utc(), $local->endOfDay()->utc()];
    }
}
