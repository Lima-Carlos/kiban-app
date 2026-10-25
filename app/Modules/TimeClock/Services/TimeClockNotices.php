<?php

namespace App\Modules\TimeClock\Services;

use App\Models\User;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Filament\Adjustments\AdjustmentResource;
use App\Modules\TimeClock\Filament\ClosingsPage;
use App\Modules\TimeClock\Filament\Punches\PunchResource;
use App\Modules\TimeClock\Filament\TimesheetPage;
use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Closing;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;

/**
 * Ponto warnings in the bell (never by e-mail) and the "Attention" box of the start page.
 *
 * - right away (in the services): correction asked/answered, month closed, disagreement, reopening;
 * - by the hourly routine, each one only once: a forgotten punch yesterday (to the employee), yesterday's
 *   summary of absences and forgotten punches (managers), the month not closed yet (from the 3rd on) and the
 *   photos that will be removed.
 */
class TimeClockNotices
{
    /** Hour (company time) after which yesterday is complete (the day turn of a night shift is at noon at the latest). */
    public const DAILY_HOUR = 12;

    /** Day of the month from which the managers hear about the month not closed. */
    public const CLOSING_REMINDER_DAY = 3;

    public function __construct(
        protected TenantContext $context,
        protected TimeClockAccess $access,
        protected Notifier $notifier,
        protected DayCalculator $calculator,
        protected MonthClosing $closing,
    ) {}

    /** The hourly routine (inside the company). Returns how many warnings were sent. */
    public function scan(): int
    {
        return $this->yesterday() + $this->notClosed() + $this->cleanupWarning();
    }

    protected function yesterday(): int
    {
        $now = CarbonImmutable::now(FilamentTimezone::get());

        if ($now->hour < self::DAILY_HOUR) {
            return 0;
        }

        $date = $now->subDay()->startOfDay();
        $key = $date->toDateString();
        $sent = 0;
        $absent = [];
        $missing = [];

        foreach (Employee::query()->active()->with(['person.user', 'workplace'])->get() as $employee) {
            // Each workplace has its own time zone: its yesterday must be complete too.
            if (CarbonImmutable::now($this->calculator->timezone($employee))->lt(CarbonImmutable::parse($key, $this->calculator->timezone($employee))->addDay()->setTime(self::DAILY_HOUR, 0))) {
                continue;
            }

            $day = $this->calculator->period($employee, $date, $date)[$key] ?? null;

            if ($day === null || ! $day['counts']) {
                continue;
            }

            if ($day['absence']) {
                $absent[] = $employee->person->name;
            }

            if (in_array('missing_punch', $day['flags'], true)) {
                $missing[] = $employee->person->name;

                $this->notifier->send([$employee->person->user], $this->notice(
                    'timeclock.notices.missing_punch',
                    ['date' => fn (): string => $date->translatedFormat(__('app.date_format'))],
                    'timeclock.notices.missing_punch_body',
                    [],
                    TimesheetPage::getUrl(['month' => $date->format('Y-m')]),
                    'heroicon-o-clock',
                    'warning',
                ), once: "tc:missing:{$employee->getKey()}:{$key}");
                $sent++;
            }
        }

        if ($absent !== [] || $missing !== []) {
            $this->notifier->send($this->access->people([TimeClockRole::Manager]), $this->notice(
                'timeclock.notices.daily_summary',
                ['date' => fn (): string => $date->translatedFormat(__('app.date_format'))],
                'timeclock.notices.daily_summary_body',
                ['absent' => $absent === [] ? '—' : implode(', ', $absent), 'missing' => $missing === [] ? '—' : implode(', ', $missing)],
                TimesheetPage::getUrl(['month' => $date->format('Y-m')]),
                'heroicon-o-clipboard-document-list',
                'warning',
            ), once: "tc:summary:{$key}");
            $sent++;
        }

        return $sent;
    }

    protected function notClosed(): int
    {
        $now = CarbonImmutable::now(FilamentTimezone::get());
        $month = $now->startOfMonth()->subMonth();

        if ($now->day < self::CLOSING_REMINDER_DAY || $this->openCount($month) === 0
            || ! $this->notifier->firstTime($this->context->get()->getKey(), 'tc:not-closed:'.$month->format('Y-m'))) {
            return 0;
        }

        $this->notifier->send($this->access->people([TimeClockRole::Manager]), $this->notice(
            'timeclock.notices.not_closed',
            ['month' => fn (): string => $this->closing->monthLabel($month)],
            'timeclock.notices.not_closed_body',
            [],
            ClosingsPage::getUrl(['month' => $month->format('Y-m')]),
            'heroicon-o-lock-open',
            'warning',
        ));

        return 1;
    }

