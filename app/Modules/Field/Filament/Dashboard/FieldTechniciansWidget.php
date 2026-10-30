<?php

namespace App\Modules\Field\Filament\Dashboard;

use App\Modules\Field\Services\FieldReports;
use Filament\Widgets\Widget;

/**
 * One line per technician in the month: done orders, revenue, time at the customer, punctuality, returns and
 * the customers' stars (each name opens the technician's orders).
 */
class FieldTechniciansWidget extends Widget
{
    use ReadsFieldFilters;

    protected string $view = 'field.dashboard.technicians';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function rows(): array
    {
        return app(FieldReports::class)->month($this->month())['technicians'];
    }
}
