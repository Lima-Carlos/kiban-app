<?php

namespace App\Modules\TimeClock\Filament\Dashboard;

use App\Modules\TimeClock\Services\TimeClockReports;
use Filament\Widgets\Widget;

/**
 * Right now: working, on a break, done for the day, late and expected later (does not follow the month filter).
 * Refreshes every minute.
 */
class NowWidget extends Widget
{
    use ReadsTimeClockFilters;

    protected string $view = 'timeclock.dashboard.now';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    /**
     * @return array<string, mixed>
     */
    public function now(): array
    {
        return app(TimeClockReports::class)->now();
    }
}
