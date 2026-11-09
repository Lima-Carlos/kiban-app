<?php

namespace App\Modules\Fleet\Filament\Vehicles\Pages;

use App\Filament\Support\ImportActions;
use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
use App\Modules\Fleet\Services\VehicleImporter;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListVehicles extends ListRecords
{
    protected static string $resource = VehicleResource::class;

    /** Says why a new vehicle cannot be added (plan limit, read-only...). Drivers see nothing here. */
    public function getSubheading(): string|Htmlable|null
    {
        $response = VehicleResource::getCreateAuthorizationResponse();

        return $response->denied() && $response->message() !== __('fleet.denied.manager') ? $response->message() : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            ImportActions::make(VehicleImporter::class, 'fleet.import', fn (): bool => VehicleResource::canCreate()),
        ];
    }
}
