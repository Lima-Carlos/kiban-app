<?php

namespace App\Digest;

use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Expenses\Models\Claim;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Enums\QuoteStatus;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Fleet\Enums\FuelUpStatus;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Services\DayCalculator;
use App\Modules\TimeClock\Services\ScheduleCalendar;
use App\Support\Money;
use Carbon\CarbonImmutable;

/**
 * The week in numbers of each system, for the weekly summary (called by each module's weeklySummary()).
 * Only totals: never a list of names.
 */
class ModuleWeek
{
    /** @return array<int, string> */
    public function timeClock(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $calculator = app(DayCalculator::class);
        $totals = ['extra' => 0, 'absences' => 0, 'late' => 0, 'missing' => 0, 'people_extra' => 0];
        $employees = Employee::query()->active()->whereNotNull('admitted_on')->whereDate('admitted_on', '<=', $to->toDateString())->get();

        if ($employees->isEmpty()) {
            return [];
        }

        foreach ($employees as $employee) {
            $week = $calculator->totals($calculator->period($employee, $from, $to));
            $extra = (int) $week['extra'] + (int) $week['extra_rest'];
            $totals['extra'] += $extra;
            $totals['people_extra'] += $extra > 0 ? 1 : 0;
            $totals['absences'] += (int) $week['absences'];
            $totals['late'] += max(0, (int) $week['deficit'] - (int) $week['absence_minutes']);
            $totals['missing'] += (int) $week['missing_punches'];
        }

        $lines = array_filter([
            $totals['extra'] > 0 ? trans_choice('digest.timeclock.extra', $totals['people_extra'], ['hours' => ScheduleCalendar::hours($totals['extra']), 'count' => $totals['people_extra']]) : null,
            $totals['absences'] > 0 ? trans_choice('digest.timeclock.absences', $totals['absences'], ['count' => $totals['absences']]) : null,
            $totals['late'] > 0 ? __('digest.timeclock.late', ['hours' => ScheduleCalendar::hours($totals['late'])]) : null,
            $totals['missing'] > 0 ? trans_choice('digest.timeclock.missing', $totals['missing'], ['count' => $totals['missing']]) : null,
        ]);

        return $lines !== [] ? array_values($lines) : [__('digest.timeclock.clean', ['count' => $employees->count()])];
    }

    /** @return array<int, string> */
    public function fleet(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $fuel = FuelUp::query()->where('status', FuelUpStatus::Completed->value)->whereBetween('fueled_at', [$from->utc(), $to->utc()])
            ->selectRaw('count(*) as total, coalesce(sum(total_cents), 0) as cents, coalesce(sum(volume), 0) as volume, count(distinct vehicle_id) as vehicles')
            ->first();

        if ((int) $fuel->total === 0) {
            return [__('digest.fleet.no_fuel')];
        }

        return [trans_choice('digest.fleet.fuel', (int) $fuel->total, [
            'amount' => Money::format((int) $fuel->cents), 'count' => (int) $fuel->total,
            'liters' => number_format((float) $fuel->volume, 0, ',', '.'), 'vehicles' => (int) $fuel->vehicles,
        ])];
    }

    /** @return array<int, string> */
    public function field(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $done = WorkOrder::query()->where('status', OrderStatus::Done->value)->whereBetween('finished_at', [$from->utc(), $to->utc()]);
        $count = (clone $done)->count();
        $rating = (clone $done)->whereNotNull('rating')->avg('rating');
        $late = WorkOrder::query()->whereIn('status', [OrderStatus::Open->value, OrderStatus::Scheduled->value])->where('scheduled_at', '<', CarbonImmutable::now()->utc())->count();
        $quotes = Quote::query()->where('status', QuoteStatus::Sent->value)->count();

        return array_values(array_filter([
            trans_choice('digest.field.done', $count, ['count' => $count]),
            $rating !== null ? __('digest.field.rating', ['rating' => number_format((float) $rating, 1, ',', '')]) : null,
            $late > 0 ? trans_choice('digest.field.late', $late, ['count' => $late]) : null,
            $quotes > 0 ? trans_choice('digest.field.quotes', $quotes, ['count' => $quotes]) : null,
        ]));
    }

    /** @return array<int, string> */
    public function agenda(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $week = Appointment::query()->whereBetween('starts_at', [$from->utc(), $to->utc()]);
        $served = (clone $week)->whereIn('status', [AppointmentStatus::Ready->value, AppointmentStatus::Delivered->value])->count();
        $noShow = (clone $week)->where('status', AppointmentStatus::NoShow->value)->count();

        return array_values(array_filter([
            trans_choice('digest.agenda.served', $served, ['count' => $served]),
            $noShow > 0 ? trans_choice('digest.agenda.no_show', $noShow, ['count' => $noShow]) : null,
        ]));
    }

    /** @return array<int, string> */
    public function expenses(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $week = Claim::query()->whereBetween('spent_on', [$from->toDateString(), $to->toDateString()])->whereIn('status', [Claim::APPROVED, Claim::PAID]);
        $cents = (int) (clone $week)->sum('amount_cents');
        $pending = Claim::query()->where('status', Claim::PENDING)->count();

        return array_values(array_filter([
            $cents > 0 ? __('digest.expenses.approved', ['amount' => Money::format($cents)]) : null,
            $pending > 0 ? trans_choice('digest.expenses.pending', $pending, ['count' => $pending]) : null,
        ]));
    }
}
