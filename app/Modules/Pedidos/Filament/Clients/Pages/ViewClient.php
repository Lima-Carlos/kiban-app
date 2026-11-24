<?php

namespace App\Modules\Pedidos\Filament\Clients\Pages;

use App\Modules\Pedidos\Filament\Clients\ClientResource;
use App\Modules\Pedidos\Filament\OrderDeskPage;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Straight to a new order with this customer found.
            Action::make('order')
                ->label(__('pedidos.desk.new_order'))
                ->icon(Heroicon::OutlinedShoppingBag)
                ->url(fn (): string => OrderDeskPage::getUrl(['telefone' => $this->getRecord()->phone]))
                ->visible(fn (): bool => $this->getRecord()->is_active && OrderDeskPage::canAccess()),
            EditAction::make()->color('gray'),
        ];
    }
}
