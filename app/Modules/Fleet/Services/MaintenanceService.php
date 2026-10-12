<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Fleet\Enums\AppointmentStatus;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Enums\MaintenanceItem;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Enums\RequestStatus;
use App\Modules\Fleet\Enums\ServiceKind;
use App\Modules\Fleet\Enums\VehicleStatus;
use App\Modules\Fleet\Models\Appointment;
use App\Modules\Fleet\Models\Expense;
use App\Modules\Fleet\Models\Maintenance;
use App\Modules\Fleet\Models\ServiceRequest;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\Wash;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;

/**
 * Maintenance and washes:
 *
 * - the driver asks ("request maintenance or wash", optional photo);
 * - the manager or the mechanic schedules it (appointment), or rejects it;
 * - finishing records the maintenance (or the wash): odometer, services, costs and, for an oil change or a
 *   revision, the next one is calculated from the vehicle's intervals;
 * - other expenses (tax, insurance, fines...) are typed by the manager.
 */
class MaintenanceService
{
    public function __construct(
        protected FleetAccess $access,
        protected OdometerService $odometer,
    ) {}

    /** Manager or mechanic: schedules, records maintenance and washes. */
    public function canWork(User $user): bool
    {
        return in_array($this->access->fleetRole($user), [FleetRole::Manager, FleetRole::Mechanic], true);
    }

    public function request(Vehicle $vehicle, ServiceKind $kind, string $description, User $user, ?string $photo = null): ServiceRequest
    {
        $visible = $this->access->visibleVehicleIds($user);
        $role = $this->access->fleetRole($user);

        if (! in_array($role, [FleetRole::Manager, FleetRole::Mechanic, FleetRole::Driver], true)
            || ($visible !== null && ! in_array($vehicle->getKey(), $visible, true))) {
            throw new FleetException(__('fleet.denied.not_your_vehicle'));
        }

        if (trim($description) === '') {
            throw new FleetException(__('fleet.errors.description_required'));
        }

        $request = new ServiceRequest(['vehicle_id' => $vehicle->getKey(), 'kind' => $kind, 'description' => $description, 'photo_path' => $photo]);
        $request->forceFill(['requested_by' => $user->getKey()])->save();

        return $request;
    }

    /**
     * @param  array{scheduled_at: CarbonInterface, workshop_id?: ?int, location?: ?string, assigned_to?: ?int, notes?: ?string}  $data
     */
    public function scheduleRequest(ServiceRequest $request, array $data, User $user): Appointment
    {
        if ($request->status !== RequestStatus::Open) {
            throw new FleetException(__('fleet.errors.request_closed'));
        }

        return DB::transaction(function () use ($request, $data, $user): Appointment {
            $appointment = $this->schedule($request->vehicle, $request->kind, $data + ['request_id' => $request->getKey(), 'notes' => $data['notes'] ?? $request->description], $user);
            $request->forceFill(['status' => RequestStatus::Scheduled, 'handled_by' => $user->getKey()])->save();

            return $appointment;
        });
    }

