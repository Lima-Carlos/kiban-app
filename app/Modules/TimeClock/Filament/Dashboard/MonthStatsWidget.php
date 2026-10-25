<?php

namespace App\Modules\TimeClock\Filament\Dashboard;

use App\Modules\TimeClock\Filament\ClosingsPage;
use App\Modules\TimeClock\Services\MonthClosing;
use App\Modules\TimeClock\Services\ScheduleCalendar;
use App\Modules\TimeClock\Services\TimeClockReports;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The company's month: overtime, days off/holidays, minutes short, absences, night hours, hour bank and how
 * many months are closed and signed.
 */
class MonthStatsWidget extends StatsOverviewWidget
{
    use ReadsTimeClockFilters;

    protected int|string|array $columnSpan = 'full';

    protected function getHeading(): ?string
    {
        return __('timeclock.dashboard.month_heading', ['month' => app(MonthClosing::class)->monthLabel($this->month())]);
    }

    protected function getStats(): array
    {
        $month = app(TimeClockReports::class)->month($this->month());
        $totals = $month['totals'];
        $h = fn (int $minutes): string => ScheduleCalendar::hours($minutes);

        $stats = [
            Stat::make(__('timeclock.timesheet.totals.extra'), $h($totals['extra']))
                ->icon(Heroicon::OutlinedArrowTrendingUp)
                ->color('success'),
            Stat::make(__('timeclock.timesheet.totals.extra_rest'), $h($totals['extra_rest']))
                ->icon(Heroicon::OutlinedSun),
            Stat::make(__('timeclock.timesheet.totals.deficit'), $h($totals['deficit'] - $totals['absence_minutes']))
                ->icon(Heroicon::OutlinedArrowTrendingDown)
                ->color('danger'),
            Stat::make(__('timeclock.timesheet.totals.absences'), (string) $totals['absences'])
                ->description(trans_choice('timeclock.dashboard.missing_punches', $totals['missing_punches'], ['count' => $totals['missing_punches']]))
                ->icon(Heroicon::OutlinedUserMinus),
            Stat::make(__('timeclock.timesheet.totals.night'), $h($totals['night_reduced']))
                ->icon(Heroicon::OutlinedMoon),
        ];

        if ($month['bank'] !== null) {
            $stats[] = Stat::make(__('timeclock.timesheet.totals.bank'), ($month['bank'] < 0 ? '−' : '+').$h(abs($month['bank'])))
                ->description($month['expired'] > 0 ? __('timeclock.timesheet.bank_expired', ['hours' => $h($month['expired'])]) : __('timeclock.dashboard.bank_help'))
                ->icon(Heroicon::OutlinedBanknotes)
                ->color($month['expired'] > 0 ? 'warning' : null);
        }

        if (! $this->onHome()) {
            $stats[] = Stat::make(__('timeclock.closing.title'), __('timeclock.dashboard.closed', ['closed' => $month['closed'], 'total' => $month['employees']]))
                ->description(__('timeclock.dashboard.signed', ['count' => $month['signed']]))
                ->icon(Heroicon::OutlinedLockClosed)
                ->url(ClosingsPage::getUrl(['month' => $this->month()->format('Y-m')]));
        }

        return $stats;
    }
}
