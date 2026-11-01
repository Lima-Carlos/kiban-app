<?php

namespace App\Modules\Agenda\Services;

use App\Models\CompanyProfile;
use App\Modules\Agenda\Models\Appointment;
use App\People\PeopleService;
use Illuminate\Support\Str;

/**
 * The ready WhatsApp messages of the desk (free: a wa.me link the person taps, no paid service): the booking, the
 * reminder for tomorrow and "your pet is ready". The texts are in the language of who sends.
 */
class AgendaMessages
{
    public function link(Appointment $appointment): string
    {
        return route('agenda.manage', ['token' => $appointment->token]);
    }

    public function booked(Appointment $appointment): string
    {
        return $this->url($appointment, 'agenda.messages.booked');
    }

    public function reminder(Appointment $appointment): string
    {
        return $this->url($appointment, 'agenda.messages.reminder');
    }

    public function ready(Appointment $appointment): string
    {
        return $this->url($appointment, $appointment->dropoff ? 'agenda.messages.ready_dropoff' : 'agenda.messages.ready');
    }

    protected function url(Appointment $appointment, string $key): string
    {
        $appointment->loadMissing(['client', 'item', 'lines']);

        return PeopleService::whatsappUrl($appointment->client->phone, __($key, [
            'name' => Str::before(trim((string) $appointment->client->name), ' '),
            'pet' => $appointment->item?->name ?? '',
            'company' => CompanyProfile::current()->name(),
            'when' => AgendaFormat::when($appointment->starts_at),
            'time' => AgendaFormat::time($appointment->starts_at),
            'services' => $appointment->servicesLabel(),
            'link' => $this->link($appointment),
        ]));
    }
}
