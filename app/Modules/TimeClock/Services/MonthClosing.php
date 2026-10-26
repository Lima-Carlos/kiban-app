<?php

namespace App\Modules\TimeClock\Services;

use App\Models\User;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Filament\TimesheetPage;
use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Closing;
use App\Modules\TimeClock\Models\Employee;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\People\PersonRuleException;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The closing of the month, per employee:
 * - only after the month is over (and after the day turn of the 1st, so a night shift is complete) and with
 *   no correction waiting for an answer;
 * - closing freezes a copy of every day and of the totals (with a SHA-256 of the copy); from then on that
 *   month does not change and the time sheet shown and printed is the copy;
 * - the employee signs it electronically (who, when, IP, device) or disagrees with a reason;
 * - the manager may reopen it only before the signature (the old closing stays in the history).
 */
class MonthClosing
{
    public function __construct(
        protected TenantContext $context,
        protected DayCalculator $calculator,
        protected HourBank $bank,
        protected TimeClockAccess $access,
        protected Notifier $notifier,
    ) {}

    public function isClosed(Employee $employee, CarbonInterface $date): bool
    {
        return $this->current($employee, $date) !== null;
    }

    /** The closing in force of the month of this date. */
    public function current(Employee $employee, CarbonInterface $date): ?Closing
    {
        return Closing::query()->current()->where('employee_id', $employee->getKey())
            ->whereDate('month', $date->format('Y-m-01'))
            ->first();
    }

    /** The month is over, including the night shift of its last day (the day turns at noon at the latest). */
    public function canClose(CarbonInterface $month, string $timezone): bool
    {
        $next = CarbonImmutable::parse($month->format('Y-m-01'), $timezone)->addMonth();

        return CarbonImmutable::now($timezone)->gte($next->setTimeFromTimeString(DayCalculator::DAY_TURN_LATEST));
    }

    /**
     * Employees with days in the month (hired before its end and not dismissed before its start).
     *
     * @return EloquentCollection<int, Employee>
     */
    public function employeesOf(CarbonInterface $month): EloquentCollection
    {
        $start = CarbonImmutable::parse($month->format('Y-m-01'));

        return Employee::query()->with(['person', 'workplace'])
            ->whereNotNull('admitted_on')
            ->whereDate('admitted_on', '<=', $start->endOfMonth()->toDateString())
            ->where(fn (Builder $query) => $query->whereNull('terminated_on')->orWhereDate('terminated_on', '>=', $start->toDateString()))
            ->where(fn (Builder $query) => $query->where('is_active', true)->orWhereNotNull('terminated_on'))
            ->get()
            ->sortBy(fn (Employee $employee): string => mb_strtolower((string) $employee->person?->name))
            ->values();
    }

    /** What stops the closing (null = it can be closed). */
    public function blocker(Employee $employee, CarbonInterface $month): ?string
    {
        $start = CarbonImmutable::parse($month->format('Y-m-01'));

        if (! $this->canClose($start, $this->calculator->timezone($employee))) {
            return __('timeclock.closing.errors.not_over');
        }

        if ($this->isClosed($employee, $start)) {
            return __('timeclock.closing.errors.already_closed');
        }

        $pending = Adjustment::query()->where('employee_id', $employee->getKey())->where('status', Adjustment::PENDING)
            ->whereBetween('date', [$start->toDateString(), $start->endOfMonth()->toDateString()])
            ->count();

        return $pending > 0 ? trans_choice('timeclock.closing.errors.pending', $pending, ['count' => $pending]) : null;
    }