    protected function cleanupWarning(): int
    {
        $count = app(TimeClockCleanup::class)->upcoming();

        if ($count === 0 || ! $this->notifier->firstTime($this->context->get()->getKey(), 'tc:cleanup:'.now()->format('Y-m'))) {
            return 0;
        }

        $this->notifier->send($this->access->people([TimeClockRole::Manager]), $this->notice(
            'timeclock.notices.cleanup',
            ['count' => $count],
            'timeclock.notices.cleanup_body',
            ['days' => TimeClockCleanup::WARN_DAYS],
            PunchResource::getUrl('index'),
            'heroicon-o-photo',
        ));

        return 1;
    }

    /** Employees of the month without a closing in force. */
    public function openCount(CarbonImmutable $month): int
    {
        $closed = Closing::query()->current()->whereDate('month', $month->format('Y-m-01'))->pluck('employee_id')->all();

        return $this->closing->employeesOf($month)->reject(fn (Employee $employee): bool => in_array($employee->getKey(), $closed, true))->count();
    }

    /**
     * Urgent and still open for this person (the "Attention" box). It disappears when solved.
     *
     * @return array<int, array{text: string, url: ?string, color: string}>
     */
    public function attention(User $user): array
    {
        $role = $this->access->role($user);
        $items = [];

        if (in_array($role, [TimeClockRole::Manager, TimeClockRole::Viewer], true)) {
            $pending = Adjustment::query()->where('status', Adjustment::PENDING)->count();

            if ($pending > 0) {
                $items[] = ['text' => trans_choice('timeclock.attention.pending', $pending, ['count' => $pending]), 'url' => AdjustmentResource::getUrl('index'), 'color' => 'warning'];
            }

            foreach (Closing::query()->current()->with('employee.person')->whereNotNull('disputed_at')->whereNull('signed_at')->get() as $closing) {
                $items[] = [
                    'text' => __('timeclock.attention.disputed', ['name' => $closing->employee->person->name, 'month' => $this->closing->monthLabel($closing->month)]),
                    'url' => TimesheetPage::getUrl(['employee' => $closing->employee_id, 'month' => $closing->month->format('Y-m')]),
                    'color' => 'danger',
                ];
            }

            $now = CarbonImmutable::now(FilamentTimezone::get());
            $month = $now->startOfMonth()->subMonth();

            if ($now->day >= self::CLOSING_REMINDER_DAY && ($open = $this->openCount($month)) > 0) {
                $items[] = ['text' => trans_choice('timeclock.attention.not_closed', $open, ['count' => $open, 'month' => $this->closing->monthLabel($month)]), 'url' => ClosingsPage::getUrl(['month' => $month->format('Y-m')]), 'color' => 'warning'];
            }

            $outside = Punch::query()->where('punched_at', '>=', $now->startOfDay()->utc())->whereJsonContains('flags', Punch::OUTSIDE_RADIUS)->count();

            if ($outside > 0) {
                $items[] = ['text' => trans_choice('timeclock.attention.outside', $outside, ['count' => $outside]), 'url' => PunchResource::getUrl('index'), 'color' => 'warning'];
            }
        }

        $employee = $this->access->employeeFor($user);

        if ($employee !== null) {
            foreach (Closing::query()->current()->where('employee_id', $employee->getKey())->whereNull('signed_at')->whereNull('disputed_at')->orderBy('month')->get() as $closing) {
                $items[] = ['text' => __('timeclock.attention.sign', ['month' => $this->closing->monthLabel($closing->month)]), 'url' => TimesheetPage::getUrl(['month' => $closing->month->format('Y-m')]), 'color' => 'info'];
            }

            $today = CarbonImmutable::now($this->calculator->timezone($employee))->startOfDay();

            foreach ($this->calculator->period($employee, $today->subDays(7), $today->subDay()) as $day) {
                if ($day['counts'] && in_array('missing_punch', $day['flags'], true) && $day['pending'] === 0 && ! $this->closing->isClosed($employee, $day['date'])) {
                    $items[] = [
                        'text' => __('timeclock.attention.missing_punch', ['date' => $day['date']->translatedFormat(__('app.date_format'))]),
                        'url' => TimesheetPage::getUrl(['month' => $day['date']->format('Y-m')]),
                        'color' => 'warning',
                    ];
                }
            }
        }

        return $items;
    }

    /**
     * @param  array<string, mixed>  $titleParams
     * @param  array<string, mixed>  $bodyParams
     */
    protected function notice(string $titleKey, array $titleParams, ?string $bodyKey, array $bodyParams, ?string $url, string $icon, string $color = 'info'): Notice
    {
        return new Notice($this->context->get()->getKey(), $titleKey, $titleParams, $bodyKey, $bodyParams, $url, $icon, $color);
    }
}
