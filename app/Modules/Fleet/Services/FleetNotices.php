<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Fleet\Enums\DueStatus;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Enums\FuelUpFlag;
use App\Modules\Fleet\Enums\InspectionFrequency;
use App\Modules\Fleet\Enums\RequestStatus;
use App\Modules\Fleet\Enums\VehicleStatus;
use App\Modules\Fleet\Filament\Appointments\AppointmentResource;
use App\Modules\Fleet\Filament\Dashboard\FleetDashboard;
use App\Modules\Fleet\Filament\FuelUps\FuelUpResource;
use App\Modules\Fleet\Filament\Inspections\InspectionResource;
use App\Modules\Fleet\Filament\Requests\ServiceRequestResource;
use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
use App\Modules\Fleet\Models\Appointment;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\Fleet\Models\Inspection;
use App\Modules\Fleet\Models\ServiceRequest;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\VehicleDocument;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Collection;

/**
 * Frota warnings in the bell (never by e-mail) and the "Attention" box of the start page.
 *
 * - right away: inspection with problems (critical = vehicle stopped), new request, request answered,
 *   fuel-up finished with warnings;
 * - by the hourly routine (each one only once): appointment tomorrow, oil change / revision soon or overdue,
 *   document expiring or expired, vehicles without today's inspection (after 11:00).
 */
class FleetNotices
{
    /** Hour (company time) after which the manager hears about vehicles without today's inspection. */
    public const INSPECTION_REMINDER_HOUR = 11;

    public function __construct(
        protected TenantContext $context,
        protected FleetAccess $access,
        protected Notifier $notifier,
    ) {}

    public function inspectionDone(Inspection $inspection, User $by): void
    {
        if ($inspection->problems === 0) {
            return;
        }

        $problems = collect($inspection->problemAnswers());
        $critical = $inspection->has_critical;

        $this->notifier->send($this->people([FleetRole::Manager, FleetRole::Mechanic]), $this->notice(
            $critical ? 'fleet.notices.inspection_critical' : 'fleet.notices.inspection_problems',
            ['plate' => $inspection->vehicle->plate],
            'fleet.notices.inspection_body',
            ['items' => $problems->pluck('label')->implode(', '), 'name' => $by->name],
            InspectionResource::getUrl('view', ['record' => $inspection]),
            $critical ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-clipboard-document-check',
            $critical ? 'danger' : 'warning',
        ), except: $by);
    }

    /** A driver asked (problems of inspections come together in inspectionDone). */
    public function requestCreated(ServiceRequest $request, User $by): void
    {
        $this->notifier->send($this->people([FleetRole::Manager, FleetRole::Mechanic]), $this->notice(
            'fleet.notices.request_new',
            ['plate' => $request->vehicle->plate, 'kind' => $request->kind],
            'fleet.notices.request_body',
            ['name' => $by->name, 'text' => str($request->description)->limit(120)->toString()],
            ServiceRequestResource::getUrl('index'),
            'heroicon-o-chat-bubble-left-ellipsis',
        ), except: $by);
    }

    /** Scheduled, rejected or done: the person who asked hears about it. */
    public function requestAnswered(ServiceRequest $request, ?User $by = null): void
    {
        $requester = $request->requester;

        if ($requester === null) {
            return;
        }

        $scheduledAt = $request->appointment?->scheduled_at?->timezone(FilamentTimezone::get());
        $date = fn (): ?string => $scheduledAt?->translatedFormat(__('app.datetime_format'));

        $this->notifier->send([$requester], $this->notice(
            'fleet.notices.request_'.$request->status->value,
            ['plate' => $request->vehicle->plate, 'kind' => $request->kind],
            match ($request->status) {
                RequestStatus::Scheduled => 'fleet.notices.request_scheduled_body',
                RequestStatus::Rejected => 'fleet.notices.request_rejected_body',
                default => null,
            },
            ['date' => $date, 'text' => (string) $request->response],
            ServiceRequestResource::getUrl('index'),
            'heroicon-o-chat-bubble-left-ellipsis',
            $request->status === RequestStatus::Rejected ? 'gray' : 'success',
        ), except: $by);
    }

    public function fuelUpFlagged(FuelUp $fuelUp, ?User $by = null): void
    {
        $flags = $fuelUp->flagList();

        // "Without inspection" alone does not ring: the daily warning of missing inspections covers it.
        if (collect($flags)->reject(fn (FuelUpFlag $flag): bool => $flag === FuelUpFlag::NoInspection)->isEmpty()) {
            return;
        }

        $this->notifier->send($this->people([FleetRole::Manager]), $this->notice(
            'fleet.notices.fuel_flagged',
            ['plate' => $fuelUp->vehicle->plate],
            'fleet.notices.fuel_flagged_body',
            ['flags' => fn (): string => collect($flags)->map(fn (FuelUpFlag $flag): string => $flag->getLabel())->implode(', ')],
            FuelUpResource::getUrl('view', ['record' => $fuelUp]),
            'heroicon-o-beaker',
            'warning',
        ), except: $by);
    }

