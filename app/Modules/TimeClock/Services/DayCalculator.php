<?php

namespace App\Modules\TimeClock\Services;

use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\TimeClockSetting;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Collection;

/**
 * The calculation of each working day of an employee (never stored: always from the punches, the approved
 * corrections, the schedule and the rules, so a correction is reflected at once; a closed month keeps its
 * own copy in part 5).
 *
 * - Which day a punch belongs to: from midnight to midnight, except after a shift that crosses midnight —
 *   then the day turns 4 hours after its end (at most at noon), so the punch-out of a night shift counts
 *   for the night it started.
 * - Punches are paired in order (in/out, in/out...). An odd number means a forgotten punch: only the complete
 *   pairs count and the day is flagged.
 * - Tolerance (Brazil, CLT art. 58 §1): when every punch is within the tolerance of its expected time and the
 *   total is within the daily tolerance, the day counts as exactly the expected; otherwise every minute counts.
 * - Working on a day off or a holiday: every minute is overtime at the rest-day percentage.
 * - Night minutes (and the reduced night hour in Brazil), and in Brazil the warnings of a short break
 *   (intrajornada) and a short rest between days (interjornada, less than 11 hours).
 */
class DayCalculator
{
    /** Hours after the end of a night shift until the day turns. */
    public const DAY_TURN_MARGIN_HOURS = 4;

    /** Latest time of the next day for the turn. */
    public const DAY_TURN_LATEST = '12:00';

    /** Brazil: rest between two working days. */
    public const MIN_REST_MINUTES = 11 * 60;

    public function __construct(
        protected ScheduleCalendar $calendar,
    ) {}

    public function timezone(Employee $employee): string
    {
        return $employee->workplace?->timezone ?? FilamentTimezone::get();
    }

    /**
     * Each day of the period, calculated.
     *
     * @return array<string, array<string, mixed>>
     */
    public function period(Employee $employee, CarbonInterface $from, CarbonInterface $to): array
    {
        $tz = $this->timezone($employee);
        $settings = TimeClockSetting::current();
        $from = CarbonImmutable::parse($from->toDateString(), $tz);
        $to = CarbonImmutable::parse($to->toDateString(), $tz);
        $today = CarbonImmutable::now($tz)->startOfDay();

        // One day more on each side: the day turns depend on the neighbours.
        $days = $this->calendar->period($employee, $from->subDays(2), $to->addDay());
        $start = $this->dayStart($from->subDay(), $days, $tz);
        $end = $this->dayStart($to->addDay(), $days, $tz);

        $punches = Punch::query()->where('employee_id', $employee->getKey())
            ->whereBetween('punched_at', [$start->utc(), $end->utc()])
            ->orderBy('punched_at')->orderBy('nsr')
            ->get();
        $adjustments = Adjustment::query()->approved()->where('employee_id', $employee->getKey())
            ->whereBetween('date', [$from->subDay()->toDateString(), $to->toDateString()])
            ->get()
            ->groupBy(fn (Adjustment $adjustment): string => $adjustment->date->toDateString());
        $pending = Adjustment::query()->where('status', Adjustment::PENDING)->where('employee_id', $employee->getKey())
            ->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->get()
            ->countBy(fn (Adjustment $adjustment): string => $adjustment->date->toDateString());

        $results = [];
        $previousOut = $this->lastOut($this->effective($punches, $adjustments->get($from->subDay()->toDateString(), collect()), $this->dayStart($from->subDay(), $days, $tz), $this->dayStart($from, $days, $tz), $tz));

        for ($date = $from; $date->lte($to); $date = $date->addDay()) {
            $key = $date->toDateString();
            $effective = $this->effective($punches, $adjustments->get($key, collect()), $this->dayStart($date, $days, $tz), $this->dayStart($date->addDay(), $days, $tz), $tz);
            $results[$key] = $this->day($employee, $date, $days[$key], $effective, $adjustments->get($key, collect()), $previousOut, $settings, $today, (int) ($pending[$key] ?? 0));
            $previousOut = $this->lastOut($effective) ?? $previousOut;
        }

        return $results;
    }

    /**
     * Totals of a period (only the days already lived and with the person employed).
     *
     * @param  array<string, array<string, mixed>>  $days
     * @return array<string, int>
     */
    public function totals(array $days): array
    {
        $totals = array_fill_keys(['expected', 'worked', 'excused', 'extra', 'extra_rest', 'deficit', 'absences', 'absence_minutes', 'night', 'night_reduced', 'missing_punches', 'warnings'], 0);

        foreach ($days as $day) {
            if (! $day['counts']) {
                continue;
            }

            foreach (['expected', 'worked', 'excused', 'extra', 'extra_rest', 'deficit', 'night', 'night_reduced'] as $field) {
                $totals[$field] += $day[$field];
            }

            $totals['absences'] += $day['absence'] ? 1 : 0;
            $totals['absence_minutes'] += $day['absence'] ? $day['deficit'] : 0;
            $totals['missing_punches'] += in_array('missing_punch', $day['flags'], true) ? 1 : 0;
            $totals['warnings'] += count(array_diff($day['flags'], ['missing_punch']));
        }

        return $totals;
    }