    public function rejectRequest(ServiceRequest $request, string $response, User $user): void
    {
        $this->ensureWorker($user);

        if ($request->status !== RequestStatus::Open) {
            throw new FleetException(__('fleet.errors.request_closed'));
        }

        $request->forceFill(['status' => RequestStatus::Rejected, 'response' => $response, 'handled_by' => $user->getKey()])->save();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function schedule(Vehicle $vehicle, ServiceKind $kind, array $data, User $user): Appointment
    {
        $this->ensureWorker($user);

        if (! $vehicle->isActiveForRecords()) {
            throw new FleetException(__('fleet.errors.vehicle_inactive'));
        }

        $appointment = new Appointment([
            'vehicle_id' => $vehicle->getKey(),
            'kind' => $kind,
            'scheduled_at' => $data['scheduled_at'],
            'workshop_id' => $data['workshop_id'] ?? null,
            'location' => $data['location'] ?? null,
            'assigned_to' => $data['assigned_to'] ?? null,
            'request_id' => $data['request_id'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $appointment->forceFill(['created_by' => $user->getKey()])->save();

        return $appointment;
    }

    public function cancel(Appointment $appointment, string $reason, User $user): void
    {
        $this->ensureWorker($user);

        if (! $appointment->isOpen()) {
            throw new FleetException(__('fleet.errors.appointment_closed'));
        }

        DB::transaction(function () use ($appointment, $reason): void {
            $appointment->forceFill(['status' => AppointmentStatus::Canceled, 'cancel_reason' => $reason])->save();
            // The driver's request goes back to "open" so someone takes care of it.
            $appointment->request?->forceFill(['status' => RequestStatus::Open])->save();
        });
    }

    /**
     * Records a maintenance done (from an appointment or directly).
     *
     * @param  array{type: string, services: array<int, string>, performed_on: CarbonInterface, odometer: int, description?: ?string, workshop_id?: ?int, performed_by?: ?int, parts_cents?: int, labor_cents?: int, invoice_path?: ?string}  $data
     */
    public function recordMaintenance(Vehicle $vehicle, array $data, User $user, ?Appointment $appointment = null): Maintenance
    {
        $this->ensureWorker($user);
        $this->ensureOpen($appointment, $vehicle, ServiceKind::Maintenance);

        return DB::transaction(function () use ($vehicle, $data, $user, $appointment): Maintenance {
            $maintenance = new Maintenance([
                'vehicle_id' => $vehicle->getKey(),
                'appointment_id' => $appointment?->getKey(),
                'type' => $data['type'],
                'services' => array_values((array) $data['services']),
                'description' => $data['description'] ?? null,
                'performed_on' => $data['performed_on'],
                'odometer' => $data['odometer'],
                'workshop_id' => $data['workshop_id'] ?? $appointment?->workshop_id,
                'performed_by' => $data['performed_by'] ?? null,
                'parts_cents' => (int) ($data['parts_cents'] ?? 0),
                'labor_cents' => (int) ($data['labor_cents'] ?? 0),
                'invoice_path' => $data['invoice_path'] ?? null,
            ]);
            $maintenance->forceFill([
                'total_cents' => (int) ($data['parts_cents'] ?? 0) + (int) ($data['labor_cents'] ?? 0),
                'created_by' => $user->getKey(),
            ])->save();

            $this->odometer->record($vehicle, (int) $data['odometer'], OdometerSource::Maintenance, $maintenance, $user, $this->readingTime($data['performed_on']));
            $this->updateNextServices($vehicle->refresh(), $maintenance);
            $this->close($appointment);

            if ($vehicle->status === VehicleStatus::Maintenance) {
                $vehicle->forceFill(['status' => VehicleStatus::Active])->save();
            }

            return $maintenance;
        });
    }

    /**
     * @param  array{kind: string, washed_on: CarbonInterface, odometer?: ?int, cost_cents?: int, place?: ?string, performed_by?: ?int, notes?: ?string}  $data
     */
    public function recordWash(Vehicle $vehicle, array $data, User $user, ?Appointment $appointment = null): Wash
    {
        $this->ensureWorker($user);
        $this->ensureOpen($appointment, $vehicle, ServiceKind::Wash);

        return DB::transaction(function () use ($vehicle, $data, $user, $appointment): Wash {
            $wash = new Wash([
                'vehicle_id' => $vehicle->getKey(),
                'appointment_id' => $appointment?->getKey(),
                'kind' => $data['kind'],
                'washed_on' => $data['washed_on'],
                'odometer' => $data['odometer'] ?? null,
                'cost_cents' => (int) ($data['cost_cents'] ?? 0),
                'place' => $data['place'] ?? $appointment?->place(),
                'performed_by' => $data['performed_by'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $wash->forceFill(['created_by' => $user->getKey()])->save();

            if (filled($data['odometer'] ?? null)) {
                $this->odometer->record($vehicle, (int) $data['odometer'], OdometerSource::Wash, $wash, $user, $this->readingTime($data['washed_on']));
            }

            $this->close($appointment);

            return $wash;
        });
    }

    /**
     * @param  array{vehicle_id?: ?int, category: string, description?: ?string, amount_cents: int, spent_on: CarbonInterface, receipt_path?: ?string}  $data
     */
    public function recordExpense(array $data, User $user): Expense
    {
        if (! $this->access->isManager($user)) {
            throw new FleetException(__('fleet.denied.manager'));
        }

        if ((int) $data['amount_cents'] <= 0) {
            throw new FleetException(__('fleet.errors.amount_invalid'));
        }

        $expense = new Expense($data);
        $expense->forceFill(['created_by' => $user->getKey()])->save();

        return $expense;
    }

    /**
     * After an oil change or a revision: next one = odometer / date of this one + the vehicle's interval.
     * Without an interval, a due date that was already reached is cleared (it was done).
     */
    public function updateNextServices(Vehicle $vehicle, Maintenance $maintenance): void
    {
        $plans = [['item' => MaintenanceItem::OilChange, 'prefix' => 'oil_change'], ['item' => MaintenanceItem::Revision, 'prefix' => 'revision']];

        foreach ($plans as $plan) {
            if (! $maintenance->has($plan['item'])) {
                continue;
            }

            $prefix = $plan['prefix'];
            $distance = $vehicle->{"{$prefix}_interval_distance"};
            $months = $vehicle->{"{$prefix}_interval_months"};

            $vehicle->{"next_{$prefix}_odometer"} = $distance
                ? $maintenance->odometer + (int) $distance
                : (($vehicle->{"next_{$prefix}_odometer"} ?? PHP_INT_MAX) <= $maintenance->odometer ? null : $vehicle->{"next_{$prefix}_odometer"});

            $vehicle->{"next_{$prefix}_on"} = $months
                ? CarbonImmutable::parse($maintenance->performed_on)->addMonths((int) $months)->toDateString()
                : ($vehicle->{"next_{$prefix}_on"}?->lte($maintenance->performed_on) ? null : $vehicle->{"next_{$prefix}_on"});
        }

        $vehicle->save();
    }

    protected function close(?Appointment $appointment): void
    {
        if ($appointment === null) {
            return;
        }

        $appointment->forceFill(['status' => AppointmentStatus::Done, 'completed_at' => now()])->save();
        $appointment->request?->forceFill(['status' => RequestStatus::Done])->save();
    }

    protected function ensureOpen(?Appointment $appointment, Vehicle $vehicle, ServiceKind $kind): void
    {
        if ($appointment !== null && (! $appointment->isOpen() || $appointment->vehicle_id !== $vehicle->getKey() || $appointment->kind !== $kind)) {
            throw new FleetException(__('fleet.errors.appointment_closed'));
        }
    }

    protected function ensureWorker(User $user): void
    {
        if (! $this->canWork($user)) {
            throw new FleetException(__('fleet.denied.worker'));
        }
    }

    /** Today: now. Another day: midday of that day (company time zone), so it fits between the readings. */
    protected function readingTime(CarbonInterface|string $date): ?CarbonInterface
    {
        $date = CarbonImmutable::parse($date, FilamentTimezone::get());

        return $date->isToday() ? null : $date->setTime(12, 0)->utc();
    }
}
