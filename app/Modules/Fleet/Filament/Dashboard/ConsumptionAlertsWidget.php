<?php

namespace App\Modules\Fleet\Filament\Dashboard;

use App\Modules\Fleet\Services\FleetReports;
use Filament\Widgets\Widget;

/**
 * Vehicles whose last full tank was much worse than their own average: worth a look by the mechanic.
 */
class ConsumptionAlertsWidget extends Widget
{
    use ReadsFleetFilters;

    protected string $view = 'fleet.dashboard.consumption-alerts';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAlerts(): array
    {
        return app(FleetReports::class)->consumptionAlerts();
    }
}
