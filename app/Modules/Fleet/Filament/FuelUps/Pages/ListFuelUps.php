<?php

namespace App\Modules\Fleet\Filament\FuelUps\Pages;

use App\Modules\Fleet\Filament\FuelUps\FuelUpResource;
use App\Modules\Fleet\Services\FuelService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListFuelUps extends ListRecords
{
    protected static string $resource = FuelUpResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('requestCode')
                ->label(__('fleet.fuel.request_code'))
                ->icon(Heroicon::OutlinedQrCode)
                ->visible(fn (): bool => app(FuelService::class)->canFuel(auth()->user()))
                ->url(fn (): string => FuelUpResource::getUrl('request')),
            CreateAction::make()
                ->label(__('fleet.fuel.register'))
                ->color('gray'),
        ];
    }
}
