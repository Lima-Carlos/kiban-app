<?php

namespace App\Modules\Field\Filament\Customers\Pages;

use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Filament\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [
            // Straight to a new work order with this customer chosen.
            Action::make('newOrder')
                ->label(__('field.orders.new'))
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->url(fn (): string => OrderResource::getUrl('create', ['cliente' => $this->getRecord()->getKey()]))
                ->visible(fn (): bool => $this->getRecord()->is_active && OrderResource::canCreate()),
            EditAction::make()->color('gray'),
        ];
    }
}
