<?php

namespace App\Modules\Fleet\Filament\Washes\Pages;

use App\Modules\Fleet\Filament\Appointments\AppointmentResource;
use App\Modules\Fleet\Filament\RunsFleetRules;
use App\Modules\Fleet\Filament\Washes\WashResource;
use App\Modules\Fleet\Filament\WorkForms;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\MaintenanceService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageWashes extends ManageRecords
{
    protected static string $resource = WashResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record')
                ->label(__('fleet.washes.record'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create')
                ->fillForm(['washed_on' => now()->toDateString()])
                ->schema(WorkForms::wash())
                ->action(fn (array $data) => RunsFleetRules::attempt(fn () => app(MaintenanceService::class)->recordWash(
                    Vehicle::query()->whereKey($data['vehicle_id'])->firstOrFail(),
                    AppointmentResource::washData($data),
                    auth()->user(),
                ))),
        ];
    }
}
