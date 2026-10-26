<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\TimeClockSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;

/**
 * Numbers of the Ponto dashboard (managers and viewers):
 * - right now: who is working, on a break, done, late (no punch after the expected start plus the tolerance)
 *   and who is expected later; punches outside the workplace today;
 * - a month: the company's overtime, days off/holidays, minutes short, absences, night, hour bank and the people
 *   with the most overtime, absences and bank balance. Closed months use the frozen copy.
 */
class TimeClockReports
{
    public const MONTHS = 12;

    public const RANKING = 5;

    public function __construct(
        protected DayCalculator $calculator,
        protected TimesheetDocument $document,
        protected MonthClosing $closing,
    ) {}

    /**
     * Each active employee's situation now.
     *
     * @return array{working: array<int, array{employee: Employee, since: CarbonImmutable}>, break: array<int, array{employee: Employee, since: CarbonImmutable}>, done: array<int, array{employee: Employee, since: CarbonImmutable}>, late: array<int, array{employee: Employee, start: string, minutes: int}>, later: array<int, array{employee: Employee, start: string}>, excused: array<int, Employee>, outside: int, expected: int}
     */
    public function now(): array
    {
        $result = ['working' => [], 'break' => [], 'done' => [], 'late' => [], 'later' => [], 'excused' => [], 'outside' => 0, 'expected' => 0];
        $tolerance = (int) TimeClockSetting::current()->tolerance_punch_minutes;

        foreach (Employee::query()->active()->with(['person', 'workplace'])->get()->sortBy(fn (Employee $employee): string => mb_strtolower((string) $employee->person?->name)) as $employee) {
            $now = CarbonImmutable::now($this->calculator->timezone($employee));
            $day = $this->calculator->period($employee, $now, $now)[$now->toDateString()] ?? null;

            if ($day === null || ! $day['employed']) {
                continue;
            }

            $plan = $day['type'] === 'work' ? $day['plan'] : null;
            $punches = $day['punches'];
            $result['expected'] += $plan !== null ? 1 : 0;

            if (collect($day['adjustments'])->contains(fn (Adjustment $adjustment): bool => $adjustment->type === Adjustment::EXCUSE && $adjustment->minutes === null)) {
                $result['excused'][] = $employee;

                continue;
            }

            if ($punches !== []) {
                $last = end($punches)['at'];

                if (count($punches) % 2 === 1) {
                    $result['working'][] = ['employee' => $employee, 'since' => $last];
                } elseif ($plan !== null && $now->lt($this->planEnd($day['date'], $plan))) {
                    $result['break'][] = ['employee' => $employee, 'since' => $last];
                } else {
                    $result['done'][] = ['employee' => $employee, 'since' => $last];
                }

                continue;
            }

            if ($plan === null) {
                continue;
            }

            $start = CarbonImmutable::parse($day['date']->toDateString().' '.$plan['start'], $day['date']->getTimezone());

            if ($now->gt($start->addMinutes($tolerance))) {
                $result['late'][] = ['employee' => $employee, 'start' => $plan['start'], 'minutes' => intdiv((int) $start->diffInSeconds($now, true), 60)];
            } else {
                $result['later'][] = ['employee' => $employee, 'start' => $plan['start']];
            }
        }

        $result['outside'] = Punch::query()->where('punched_at', '>=', CarbonImmutable::now(FilamentTimezone::get())->startOfDay()->utc())
            ->whereJsonContains('flags', Punch::OUTSIDE_RADIUS)->count();

        return $result;
    }

    /**
     * The company's month: totals and the rankings.
     *
     * @return array{totals: array<string, int>, bank: ?int, expired: int, employees: int, closed: int, signed: int, people: array<int, array{employee: Employee, totals: array<string, int>, bank: ?int}>}
     */
    public function month(CarbonInterface $month): array
    {
        $start = CarbonImmutable::parse($month->format('Y-m-01'));
        $totals = array_fill_keys(['expected', 'worked', 'excused', 'extra', 'extra_rest', 'deficit', 'absences', 'absence_minutes', 'night', 'night_reduced', 'missing_punches', 'warnings'], 0);
        $result = ['totals' => $totals, 'bank' => null, 'expired' => 0, 'employees' => 0, 'closed' => 0, 'signed' => 0, 'people' => []];

        foreach ($this->closing->employeesOf($start) as $employee) {
            $data = $this->document->month($employee, $start);
            $result['employees']++;
            $result['closed'] += $data['closing'] !== null ? 1 : 0;
            $result['signed'] += $data['closing']?->isSigned() ? 1 : 0;

            foreach ($data['totals'] as $field => $value) {
                $result['totals'][$field] = ($result['totals'][$field] ?? 0) + (int) $value;
            }

            if ($data['bank'] !== null) {
                $result['bank'] = ($result['bank'] ?? 0) + (int) $data['bank']['balance'];
                $result['expired'] += (int) $data['bank']['expired'];
            }

            $result['people'][] = ['employee' => $employee, 'totals' => $data['totals'], 'bank' => $data['bank']['balance'] ?? null];
        }

        return $result;
    }

    /**
     * The people with the highest value of a field (only those above zero).
     *
     * @param  array<int, array{employee: Employee, totals: array<string, int>, bank: ?int}>  $people
     * @param  callable(array{employee: Employee, totals: array<string, int>, bank: ?int}): int  $value
     * @return array<int, array{employee: Employee, value: int}>
     */
    public function top(array $people, callable $value): array
    {
        return collect($people)
            ->map(fn (array $row): array => ['employee' => $row['employee'], 'value' => $value($row)])
            ->filter(fn (array $row): bool => $row['value'] > 0)
            ->sortByDesc('value')
            ->take(self::RANKING)
            ->values()
            ->all();
    }

    /**
     * The months of the filter (this month and the 11 before).
     *
     * @return array<string, string>
     */
    public function monthOptions(): array
    {
        $options = [];
        $month = CarbonImmutable::now(FilamentTimezone::get())->startOfMonth();

        for ($i = 0; $i < self::MONTHS; $i++) {
            $options[$month->format('Y-m')] = $this->closing->monthLabel($month);
            $month = $month->subMonth();
        }

        return $options;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    protected function planEnd(CarbonImmutable $date, array $plan): CarbonImmutable
    {
        return CarbonImmutable::parse($date->toDateString().' '.$plan['start'], $date->getTimezone())
            ->addMinutes(ScheduleCalendar::span($plan['start'], $plan['end']));
    }
}
