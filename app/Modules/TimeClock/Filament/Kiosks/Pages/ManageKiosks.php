<?php

namespace App\Modules\TimeClock\Filament\Kiosks\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\TimeClock\Filament\Kiosks\KioskResource;

class ManageKiosks extends ModuleRecordsPage
{
    protected static string $resource = KioskResource::class;
}
