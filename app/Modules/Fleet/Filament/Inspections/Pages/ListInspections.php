<?php

namespace App\Modules\Fleet\Filament\Inspections\Pages;

use App\Modules\Fleet\Filament\Inspections\InspectionResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListInspections extends ListRecords
{
    protected static string $resource = InspectionResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('do')
                ->label(__('fleet.inspections.do'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->visible(fn (): bool => DoInspection::canAccess())
                ->url(fn (): string => InspectionResource::getUrl('do')),
        ];
    }
}
