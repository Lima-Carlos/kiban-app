<?php

namespace App\Modules\TimeClock\Filament\Holidays\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\TimeClock\Filament\Holidays\HolidayResource;
use App\Modules\TimeClock\Filament\Holidays\NationalHolidaysWidget;

class ManageHolidays extends ModuleRecordsPage
{
    protected static string $resource = HolidayResource::class;

    protected function getHeaderWidgets(): array
    {
        return [NationalHolidaysWidget::class];
    }
}