    /**
     * The hourly routine (inside the company). Returns how many warnings were sent.
     */
    public function scan(): int
    {
        return $this->appointmentsTomorrow() + $this->dueServices() + $this->documents() + $this->missingInspections() + $this->cleanupWarning();
    }

    protected function appointmentsTomorrow(): int
    {
        $tomorrow = CarbonImmutable::now(FilamentTimezone::get())->addDay();
        $sent = 0;

        $appointments = Appointment::query()->with(['vehicle', 'assignee'])->open()
            ->whereBetween('scheduled_at', [$tomorrow->startOfDay()->utc(), $tomorrow->endOfDay()->utc()])
            ->get();

        foreach ($appointments as $appointment) {
            $to = $appointment->assignee !== null ? [$appointment->assignee] : $this->people([FleetRole::Mechanic]);

            $this->notifier->send($to, $this->notice(
                'fleet.notices.appointment_tomorrow',
                ['plate' => $appointment->vehicle->plate, 'kind' => $appointment->kind],
                'fleet.notices.appointment_body',
                ['time' => $appointment->scheduled_at->timezone(FilamentTimezone::get())->format('H:i'), 'place' => $appointment->place() ?? '—'],
                AppointmentResource::getUrl('calendar'),
                'heroicon-o-calendar-days',
            ), once: "appointment:{$appointment->getKey()}:tomorrow");
            $sent++;
        }

        return $sent;
    }

    /** Oil change and revision soon or overdue: manager and the vehicle's drivers. */
    protected function dueServices(): int
    {
        $settings = FleetSetting::current();
        $sent = 0;

        foreach (Vehicle::query()->countingInLimit()->get() as $vehicle) {
            foreach (['oil_change' => $vehicle->oilChangeStatus($settings), 'revision' => $vehicle->revisionStatus($settings)] as $service => $status) {
                if (! in_array($status, [DueStatus::Soon, DueStatus::Overdue], true)) {
                    continue;
                }

                $due = $vehicle->{"next_{$service}_odometer"}.'|'.$vehicle->{"next_{$service}_on"}?->toDateString();

                $this->notifier->send($this->people([FleetRole::Manager])->merge($this->driversOf($vehicle)), $this->notice(
                    "fleet.notices.{$service}_{$status->value}",
                    ['plate' => $vehicle->plate],
                    null,
                    [],
                    VehicleResource::getUrl('view', ['record' => $vehicle]),
                    'heroicon-o-wrench',
                    $status === DueStatus::Overdue ? 'danger' : 'warning',
                ), once: "{$service}:{$vehicle->getKey()}:{$status->value}:{$due}");
                $sent++;
            }
        }

        return $sent;
    }

    protected function documents(): int
    {
        $settings = FleetSetting::current();
        $sent = 0;

        $documents = VehicleDocument::query()->with('vehicle')->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', now()->addDays($settings->alert_days))
            ->whereHas('vehicle', fn ($query) => $query->where('status', '!=', VehicleStatus::Inactive->value))
            ->get();

        foreach ($documents as $document) {
            $status = $document->dueStatus($settings);

            $this->notifier->send($this->people([FleetRole::Manager]), $this->notice(
                "fleet.notices.document_{$status->value}",
                ['plate' => $document->vehicle->plate, 'document' => $document->type],
                'fleet.notices.document_body',
                ['date' => fn (): string => $document->expires_on->translatedFormat(__('app.date_format'))],
                VehicleResource::getUrl('view', ['record' => $document->vehicle]),
                'heroicon-o-document-text',
                $status === DueStatus::Overdue ? 'danger' : 'warning',
            ), once: "document:{$document->getKey()}:{$document->expires_on->toDateString()}:{$status->value}");
            $sent++;
        }

        return $sent;
    }

    /** Daily inspections: after 11:00, one warning to the manager with the vehicles still missing it. */
    protected function missingInspections(): int
    {
        $inspections = app(InspectionService::class);
        $now = CarbonImmutable::now(FilamentTimezone::get());

        if ($inspections->frequency() !== InspectionFrequency::Daily || $now->hour < self::INSPECTION_REMINDER_HOUR) {
            return 0;
        }

        $due = $inspections->dueVehicles();

        if ($due->isEmpty()) {
            return 0;
        }

        $this->notifier->send($this->people([FleetRole::Manager]), $this->notice(
            'fleet.notices.inspections_missing',
            ['count' => $due->count()],
            'fleet.notices.inspections_missing_body',
            ['plates' => $due->pluck('plate')->implode(', ')],
            FleetDashboard::getUrl(),
            'heroicon-o-clipboard-document-check',
            'warning',
        ), once: 'inspections:'.$now->toDateString());

        return 1;
    }

