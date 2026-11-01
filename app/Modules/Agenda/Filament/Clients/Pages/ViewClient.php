<?php

namespace App\Modules\Agenda\Filament\Clients\Pages;

use App\Modules\Agenda\Filament\Appointments\AppointmentResource;
use App\Modules\Agenda\Filament\Clients\ClientResource;
use App\Modules\Agenda\Filament\Packages\ClientPackageResource;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewClient extends ViewRecord
{
    protected static string $resource = ClientResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->name;
    }

    protected function getHeaderActions(): array
    {
        return [
            // Straight to a new appointment with this client chosen.
            Action::make('book')
                ->label(__('agenda.appointments.new'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->url(fn (): string => AppointmentResource::getUrl('create', ['cliente' => $this->getRecord()->getKey()]))
                ->visible(fn (): bool => $this->getRecord()->is_active && AppointmentResource::canCreate()),
            Action::make('sell')
                ->label(__('agenda.client_packages.new'))
                ->icon(Heroicon::OutlinedTicket)
                ->color('gray')
                ->url(fn (): string => ClientPackageResource::getUrl('index', ['cliente' => $this->getRecord()->getKey()]))
                ->visible(fn (): bool => ClientPackageResource::canCreate()),
            EditAction::make()->color('gray'),
        ];
    }
}
