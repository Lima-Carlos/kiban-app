<?php

namespace App\Modules\Field\Filament\Orders\Pages;

use App\Modules\Field\Filament\Orders\OrderResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ListOrders extends ListRecords
{
    protected static string $resource = OrderResource::class;

    /** Says why an order cannot be opened (read-only, a first step missing). */
    public function getSubheading(): string|Htmlable|null
    {
        $response = OrderResource::getCreateAuthorizationResponse();

        return $response->denied() && $response->message() !== __('field.denied.manager') ? $response->message() : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('agenda')
                ->label(__('field.agenda.title'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(OrderResource::getUrl('agenda')),
        ];
    }
}
