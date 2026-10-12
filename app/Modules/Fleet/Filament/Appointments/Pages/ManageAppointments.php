<?php

namespace App\Modules\Fleet\Filament\Appointments\Pages;

use App\Modules\Fleet\Enums\ServiceKind;
use App\Modules\Fleet\Filament\Appointments\AppointmentResource;
use App\Modules\Fleet\Filament\RunsFleetRules;
use App\Modules\Fleet\Filament\WorkForms;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\MaintenanceService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ManageRecords;
use Filament\Support\Icons\Heroicon;

class ManageAppointments extends ManageRecords
{
    protected static string $resource = AppointmentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('calendar')
                ->label(__('fleet.appointments.calendar'))
                ->icon(Heroicon::OutlinedCalendar)
                ->color('gray')
                ->url(AppointmentResource::getUrl('calendar')),
            Action::make('schedule')
                ->label(__('fleet.actions.schedule'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize('create')
                ->schema(WorkForms::schedule())
                ->action(fn (array $data) => RunsFleetRules::attempt(fn () => app(MaintenanceService::class)->schedule(
                    Vehicle::query()->whereKey($data['vehicle_id'])->firstOrFail(),
                    ServiceKind::from($data['kind'] instanceof ServiceKind ? $data['kind']->value : (string) $data['kind']),
                    [
                        'scheduled_at' => WorkForms::date($data['scheduled_at']),
                        'workshop_id' => $data['workshop_id'] ?? null,
                        'location' => $data['location'] ?? null,
                        'assigned_to' => $data['assigned_to'] ?? null,
                        'notes' => $data['notes'] ?? null,
                    ],
                    auth()->user(),
                ))),
        ];
    }
}
