<?php

namespace App\Modules\Agenda\Filament\Appointments\Pages;

use App\Filament\Support\NewRecordTitle;
use App\Modules\Agenda\Filament\Appointments\AppointmentResource;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Services\AgendaException;
use App\Modules\Agenda\Services\AgendaFormat;
use App\Modules\Agenda\Services\AppointmentBook;
use App\Modules\Agenda\Services\SlotFinder;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * New appointment by the desk: everything goes through AppointmentBook. A time outside the free ones ("squeeze in")
 * is booked and the clash, if any, is warned (never blocked: the desk knows its day).
 */
class CreateAppointment extends CreateRecord
{
    use NewRecordTitle;

    protected static string $resource = AppointmentResource::class;

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $time = $data['squeeze'] ?? false ? substr((string) $data['other_time'], 0, 5) : (string) $data['time'];

        try {
            $appointment = app(AppointmentBook::class)->book([
                'customer_id' => $data['customer_id'],
                'item_id' => $data['item_id'] ?? null,
                'size' => $data['size'] ?? null,
                'professional_id' => $data['professional_id'],
                'starts_at' => $data['date'].' '.$time,
                'service_ids' => (array) $data['service_ids'],
                'notes' => $data['notes'] ?? null,
                'pickup' => $data['pickup'] ?? false,
                'dropoff' => $data['dropoff'] ?? false,
                'pickup_site_id' => $data['pickup_site_id'] ?? null,
                'pickup_address' => $data['pickup_address'] ?? null,
                'pickup_from' => $data['pickup_from'] ?? null,
                'pickup_until' => $data['pickup_until'] ?? null,
                'client_package_id' => $data['client_package_id'] ?? null,
            ], auth()->user());
        } catch (AgendaException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            $this->halt();
        }

        $this->warnClash($appointment);

        return $appointment;
    }

    protected function warnClash(Appointment $appointment): void
    {
        $clashes = app(SlotFinder::class)->clashes($appointment->professional, $appointment->starts_at, $appointment->ends_at, $appointment->getKey());

        if ($clashes->isNotEmpty()) {
            Notification::make()->warning()->persistent()
                ->title(__('agenda.appointments.clash', ['name' => $appointment->professional->name()]))
                ->body($clashes->map(fn (Appointment $other): string => AgendaFormat::time($other->starts_at).'–'.AgendaFormat::time($other->ends_at).' · '.$other->title())->implode("\n"))
                ->send();
        }
    }

    protected function getRedirectUrl(): string
    {
        return AppointmentResource::getUrl('view', ['record' => $this->getRecord()]);
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('agenda.appointments.booked', ['when' => AgendaFormat::when($this->getRecord()->starts_at)]);
    }
}
