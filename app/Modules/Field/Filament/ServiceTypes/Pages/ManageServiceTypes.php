<?php

namespace App\Modules\Field\Filament\ServiceTypes\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Field\Filament\ServiceTypes\ServiceTypeResource;
use Filament\Actions\CreateAction;

class ManageServiceTypes extends ModuleRecordsPage
{
    protected static string $resource = ServiceTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // The checklist can be long: the form opens on the side.
            CreateAction::make()->slideOver(),
        ];
    }
}