    /** Once a month: how many old photos and receipts will be removed in the next 30 days. */
    protected function cleanupWarning(): int
    {
        $count = app(FleetCleanup::class)->upcoming();

        if ($count === 0 || ! $this->notifier->firstTime($this->context->get()->getKey(), 'cleanup:'.now()->format('Y-m'))) {
            return 0;
        }

        $this->notifier->send($this->people([FleetRole::Manager]), $this->notice(
            'fleet.notices.cleanup',
            ['count' => $count],
            'fleet.notices.cleanup_body',
            ['days' => FleetCleanup::WARN_DAYS],
            InspectionResource::getUrl('index'),
            'heroicon-o-photo',
        ));

        return 1;
    }

    /**
     * Urgent and still open for this person (the "Attention" box). It disappears when solved.
     *
     * @return array<int, array{text: string, url: ?string, color: string}>
     */
    public function attention(User $user): array
    {
        $role = $this->access->fleetRole($user);
        $items = [];

        if ($role === FleetRole::Driver) {
            $visible = $this->access->visibleVehicleIds($user) ?? [];

            foreach (Vehicle::query()->whereKey($visible)->where('status', VehicleStatus::Maintenance->value)->orderBy('plate')->get() as $vehicle) {
                $items[] = ['text' => __('fleet.attention.do_not_use', ['plate' => $vehicle->plate]), 'url' => VehicleResource::getUrl('view', ['record' => $vehicle]), 'color' => 'danger'];
            }

            return $items;
        }

        if (! in_array($role, [FleetRole::Manager, FleetRole::Mechanic, FleetRole::Viewer], true)) {
            return [];
        }

        // Vehicles stopped by a critical item of an inspection, while the problem is open.
        $stopped = ServiceRequest::query()
            ->with(['vehicle', 'inspection'])
            ->whereIn('status', [RequestStatus::Open->value, RequestStatus::Scheduled->value])
            ->whereHas('inspection', fn ($query) => $query->where('has_critical', true))
            ->whereHas('vehicle', fn ($query) => $query->where('status', VehicleStatus::Maintenance->value))
            ->get()
            ->groupBy('vehicle_id');

        foreach ($stopped as $requests) {
            $inspection = $requests->first()->inspection;
            $items[] = [
                'text' => __('fleet.attention.stopped', [
                    'plate' => $requests->first()->vehicle->plate,
                    'items' => collect($inspection->problemAnswers())->where('critical', true)->pluck('label')->implode(', '),
                ]),
                'url' => InspectionResource::getUrl('view', ['record' => $inspection]),
                'color' => 'danger',
            ];
        }

        $settings = FleetSetting::current();

        foreach (Vehicle::query()->countingInLimit()->orderBy('plate')->get() as $vehicle) {
            foreach (['oil_change' => $vehicle->oilChangeStatus($settings), 'revision' => $vehicle->revisionStatus($settings)] as $service => $status) {
                if ($status === DueStatus::Overdue) {
                    $items[] = ['text' => __("fleet.notices.{$service}_overdue", ['plate' => $vehicle->plate]), 'url' => VehicleResource::getUrl('view', ['record' => $vehicle]), 'color' => 'warning'];
                }
            }
        }

        if ($role !== FleetRole::Mechanic) {
            $expired = VehicleDocument::query()->with('vehicle')->whereDate('expires_on', '<', now()->startOfDay())
                ->whereHas('vehicle', fn ($query) => $query->where('status', '!=', VehicleStatus::Inactive->value))
                ->get();

            foreach ($expired as $document) {
                $items[] = [
                    'text' => __('fleet.notices.document_overdue', ['plate' => $document->vehicle->plate, 'document' => $document->type->getLabel()]),
                    'url' => VehicleResource::getUrl('view', ['record' => $document->vehicle]),
                    'color' => 'warning',
                ];
            }
        }

        return $items;
    }

    /**
     * People of the company with one of these Frota roles.
     *
     * @param  array<int, FleetRole>  $roles
     * @return Collection<int, User>
     */
    public function people(array $roles): Collection
    {
        return $this->context->get()->users()->get()
            ->filter(fn (User $user): bool => in_array($this->access->fleetRole($user), $roles, true))
            ->values()
            ->toBase();
    }

    /**
     * @return Collection<int, User>
     */
    protected function driversOf(Vehicle $vehicle): Collection
    {
        return $vehicle->drivers()->where('is_active', true)->whereNotNull('user_id')->with('user')->get()->pluck('user')->filter()->toBase();
    }

    /**
     * @param  array<string, mixed>  $titleParams
     * @param  array<string, mixed>  $bodyParams
     */
    protected function notice(string $titleKey, array $titleParams, ?string $bodyKey, array $bodyParams, ?string $url, string $icon, string $color = 'info'): Notice
    {
        return new Notice($this->context->get()->getKey(), $titleKey, $titleParams, $bodyKey, $bodyParams, $url, $icon, $color);
    }
}
