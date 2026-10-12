<?php

namespace App\Modules\Fleet\Filament\Maintenances\Pages;

use App\Modules\Fleet\Filament\Appointments\AppointmentResource;
use App\Modules\Fleet\Filament\Maintenances\MaintenanceResource;
use App\Modules\Fleet\Filament\RunsFleetRules;
use App\Modules\Fleet\Filament\WorkForms;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\MaintenanceService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageMaintenances extends ManageRecords
{
    protected static string $resource = MaintenanceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('record')
                ->label(__('fleet.maintenances.record'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create')
                ->modalWidth('3xl')
                ->fillForm(['performed_on' => now()->toDateString()])
                ->schema(WorkForms::maintenance())
                ->action(fn (array $data) => RunsFleetRules::attempt(
                    fn () => app(MaintenanceService::class)->recordMaintenance(
                        Vehicle::query()->whereKey($data['vehicle_id'])->firstOrFail(),
                        AppointmentResource::maintenanceData($data),
                        auth()->user(),
                    ),
                    __('fleet.maintenances.recorded'),
                )),
        ];
    }
}
