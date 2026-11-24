<?php

namespace App\Modules\Pedidos\Filament\Orders\Pages;

use App\Filament\Support\EmptyListShowsPendingStep;
use App\Modules\Pedidos\Filament\OrderDeskPage;
use App\Modules\Pedidos\Filament\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListOrders extends ListRecords
{
    use EmptyListShowsPendingStep;

    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('new')
                ->label(__('pedidos.desk.new_order'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => OrderDeskPage::getUrl())
                ->visible(fn (): bool => OrderDeskPage::canAccess()),
        ];
    }
}