    public function close(Employee $employee, CarbonInterface $month, User $by): Closing
    {
        $start = CarbonImmutable::parse($month->format('Y-m-01'));

        if (($blocker = $this->blocker($employee, $start)) !== null) {
            throw new PersonRuleException($blocker);
        }

        $days = $this->calculator->period($employee, $start, $start->endOfMonth());
        $snapshot = $this->serialize($days);
        $totals = $this->calculator->totals($days);
        $bank = $this->bank->enabled() ? $this->bank->balance($employee, $start->endOfMonth()) : null;

        try {
            $closing = DB::transaction(function () use ($employee, $start, $snapshot, $totals, $bank, $by): Closing {
                $closing = new Closing;
                $closing->forceFill([
                    'employee_id' => $employee->getKey(),
                    'month' => $start->toDateString(),
                    'days' => $snapshot,
                    'totals' => $totals,
                    'bank' => $bank,
                    'snapshot_hash' => $this->hash($snapshot, $totals, $bank),
                    'closed_by' => $by->getKey(),
                    'closed_at' => now(),
                ])->save();

                return $closing;
            });
        } catch (UniqueConstraintViolationException) {
            throw new PersonRuleException(__('timeclock.closing.errors.already_closed'));
        }

        $this->notifier->send([$employee->person?->user], $this->notice(
            'timeclock.notices.closing_ready',
            ['month' => fn (): string => $this->monthLabel($start)],
            'timeclock.notices.closing_ready_body',
            [],
            $this->url($employee, $start, own: true),
            'heroicon-o-document-check',
        ), except: $by);

        return $closing;
    }

    /**
     * Closes everyone that can be closed. Returns the number closed and, per name, why the others were not.
     *
     * @return array{closed: int, skipped: array<string, string>}
     */
    public function closeAll(CarbonInterface $month, User $by): array
    {
        $result = ['closed' => 0, 'skipped' => []];

        foreach ($this->employeesOf($month) as $employee) {
            if ($this->isClosed($employee, $month)) {
                continue;
            }

            try {
                $this->close($employee, $month, $by);
                $result['closed']++;
            } catch (PersonRuleException $exception) {
                $result['skipped'][(string) $employee->person?->name] = $exception->getMessage();
            }
        }

        return $result;
    }

    public function reopen(Closing $closing, User $by, string $reason): void
    {
        if ($closing->reopened_at !== null) {
            throw new PersonRuleException(__('timeclock.closing.errors.already_reopened'));
        }

        if ($closing->isSigned()) {
            throw new PersonRuleException(__('timeclock.closing.errors.signed'));
        }

        if (trim($reason) === '') {
            throw new PersonRuleException(__('timeclock.errors.reason_required'));
        }

        $closing->forceFill(['reopened_by' => $by->getKey(), 'reopened_at' => now(), 'reopen_reason' => trim($reason)])->save();

        $this->notifier->send([$closing->employee->person?->user], $this->notice(
            'timeclock.notices.closing_reopened',
            ['month' => fn (): string => $this->monthLabel($closing->month)],
            'timeclock.notices.reason_body',
            ['text' => trim($reason)],
            $this->url($closing->employee, $closing->month, own: true),
            'heroicon-o-lock-open',
        ), except: $by);
    }

    /** The employee signs their own time sheet. */
    public function sign(Closing $closing, User $by, ?string $ip, ?string $device): void
    {
        $this->assertOwn($closing, $by);

        if ($closing->isSigned()) {
            throw new PersonRuleException(__('timeclock.closing.errors.signed'));
        }

        $at = now();
        $closing->forceFill([
            'signed_by' => $by->getKey(),
            'signed_at' => $at,
            'signature_ip' => $ip,
            'signature_device' => $device !== null ? mb_substr($device, 0, 255) : null,
            'signature_hash' => hash('sha256', implode('|', [$closing->snapshot_hash, $by->getKey(), $at->toIso8601String(), (string) $ip])),
        ])->save();
    }

    /** The employee does not agree: the managers hear about it (they may reopen and correct). */
    public function dispute(Closing $closing, User $by, string $reason): void
    {
        $this->assertOwn($closing, $by);

        if ($closing->isSigned()) {
            throw new PersonRuleException(__('timeclock.closing.errors.signed'));
        }

        if (trim($reason) === '') {
            throw new PersonRuleException(__('timeclock.errors.reason_required'));
        }

        $closing->forceFill(['disputed_at' => now(), 'dispute_reason' => trim($reason)])->save();

        $this->notifier->send($this->access->people([TimeClockRole::Manager]), $this->notice(
            'timeclock.notices.closing_disputed',
            ['name' => $closing->employee->person->name, 'month' => fn (): string => $this->monthLabel($closing->month)],
            'timeclock.notices.reason_body',
            ['text' => str(trim($reason))->limit(150)->toString()],
            $this->url($closing->employee, $closing->month),
            'heroicon-o-hand-raised',
            'warning',
        ), except: $by);
    }

