<?php

namespace App\Modules\Fleet\Filament\Dashboard;

use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Services\FleetReports;
use Filament\Widgets\ChartWidget;

/**
 * Money spent on fuel month by month. Maintenance and other expenses join this chart in part 3.
 */
class FuelSpendChartWidget extends ChartWidget
{
    use ReadsFleetFilters;

    protected ?string $maxHeight = '260px';

    public function getHeading(): string
    {
        return __('fleet.dashboard.spend_chart_all', ['currency' => FleetSetting::current()->currency]);
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getOptions(): array
    {
        return ['scales' => ['x' => ['stacked' => true], 'y' => ['stacked' => true]]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $series
     * @return array<string, mixed>
     */
    protected function dataset(array $series, string $key, string $label, string $color): array
    {
        return ['label' => $label, 'data' => array_map(fn (array $month): float => round($month[$key] / 100, 2), $series), 'backgroundColor' => $color];
    }

    protected function getData(): array
    {
        $series = app(FleetReports::class)->monthly($this->months(), $this->vehicleId());

        return [
            'datasets' => [
                $this->dataset($series, 'spent_cents', __('fleet.dashboard.fuel_spent'), config('kiban.brand.blue')),
                $this->dataset($series, 'maintenance_cents', __('fleet.maintenances.plural_title'), '#d97706'),
                $this->dataset($series, 'wash_cents', __('fleet.washes.plural_title'), '#0284c7'),
                $this->dataset($series, 'expense_cents', __('fleet.expenses.plural_title'), '#6b7280'),
            ],
            'labels' => array_column($series, 'label'),
        ];
    }
}