    /**
     * @param  array{date: CarbonImmutable, plan: ?array, holiday: ?string, schedule: mixed}  $calendarDay
     * @param  Collection<int, array{at: CarbonImmutable, kind: string, id: ?int}>  $effective
     * @param  Collection<int, Adjustment>  $adjustments
     * @return array<string, mixed>
     */
    protected function day(Employee $employee, CarbonImmutable $date, array $calendarDay, Collection $effective, Collection $adjustments, ?CarbonImmutable $previousOut, TimeClockSetting $settings, CarbonImmutable $today, int $pending): array
    {
        $plan = $calendarDay['plan'];
        $employed = $employee->admitted_on !== null && $date->toDateString() >= $employee->admitted_on->toDateString()
            && ($employee->terminated_on === null || $date->toDateString() <= $employee->terminated_on->toDateString());
        $moment = $date->lt($today) ? 'past' : ($date->equalTo($today) ? 'today' : 'future');
        $type = $calendarDay['holiday'] !== null ? 'holiday' : ($plan !== null ? 'work' : ($calendarDay['schedule'] !== null ? 'off' : 'no_schedule'));

        $result = [
            'date' => $date, 'type' => $type, 'plan' => $plan, 'holiday' => $calendarDay['holiday'], 'moment' => $moment,
            'employed' => $employed, 'counts' => $employed && $moment !== 'future',
            'punches' => $effective->all(),
            'ignored' => $adjustments->where('type', Adjustment::IGNORE_PUNCH)->pluck('punch_id')->filter()->values()->all(),
            'adjustments' => $adjustments->values()->all(), 'pending' => $pending,
            'expected' => 0, 'worked' => 0, 'excused' => 0, 'extra' => 0, 'extra_rest' => 0, 'deficit' => 0,
            'absence' => false, 'night' => 0, 'night_reduced' => 0, 'flags' => [],
        ];

        if (! $result['counts']) {
            return $result;
        }

        $pairs = $effective->values()->chunk(2)->filter(fn (Collection $pair): bool => $pair->count() === 2)->map(fn (Collection $pair): array => [$pair->first()['at'], $pair->last()['at']])->values();
        $worked = (int) $pairs->sum(fn (array $pair): int => intdiv((int) $pair[0]->diffInSeconds($pair[1], true), 60));
        $expected = $plan['minutes'] ?? 0;
        $excuses = $adjustments->where('type', Adjustment::EXCUSE);
        $excused = $excuses->contains(fn (Adjustment $excuse): bool => $excuse->minutes === null)
            ? $expected
            : min($expected, (int) $excuses->sum('minutes'));

        $result['expected'] = $expected;
        $result['worked'] = $worked;
        $result['excused'] = $excused;

        if ($effective->count() % 2 === 1 && $moment === 'past') {
            $result['flags'][] = 'missing_punch';
        }

        if ($type === 'work') {
            $counted = $this->withinTolerance($date, $plan, $effective, $settings) ? $expected : $worked;
            $balance = $counted + $excused - $expected;
            $result['extra'] = max(0, $balance);
            $result['deficit'] = $moment === 'past' || $balance > 0 ? max(0, -$balance) : 0;
            $result['absence'] = $moment === 'past' && $expected > 0 && $effective->isEmpty() && $excused < $expected;
        } elseif ($type !== 'no_schedule') {
            // Day off or holiday: every minute worked is overtime at the rest-day percentage.
            $result['extra_rest'] = $worked;
        }

        $result['night'] = $this->nightMinutes($pairs, $settings, $date->getTimezone()->getName());
        $result['night_reduced'] = $settings->night_reduced_hour ? (int) round($result['night'] * 60 / 52.5) : $result['night'];

        if ($settings->isBrazil()) {
            $result['flags'] = array_merge($result['flags'], $this->legalWarnings($pairs, $worked, $previousOut));
        }

        return $result;
    }

    /**
     * The punches that count for a day: the real ones in its window (minus the ones left out) plus the
     * forgotten ones added for that day, in time order.
     *
     * @param  Collection<int, Punch>  $punches
     * @param  Collection<int, Adjustment>  $adjustments
     * @return Collection<int, array{at: CarbonImmutable, kind: string, id: ?int}>
     */
    protected function effective(Collection $punches, Collection $adjustments, CarbonImmutable $start, CarbonImmutable $end, string $tz): Collection
    {
        $ignored = $adjustments->where('type', Adjustment::IGNORE_PUNCH)->pluck('punch_id')->filter()->all();

        $real = $punches
            ->filter(fn (Punch $punch): bool => $punch->punched_at->gte($start) && $punch->punched_at->lt($end) && ! in_array($punch->getKey(), $ignored, true))
            ->map(fn (Punch $punch): array => ['at' => $punch->punched_at->setTimezone($tz), 'kind' => 'real', 'id' => $punch->getKey()]);

        $added = $adjustments->where('type', Adjustment::ADD_PUNCH)->filter(fn (Adjustment $adjustment): bool => $adjustment->punch_at !== null)
            ->map(fn (Adjustment $adjustment): array => ['at' => $adjustment->punch_at->setTimezone($tz), 'kind' => 'added', 'id' => $adjustment->getKey()]);

        return $real->concat($added)->sortBy(fn (array $punch): int => $punch['at']->getTimestamp())->values();
    }

