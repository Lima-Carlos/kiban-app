<?php

namespace App\Modules\Agenda\Services;

use App\Models\User;
use App\Modules\Agenda\Filament\Appointments\AppointmentResource;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Notices\Notice;
use App\Notices\Notifier;

/**
 * Agenda warnings in the bell of the desk (managers and front desk), never by e-mail: a new booking from the
 * public page, a client who canceled or rescheduled by the link. Clicking opens the appointment.
 */
class AgendaNotices
{
    public function __construct(protected AgendaAccess $access) {}

    public function booked(Appointment $appointment): void
    {
        $this->desk($appointment, 'agenda.notices.booked', 'heroicon-o-calendar-days', 'success');
    }

    public function canceledByClient(Appointment $appointment): void
    {
        $this->desk($appointment, 'agenda.notices.canceled', 'heroicon-o-x-circle', 'danger');
    }

    public function movedByClient(Appointment $appointment): void
    {
        $this->desk($appointment, 'agenda.notices.moved', 'heroicon-o-arrow-path', 'warning');
    }

    public function confirmedByClient(Appointment $appointment): void
    {
        $this->desk($appointment, 'agenda.notices.confirmed', 'heroicon-o-check-circle', 'info');
    }

    protected function desk(Appointment $appointment, string $title, string $icon, string $color, ?User $except = null): void
    {
        $appointment->loadMissing(['client', 'item', 'professional.person']);
        $timezone = AgendaSetting::timezone();
        $starts = $appointment->starts_at;

        app(Notifier::class)->send($this->access->deskTeam(), new Notice(
            tenantId: $appointment->tenant_id,
            titleKey: $title,
            titleParams: ['name' => $appointment->title()],
            bodyKey: 'agenda.notices.body',
            bodyParams: [
                'client' => $appointment->client->name,
                'when' => fn (): string => AgendaFormat::when($starts->setTimezone($timezone)),
                'professional' => $appointment->professional->name(),
            ],
            url: Notice::path(AppointmentResource::getUrl('view', ['record' => $appointment])),
            icon: $icon,
            color: $color,
        ), null, $except);
    }
}
