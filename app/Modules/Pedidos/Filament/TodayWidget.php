<?php

namespace App\Modules\Pedidos\Filament;

use App\Modules\Pedidos\Services\PedidosReports;
use Filament\Widgets\Widget;

/**
 * Today on the start page: orders new, preparing, ready, on the way, delivered, the number of orders and the sales
 * of the day, with the buttons to the board and the quick order. Refreshes every minute.
 */
class TodayWidget extends Widget
{
    protected string $view = 'pedidos.today-widget';

    protected int|string|array $columnSpan = 'full';

    protected ?string $pollingInterval = '60s';

    /**
     * @return array<string, int>
     */
    public function today(): array
    {
        return app(PedidosReports::class)->today();
    }
}
