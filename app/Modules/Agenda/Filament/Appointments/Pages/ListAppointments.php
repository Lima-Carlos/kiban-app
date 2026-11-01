<?php

namespace App\Modules\Agenda\Filament\Appointments\Pages;

use App\Filament\Support\EmptyListShowsPendingStep;
use App\Modules\Agenda\Filament\Appointments\AppointmentResource;
use App\Modules\Agenda\Filament\CalendarPage;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ListAppointments extends ListRecords
{
    use EmptyListShowsPendingStep;

    protected static string $resource = AppointmentResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $response = AppointmentResource::getCreateAuthorizationResponse();

        return $response->denied() && $response->message() !== __('agenda.denied.manager') ? $response->message() : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('calendar')
                ->label(__('agenda.calendar.title'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(fn (): string => CalendarPage::getUrl()),
        ];
    }
}
