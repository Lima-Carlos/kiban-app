<?php

namespace App\Modules\Fleet;

use App\Filament\Pages\PeoplePage;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Enums\FuelUpStatus;
use App\Modules\Fleet\Enums\RequestStatus;
use App\Modules\Fleet\Filament\Appointments\AppointmentResource;
use App\Modules\Fleet\Filament\Checklist\ChecklistItemResource;
use App\Modules\Fleet\Filament\Dashboard\ConsumptionAlertsWidget;
use App\Modules\Fleet\Filament\Dashboard\ConsumptionChartWidget;
use App\Modules\Fleet\Filament\Dashboard\FleetDashboard;
use App\Modules\Fleet\Filament\Dashboard\FleetStatsWidget;
use App\Modules\Fleet\Filament\Dashboard\FuelSpendChartWidget;
use App\Modules\Fleet\Filament\Dashboard\InspectionStatusWidget;
use App\Modules\Fleet\Filament\Drivers\DriverResource;
use App\Modules\Fleet\Filament\Expenses\ExpenseResource;
use App\Modules\Fleet\Filament\FleetSettingsPage;
use App\Modules\Fleet\Filament\FuelStations\FuelStationResource;
use App\Modules\Fleet\Filament\FuelUps\FuelUpResource;
use App\Modules\Fleet\Filament\Inspections\InspectionResource;
use App\Modules\Fleet\Filament\Maintenances\MaintenanceResource;
use App\Modules\Fleet\Filament\Requests\ServiceRequestResource;
use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
use App\Modules\Fleet\Filament\Washes\WashResource;
use App\Modules\Fleet\Filament\Workshops\WorkshopResource;
use App\Modules\Fleet\Models\Appointment;
use App\Modules\Fleet\Models\ChecklistItem;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\Expense;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\Fleet\Models\Inspection;
use App\Modules\Fleet\Models\Maintenance;
use App\Modules\Fleet\Models\OdometerReading;
use App\Modules\Fleet\Models\ServiceRequest;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\VehicleDocument;
use App\Modules\Fleet\Models\VehicleEvent;
use App\Modules\Fleet\Models\Wash;
use App\Modules\Fleet\Models\Workshop;
use App\Modules\Fleet\Policies\AppointmentPolicy;
use App\Modules\Fleet\Policies\ChecklistItemPolicy;
use App\Modules\Fleet\Policies\DriverPolicy;
use App\Modules\Fleet\Policies\ExpensePolicy;
use App\Modules\Fleet\Policies\FuelStationPolicy;
use App\Modules\Fleet\Policies\FuelUpPolicy;
use App\Modules\Fleet\Policies\InspectionPolicy;
use App\Modules\Fleet\Policies\MaintenancePolicy;
use App\Modules\Fleet\Policies\OdometerReadingPolicy;
use App\Modules\Fleet\Policies\ServiceRequestPolicy;
use App\Modules\Fleet\Policies\VehicleDocumentPolicy;
use App\Modules\Fleet\Policies\VehicleEventPolicy;
use App\Modules\Fleet\Policies\VehiclePolicy;
use App\Modules\Fleet\Policies\WashPolicy;
use App\Modules\Fleet\Policies\WorkshopPolicy;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetPeople;
use App\Modules\Fleet\Services\FuelService;
use App\Modules\Fleet\Services\InspectionService;
use App\Modules\Module;
use App\Modules\Step;
use App\Tenancy\TenantContext;

/**
 * Kiban Frota: vehicles with the odometer always up to date, oil changes and revisions, documents,
 * drivers (with their own login on the phone) and fuel stations. Product code in the Core: "frota".
 */
class FleetModule extends Module
{
    public const KEY = 'frota';

