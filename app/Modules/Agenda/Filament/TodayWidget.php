<?php

namespace App\Modules\Agenda\Filament;

use App\Modules\Agenda\Services\AgendaReports;
use Filament\Widgets\Widget;

/**
 * Today on the start page (desk, managers, viewers): scheduled, arrived/in service, pets ready, delivered and
 * no-shows, with the button to the calendar. Refreshes every minute.
 */
class TodayWidget extends Widget
{
    protected string $view = 'agenda.today-widget';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    /**
     * @return array<string, int>
     */
    public function today(): array
    {
        return app(AgendaReports::class)->today();
    }
}
