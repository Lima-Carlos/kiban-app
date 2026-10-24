<?php

namespace App\Modules\TimeClock\Filament\Schedules\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\TimeClock\Filament\Schedules\ScheduleResource;
use App\Modules\TimeClock\Models\Schedule;
use Filament\Actions\CreateAction;

class ManageSchedules extends ModuleRecordsPage
{
    protected static string $resource = ScheduleResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->modalWidth('5xl')
                ->using(function (array $data, CreateAction $action): Schedule {
                    ScheduleResource::checkOrHalt($data, $action);

                    return Schedule::create($data);
                }),
        ];
    }
}