    /** Plan limit in the Core: number of vehicles (inactive ones do not count). */
    public const LIMIT_VEHICLES = 'vehicles';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('fleet.module');
    }

    public function icon(): string
    {
        return 'heroicon-o-truck';
    }

    public function steps(): array
    {
        return [
            new Step(
                key: 'vehicle',
                labelKey: 'fleet.steps.vehicle',
                descriptionKey: 'fleet.steps.vehicle_help',
                done: fn (Tenant $tenant): bool => Vehicle::query()->exists(),
                url: fn (): string => VehicleResource::getUrl('create'),
            ),
            new Step(
                key: 'driver',
                labelKey: 'fleet.steps.driver',
                descriptionKey: 'fleet.steps.driver_help',
                requires: ['vehicle'],
                done: fn (Tenant $tenant): bool => Driver::query()->whereHas('vehicles')->exists(),
                url: fn (): string => PeoplePage::getUrl(),
            ),
            new Step(
                key: 'station',
                labelKey: 'fleet.steps.station',
                descriptionKey: 'fleet.steps.station_help',
                done: fn (Tenant $tenant): bool => FuelStation::query()->exists(),
                url: fn (): string => FuelStationResource::getUrl('index'),
            ),
        ];
    }

    public function resources(): array
    {
        return [
            VehicleResource::class, FuelUpResource::class, InspectionResource::class, ServiceRequestResource::class, AppointmentResource::class,
            MaintenanceResource::class, WashResource::class, ExpenseResource::class,
            DriverResource::class, FuelStationResource::class, WorkshopResource::class, ChecklistItemResource::class,
        ];
    }

    public function pages(): array
    {
        return [FleetDashboard::class, FleetSettingsPage::class];
    }

    public function widgets(): array
    {
        return [FleetStatsWidget::class, InspectionStatusWidget::class, ConsumptionChartWidget::class, FuelSpendChartWidget::class, ConsumptionAlertsWidget::class];
    }

    public function showsSetupTo(User $user): bool
    {
        return app(FleetAccess::class)->isManager($user);
    }

    /**
     * Drivers and mechanics: their vehicles with big buttons (phone-first) and any open fuel code.
     * Managers: only their open fuel codes, if any.
     */
    public function personalHome(User $user): ?array
    {
        $access = app(FleetAccess::class);
        $role = $access->fleetRole($user);
        $open = FuelUp::query()->with('vehicle')->where('created_by', $user->getKey())->where('status', FuelUpStatus::Authorized->value)->latest()->get();

        if (in_array($role, [FleetRole::Viewer, FleetRole::None], true) || ($role === FleetRole::Manager && $open->isEmpty())) {
            return null;
        }

        // Mechanic: the work queue (requests waiting and the next appointments).
        if ($role === FleetRole::Mechanic) {
            return [
                'view' => 'fleet.home.mechanic',
                'data' => [
                    'requests' => ServiceRequest::query()->with(['vehicle', 'requester'])->where('status', RequestStatus::Open->value)->oldest()->limit(10)->get(),
                    'appointments' => Appointment::query()->with(['vehicle', 'workshop'])->open()->where('scheduled_at', '<=', now()->addDays(7))->orderBy('scheduled_at')->limit(15)->get(),
                ],
            ];
        }

        $visible = $access->visibleVehicleIds($user);
        $vehicles = $role === FleetRole::Manager ? collect() : Vehicle::query()->countingInLimit()->when($visible !== null, fn ($query) => $query->whereKey($visible))->orderBy('plate')->get();
        $inspections = app(InspectionService::class);

        return [
            'view' => 'fleet.home.driver',
            'data' => [
                'openFuelUps' => $open,
                'vehicles' => $vehicles,
                // Vehicles still missing the inspection of today / this week / this month.
                'dueInspection' => $vehicles->filter(fn (Vehicle $vehicle): bool => $inspections->isDue($vehicle))->modelKeys(),
                'canInspect' => $inspections->canInspect($user) && $this->tenantCanWrite(),
                'canFuel' => app(FuelService::class)->canFuel($user) && $this->tenantCanWrite(),
                'showVehicles' => $role !== FleetRole::Manager,
                'openRequests' => $role === FleetRole::Manager ? collect() : ServiceRequest::query()->with('vehicle')->where('requested_by', $user->getKey())->whereIn('status', [RequestStatus::Open->value, RequestStatus::Scheduled->value])->latest()->get(),
            ],
        ];
    }

    protected function tenantCanWrite(): bool
    {
        return app(TenantContext::class)->get()->license()->canWrite(self::KEY);
    }

    public function roles(): array
    {
        return collect(FleetRole::assignable())->mapWithKeys(fn (FleetRole $role): array => [$role->value => $role->getLabel()])->all();
    }

    /** Someone linked to an active driver record (same e-mail or added as driver) drives without anything else. */
    public function implicitRole(User $user): ?string
    {
        return app(FleetAccess::class)->driverFor($user) !== null ? FleetRole::Driver->value : null;
    }

    /** Fleet summary on the start page for managers and viewers. */
    public function homeWidgets(User $user): array
    {
        return in_array(app(FleetAccess::class)->fleetRole($user), [FleetRole::Manager, FleetRole::Viewer], true) ? [FleetStatsWidget::class, InspectionStatusWidget::class] : [];
    }

    public function managesPeople(User $user): bool
    {
        return app(FleetAccess::class)->isManager($user);
    }

    public function personFields(): array
    {
        return app(FleetPeople::class)->fields();
    }

    public function personData(User $user): array
    {
        return app(FleetPeople::class)->data($user);
    }

    public function savePerson(User $user, array $data): void
    {
        app(FleetPeople::class)->save($user, $data);
    }

    public function removePerson(User $user): void
    {
        app(FleetPeople::class)->remove($user);
    }

    public function personRoleLabel(User $user): ?string
    {
        return app(FleetPeople::class)->roleLabel($user);
    }

    public function policies(): array
    {
        return [
            Vehicle::class => VehiclePolicy::class,
            Driver::class => DriverPolicy::class,
            FuelStation::class => FuelStationPolicy::class,
            VehicleDocument::class => VehicleDocumentPolicy::class,
            OdometerReading::class => OdometerReadingPolicy::class,
            VehicleEvent::class => VehicleEventPolicy::class,
            FuelUp::class => FuelUpPolicy::class,
            ServiceRequest::class => ServiceRequestPolicy::class,
            Appointment::class => AppointmentPolicy::class,
            Maintenance::class => MaintenancePolicy::class,
            Wash::class => WashPolicy::class,
            Expense::class => ExpensePolicy::class,
            Workshop::class => WorkshopPolicy::class,
            Inspection::class => InspectionPolicy::class,
            ChecklistItem::class => ChecklistItemPolicy::class,
        ];
    }
}
