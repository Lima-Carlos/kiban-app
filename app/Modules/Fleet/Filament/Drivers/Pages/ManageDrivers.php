<?php

namespace App\Modules\Fleet\Filament\Drivers\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Fleet\Filament\Drivers\DriverResource;

class ManageDrivers extends ModuleRecordsPage
{
    protected static string $resource = DriverResource::class;
}