    /**
     * When a working day starts: midnight, or later after a shift of the day before that crossed midnight.
     *
     * @param  array<string, array<string, mixed>>  $days
     */
    protected function dayStart(CarbonImmutable $date, array $days, string $tz): CarbonImmutable
    {
        $midnight = CarbonImmutable::parse($date->toDateString(), $tz);
        $previous = $days[$date->subDay()->toDateString()]['plan'] ?? null;

        if ($previous === null || ScheduleCalendar::minutesOf($previous['end']) > ScheduleCalendar::minutesOf($previous['start'])) {
            return $midnight;
        }

        $turn = $midnight->addMinutes(ScheduleCalendar::minutesOf($previous['end']))->addHours(self::DAY_TURN_MARGIN_HOURS);
        $latest = $midnight->addMinutes(ScheduleCalendar::minutesOf(self::DAY_TURN_LATEST));

        return $turn->lt($latest) ? $turn : $latest;
    }

    /**
     * Every punch within the tolerance of its expected time, and the total within the daily tolerance.
     *
     * @param  Collection<int, array{at: CarbonImmutable, kind: string, id: ?int}>  $effective
     */
    protected function withinTolerance(CarbonImmutable $date, ?array $plan, Collection $effective, TimeClockSetting $settings): bool
    {
        if ($plan === null) {
            return false;
        }

        $start = CarbonImmutable::parse($date->toDateString().' '.$plan['start'], $date->getTimezone());
        $marks = [$start];

        if ($plan['break_start'] !== null && $plan['break_end'] !== null) {
            $marks[] = $start->addMinutes(ScheduleCalendar::span($plan['start'], $plan['break_start']));
            $marks[] = $start->addMinutes(ScheduleCalendar::span($plan['start'], $plan['break_end']));
        }

        $marks[] = $start->addMinutes(ScheduleCalendar::span($plan['start'], $plan['end']));

        if ($effective->count() !== count($marks)) {
            return false;
        }

        $total = 0;

        foreach ($effective->values() as $index => $punch) {
            $deviation = intdiv((int) $punch['at']->diffInSeconds($marks[$index], true), 60);

            if ($deviation > $settings->tolerance_punch_minutes) {
                return false;
            }

            $total += $deviation;
        }

        return $total <= $settings->tolerance_day_minutes;
    }

    /**
     * Worked minutes inside the night hours (each night from the start to the end, crossing midnight).
     *
     * @param  Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $pairs
     */
    protected function nightMinutes(Collection $pairs, TimeClockSetting $settings, string $tz): int
    {
        $total = 0;
        $nightStart = ScheduleCalendar::minutesOf((string) $settings->night_start);
        $nightLength = ScheduleCalendar::span((string) $settings->night_start, (string) $settings->night_end);

        foreach ($pairs as [$in, $out]) {
            for ($day = CarbonImmutable::parse($in->toDateString(), $tz)->subDay(); $day->lte($out); $day = $day->addDay()) {
                $from = $day->addMinutes($nightStart);
                $to = $from->addMinutes($nightLength);
                $overlapStart = $in->gt($from) ? $in : $from;
                $overlapEnd = $out->lt($to) ? $out : $to;

                if ($overlapEnd->gt($overlapStart)) {
                    $total += intdiv((int) $overlapStart->diffInSeconds($overlapEnd, true), 60);
                }
            }
        }

        return $total;
    }

    /**
     * Brazil: break too short for the hours worked (intrajornada) and less than 11 hours since the last day
     * (interjornada). Warnings only.
     *
     * @param  Collection<int, array{0: CarbonImmutable, 1: CarbonImmutable}>  $pairs
     * @return array<int, string>
     */
    protected function legalWarnings(Collection $pairs, int $worked, ?CarbonImmutable $previousOut): array
    {
        $flags = [];
        $longestBreak = 0;

        for ($i = 1; $i < $pairs->count(); $i++) {
            $longestBreak = max($longestBreak, intdiv((int) $pairs[$i - 1][1]->diffInSeconds($pairs[$i][0], true), 60));
        }

        if (($worked > 360 && $longestBreak < 60) || ($worked > 240 && $worked <= 360 && $longestBreak < 15)) {
            $flags[] = 'short_break';
        }

        if ($previousOut !== null && $pairs->isNotEmpty() && intdiv((int) $previousOut->diffInSeconds($pairs->first()[0], true), 60) < self::MIN_REST_MINUTES) {
            $flags[] = 'short_rest';
        }

        return $flags;
    }

    /**
     * @param  Collection<int, array{at: CarbonImmutable, kind: string, id: ?int}>  $effective
     */
    protected function lastOut(Collection $effective): ?CarbonImmutable
    {
        return $effective->count() >= 2 && $effective->count() % 2 === 0 ? $effective->last()['at'] : null;
    }
}
