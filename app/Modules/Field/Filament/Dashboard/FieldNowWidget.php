<?php

namespace App\Modules\Field\Filament\Dashboard;

use App\Modules\Field\Services\FieldReports;
use Filament\Widgets\Widget;

/**
 * Today, now: each technician at a service, on the way, late or free, with the day's counts (does not follow
 * the month filter). Refreshes every minute.
 */
class FieldNowWidget extends Widget
{
    use ReadsFieldFilters;

    protected string $view = 'field.dashboard.now';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    /**
     * @return array<string, mixed>
     */
    public function today(): array
    {
        return app(FieldReports::class)->today();
    }
}
