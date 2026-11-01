<?php

namespace App\Connections\Bridges;

use App\Models\User;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Services\AgendaException;
use App\Modules\Agenda\Services\AgendaFormat;
use App\Modules\Agenda\Services\AppointmentBook;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Services\FieldException;
use App\Modules\Field\Services\WorkOrderService;
use App\Tenancy\TenantContext;

/**
 * Kiban Core Agenda + Kiban Core Campo: the pick-up and drop-off ("leva e traz") of an appointment becomes a Campo
 * work order with one click, for the driver/technician who goes to the client. The customer is the same record in
 * both systems (fd_customers) and the address is the client's address, so nothing is copied. The order waits on
 * the Campo agenda ("to schedule") with the pick-up window in the description; the type of service is chosen once
 * in the Agenda settings. Only when the company has both systems.
 */
class AgendaField
{
    public function __construct(protected TenantContext $context) {}

    public function enabled(): bool
    {
        $license = $this->context->get()->license();

        return $license->canWrite(AgendaModule::KEY) && $license->canWrite(FieldModule::KEY);
    }

    /** The appointment has a pick-up or drop-off, no order yet, and is still to happen. */
    public function canOpenFor(Appointment $appointment): bool
    {
        return $this->enabled()
            && ($appointment->pickup || $appointment->dropoff)
            && $appointment->field_order_id === null
            && ! $appointment->status->isFinal();
    }

    /**
     * Types of service of the Campo to choose in the Agenda settings.
     *
     * @return array<int, string>
     */
    public function serviceTypeOptions(): array
    {
        return $this->enabled() ? ServiceType::query()->active()->orderBy('name')->pluck('name', 'id')->all() : [];
    }

    public function openOrder(Appointment $appointment, ?User $by): Appointment
    {
        if (! $this->canOpenFor($appointment)) {
            throw new AgendaException(__('agenda.errors.field_order_not_possible'));
        }

        $type = ServiceType::query()->active()->find(AgendaSetting::current()->field_service_type_id);

        if ($type === null) {
            throw new AgendaException(__('agenda.errors.field_type_missing'));
        }

        $appointment->loadMissing(['item', 'client']);

        try {
            $order = app(WorkOrderService::class)->open([
                'customer_id' => $appointment->customer_id,
                'site_id' => $appointment->pickup_site_id,
                'service_type_id' => $type->getKey(),
                'reported' => __('agenda.bridge.field_order_text', [
                    'what' => collect([$appointment->pickup ? __('agenda.fields.pickup') : null, $appointment->dropoff ? __('agenda.fields.dropoff') : null])->filter()->implode(' + '),
                    'pet' => $appointment->item?->label() ?? '—',
                    'when' => AgendaFormat::when($appointment->starts_at),
                    'window' => $appointment->pickup_from ? __('agenda.appointments.window', ['from' => $appointment->pickup_from, 'until' => $appointment->pickup_until ?: '—']) : '—',
                ]),
                'internal_notes' => $appointment->item?->notes,
            ], $by);
        } catch (FieldException $exception) {
            throw new AgendaException($exception->getMessage());
        }

        $appointment->forceFill(['field_order_id' => $order->getKey()])->save();
        app(AppointmentBook::class)->log($appointment, 'field_order', ['order' => $order->getKey()], $by);

        return $appointment;
    }
}
