<?php

namespace App\Modules\Agenda\Filament\Appointments\Pages;

use App\Filament\Support\NewRecordTitle;
use App\Modules\Agenda\Filament\Appointments\AppointmentResource;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Services\AgendaException;
use App\Modules\Agenda\Services\AgendaFormat;
use App\Modules\Agenda\Services\AppointmentBook;
use App\Modules\Agenda\Services\SlotFinder;
use App\Modules\Agenda\Services\WorkingHours;
use Carbon\CarbonImmutable;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * New appointment by the desk: everything goes through AppointmentBook. A time outside the free ones ("squeeze in")
 * is booked; a clash or a time outside the professional's hours is warned (never blocked: the desk knows its day).
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

        static::warn($appointment);

        return $appointment;
    }

    /**
     * The desk's warnings after booking or moving (never a block): another appointment at the same time, and a time
     * outside the professional's hours (day off, before/after the hours, break or time off).
     */
    public static function warn(Appointment $appointment): void
    {
        $slots = app(SlotFinder::class);
        $outside = $slots->outsideHours($appointment);

        if ($outside !== null) {
            $hours = collect(app(WorkingHours::class)->intervals($appointment->professional, CarbonImmutable::instance($appointment->starts_at)->setTimezone(AgendaSetting::timezone())->startOfDay()))
                ->map(fn (array $piece): string => $piece[0]->format('H:i').'–'.$piece[1]->format('H:i'))->implode(' · ');

            Notification::make()->warning()->persistent()
                ->title(__('agenda.appointments.'.$outside, ['name' => $appointment->professional->name()]))
                ->body($outside === 'outside' ? __('agenda.appointments.outside_body', ['hours' => $hours]) : __('agenda.appointments.day_off_body'))
                ->send();
        }

        $clashes = $slots->clashes($appointment->professional, $appointment->starts_at, $appointment->ends_at, $appointment->getKey());

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
