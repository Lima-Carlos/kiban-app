<?php

namespace App\Modules\Field\Filament\Dashboard;

use App\Modules\Field\Services\FieldFormat;
use App\Modules\Field\Services\FieldReports;
use Filament\Support\Icons\Heroicon;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

/**
 * The company's month: done orders and their value, time at the customer, punctuality, returns, the customers'
 * stars and the approved quotes.
 */
class FieldMonthWidget extends StatsOverviewWidget
{
    use ReadsFieldFilters;

    protected int|string|array $columnSpan = 'full';

    protected function getHeading(): ?string
    {
        return __('field.dashboard.month_heading', ['month' => app(FieldReports::class)->monthLabel($this->month())]);
    }

    protected function getStats(): array
    {
        $totals = app(FieldReports::class)->month($this->month())['totals'];

        return [
            Stat::make(__('field.dashboard.done'), (string) $totals['done'])
                ->description(__('field.dashboard.revenue', ['value' => FieldFormat::money($totals['revenue'])]))
                ->icon(Heroicon::OutlinedCheckCircle)
                ->color('success'),
            Stat::make(__('field.dashboard.average'), $totals['average_minutes'] !== null ? FieldFormat::duration($totals['average_minutes']) : '—')
                ->description(__('field.dashboard.average_help'))
                ->icon(Heroicon::OutlinedClock),
            Stat::make(__('field.dashboard.on_time'), $totals['on_time'] !== null ? $totals['on_time'].'%' : '—')
                ->description(__('field.dashboard.on_time_help', ['minutes' => FieldReports::ON_TIME_MINUTES]))
                ->icon(Heroicon::OutlinedMapPin)
                ->color($totals['on_time'] !== null && $totals['on_time'] < 80 ? 'warning' : null),
            Stat::make(__('field.dashboard.returns'), (string) $totals['returns'])
                ->description($totals['done'] > 0 ? __('field.dashboard.returns_help', ['percent' => (int) round($totals['returns'] * 100 / $totals['done'])]) : null)
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->color($totals['returns'] > 0 ? 'warning' : null),
            Stat::make(__('field.dashboard.rating'), $totals['rating'] !== null ? '★ '.number_format($totals['rating'], 1, __('field.dashboard.decimal'), '') : '—')
                ->description(trans_choice('field.dashboard.ratings', $totals['ratings'], ['count' => $totals['ratings']]))
                ->icon(Heroicon::OutlinedStar),
            Stat::make(__('field.dashboard.quotes'), $totals['quotes_approved'].' / '.$totals['quotes_sent'])
                ->description(__('field.dashboard.quotes_help'))
                ->icon(Heroicon::OutlinedDocumentText),
        ];
    }
}