    /**
     * The days of a closing in the same shape as the calculation (for the screen and the PDF).
     *
     * @return array<string, array<string, mixed>>
     */
    public function days(Closing $closing): array
    {
        $tz = $this->calculator->timezone($closing->employee);
        $days = [];

        foreach ((array) $closing->days as $key => $day) {
            $days[$key] = [
                'date' => CarbonImmutable::parse($key, $tz),
                'punches' => array_map(fn (array $punch): array => ['at' => CarbonImmutable::parse($punch['at'])->setTimezone($tz), 'kind' => $punch['kind'], 'id' => null], $day['punches']),
                'ignored' => array_fill(0, (int) $day['ignored'], null),
                'adjustments' => array_map(fn (?int $minutes): Adjustment => (new Adjustment)->forceFill(['type' => Adjustment::EXCUSE, 'minutes' => $minutes]), $day['excuses']),
                'pending' => 0,
            ] + $day;
        }

        return $days;
    }

    /** The frozen copy still matches its hash. */
    public function intact(Closing $closing): bool
    {
        return hash_equals($closing->snapshot_hash, $this->hash((array) $closing->days, (array) $closing->totals, $closing->bank));
    }

    /**
     * @param  array<string, array<string, mixed>>  $days
     * @return array<string, array<string, mixed>>
     */
    protected function serialize(array $days): array
    {
        $copy = [];

        foreach ($days as $key => $day) {
            $copy[$key] = [
                'type' => $day['type'],
                'plan' => $day['plan'] === null ? null : array_intersect_key($day['plan'], array_flip(['start', 'end', 'break_start', 'break_end', 'minutes'])),
                'holiday' => $day['holiday'],
                'moment' => $day['moment'],
                'employed' => $day['employed'],
                'counts' => $day['counts'],
                'punches' => array_map(fn (array $punch): array => ['at' => $punch['at']->toIso8601String(), 'kind' => $punch['kind']], $day['punches']),
                'ignored' => count($day['ignored']),
                'excuses' => collect($day['adjustments'])->where('type', Adjustment::EXCUSE)->map(fn (Adjustment $adjustment): ?int => $adjustment->minutes)->values()->all(),
            ] + array_intersect_key($day, array_flip(['expected', 'worked', 'excused', 'extra', 'extra_rest', 'deficit', 'absence', 'night', 'night_reduced', 'flags']));
        }

        return $copy;
    }

    /**
     * @param  array<string, mixed>  $days
     * @param  array<string, mixed>  $totals
     * @param  array<string, mixed>|null  $bank
     */
    protected function hash(array $days, array $totals, ?array $bank): string
    {
        return hash('sha256', json_encode([$days, $totals, $bank], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    protected function assertOwn(Closing $closing, User $by): void
    {
        if ($closing->reopened_at !== null || $closing->employee?->person?->user_id !== $by->getKey()) {
            throw new PersonRuleException(__('timeclock.closing.errors.not_yours'));
        }
    }

    public function monthLabel(CarbonInterface $month): string
    {
        return ucfirst(CarbonImmutable::parse($month->format('Y-m-01'))->translatedFormat('F Y'));
    }

    protected function url(Employee $employee, CarbonInterface $month, bool $own = false): string
    {
        return TimesheetPage::getUrl(($own ? [] : ['employee' => $employee->getKey()]) + ['month' => $month->format('Y-m')]);
    }

    /**
     * @param  array<string, mixed>  $titleParams
     * @param  array<string, mixed>  $bodyParams
     */
    protected function notice(string $titleKey, array $titleParams, ?string $bodyKey, array $bodyParams, string $url, string $icon, string $color = 'info'): Notice
    {
        return new Notice($this->context->get()->getKey(), $titleKey, $titleParams, $bodyKey, $bodyParams, $url, $icon, $color);
    }
}
