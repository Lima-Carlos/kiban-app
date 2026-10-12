<?php

namespace App\Modules\Fleet\Filament\Dashboard;

use App\Modules\Fleet\Services\FleetReports;
use Filament\Widgets\Concerns\InteractsWithPageFilters;

/**
 * Period (months) and vehicle chosen at the top of the Frota dashboard.
 */
trait ReadsFleetFilters
{
    use InteractsWithPageFilters;

    protected function months(): int
    {
        $months = (int) ($this->pageFilters['period'] ?? FleetReports::DEFAULT_PERIOD);

        return in_array($months, FleetReports::PERIODS, true) ? $months : FleetReports::DEFAULT_PERIOD;
    }

    protected function vehicleId(): ?int
    {
        $vehicle = $this->pageFilters['vehicle'] ?? null;

        return filled($vehicle) ? (int) $vehicle : null;
    }

    public static function canView(): bool
    {
        return FleetDashboard::canAccess();
    }
}
