<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Fleet\Enums\AppointmentStatus;
use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Enums\RequestStatus;
use App\Modules\Fleet\Enums\ServiceKind;
use App\Modules\Fleet\Filament\Appointments\Pages\ManageAppointments;
use App\Modules\Fleet\Filament\Dashboard\FleetStatsWidget;
use App\Modules\Fleet\Filament\Requests\Pages\ManageServiceRequests;
use App\Modules\Fleet\Models\Appointment;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\Expense;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\Maintenance;
use App\Modules\Fleet\Models\ServiceRequest;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\Wash;
use App\Modules\Fleet\Models\Workshop;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\FleetReports;
use App\Modules\Fleet\Services\FuelService;
use App\Modules\Fleet\Services\MaintenanceService;
use App\Modules\Fleet\Services\OdometerService;
use App\Modules\Fleet\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Core Frota, part 3: driver requests, schedule, maintenance (next oil change / revision), washes, expenses,
 * workshops, the mechanic's menu and the dashboard with every cost.
 */
class FleetMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $manager;

    protected Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('transportes', ['frota' => []]);
        $this->manager = $this->signIn($this->company);
        $this->car = app(VehicleService::class)->register([
            'plate' => 'CAR1A11', 'brand' => 'Fiat', 'model' => 'Strada', 'type' => 'pickup', 'fuel_type' => 'flex', 'tank_capacity' => 55,
            'oil_change_interval_distance' => 10000, 'oil_change_interval_months' => 6,
            'revision_interval_distance' => 20000,
        ], 10000, $this->manager);
    }

    protected function service(): MaintenanceService
    {
        return app(MaintenanceService::class);
    }

    /** @param  array<int, Vehicle>  $vehicles */
    protected function driver(array $vehicles = []): User
    {
        $user = User::factory()->memberOf($this->company, 'member')->create();
        Driver::create(['name' => $user->name, 'email' => $user->email])->vehicles()->attach(collect($vehicles)->map->getKey()->all());

        return $user;
    }

    protected function mechanic(): User
    {
        $user = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($user, 'frota', 'mechanic');

        return $user;
    }

    public function test_driver_request_is_scheduled_and_finished_with_the_next_oil_change(): void
    {
        $driver = $this->driver([$this->car]);
        $mechanic = $this->mechanic();
        $workshop = Workshop::create(['name' => 'Auto Center Silva']);

        // The driver asks from the phone (photo optional).
        $this->actingAs($driver);
        Livewire::test(ManageServiceRequests::class)
            ->callAction('request', ['vehicle_id' => $this->car->id, 'kind' => 'maintenance', 'description' => 'Barulho no freio', 'photo_path' => UploadedFile::fake()->image('freio.jpg')])
            ->assertHasNoActionErrors()
            ->assertNotified(__('fleet.requests.sent'));

        $request = ServiceRequest::sole();
        $this->assertSame(RequestStatus::Open, $request->status);
        $this->assertSame($driver->id, $request->requested_by);
        $this->assertNotNull($request->photo_path);
        $this->assertThrows(fn () => $this->service()->request($this->car, ServiceKind::Wash, '   ', $driver), FleetException::class);

        // A driver cannot schedule.
        $this->assertThrows(fn () => $this->service()->scheduleRequest($request, ['scheduled_at' => now()->addDay()], $driver), FleetException::class);

        // The mechanic sees it on the start page and schedules it.
        $this->actingAs($mechanic);
        $this->get($this->company->url('/'))->assertOk()->assertSee(__('fleet.home.waiting_requests'))->assertSee('Barulho no freio');
        $appointment = $this->service()->scheduleRequest($request, ['scheduled_at' => now()->addDay(), 'workshop_id' => $workshop->id, 'assigned_to' => $mechanic->id], $mechanic);
        $this->assertSame(RequestStatus::Scheduled, $request->fresh()->status);
        $this->assertThrows(fn () => $this->service()->scheduleRequest($request->fresh(), ['scheduled_at' => now()], $mechanic), FleetException::class);

        // Finishing from the schedule records the maintenance.
        Livewire::test(ManageAppointments::class)
            ->callTableAction('finishMaintenance', $appointment, [
                'type' => 'corrective',
                'services' => ['brakes', 'oil_change'],
                'performed_on' => now()->toDateString(),
                'odometer' => 12500,
                'parts' => 350.5,
                'labor' => 120,
            ])
            ->assertHasNoTableActionErrors();

        $maintenance = Maintenance::sole();
        $this->assertSame(47050, $maintenance->total_cents);
        $this->assertSame($workshop->id, $maintenance->workshop_id);
        $this->assertSame(AppointmentStatus::Done, $appointment->fresh()->status);
        $this->assertSame(RequestStatus::Done, $request->fresh()->status);

        $car = $this->car->fresh();
        $this->assertSame(12500, $car->odometer);
        $this->assertSame(OdometerSource::Maintenance, $car->odometerReadings()->latest('id')->first()->source);
        $this->assertSame(22500, $car->next_oil_change_odometer);
        $this->assertSame(now()->addMonths(6)->toDateString(), $car->next_oil_change_on->toDateString());
        $this->assertNull($car->next_revision_odometer); // a revision was not done

        // History is never deleted.
        $this->assertFalse($this->manager->can('delete', $maintenance));
        $this->assertFalse($this->manager->can('delete', $workshop->fresh()));
        $this->assertTrue($this->manager->can('delete', Workshop::create(['name' => 'Sem uso'])));
    }

    public function test_revision_in_the_past_fits_between_readings(): void
    {
        $this->travel(-20)->days();
        $old = app(VehicleService::class)->register(['plate' => 'OLD2B22', 'brand' => 'VW', 'model' => 'Gol', 'type' => 'car', 'fuel_type' => 'flex', 'revision_interval_distance' => 15000], 30000, $this->manager);
        $this->travelBack();
        app(OdometerService::class)->record($old, 31000, OdometerSource::Manual, null, $this->manager);

        // A revision typed 10 days late, with a reading between the two.
        $this->service()->recordMaintenance($old, ['type' => 'preventive', 'services' => ['revision'], 'performed_on' => now()->subDays(10), 'odometer' => 30500], $this->manager);
        $old->refresh();
        $this->assertSame(31000, $old->odometer); // the current reading does not go back
        $this->assertSame(45500, $old->next_revision_odometer);

        // A reading that does not fit is refused.
        $this->assertThrows(fn () => $this->service()->recordMaintenance($old, ['type' => 'preventive', 'services' => ['revision'], 'performed_on' => now()->subDays(10), 'odometer' => 31500], $this->manager), FleetException::class);
    }

    public function test_washes_expenses_and_cancel(): void
    {
        $mechanic = $this->mechanic();
        $this->actingAs($mechanic);

        // Wash without odometer: no reading.
        $this->service()->recordWash($this->car, ['kind' => 'simple', 'washed_on' => now(), 'cost_cents' => 4000], $mechanic);
        $this->assertSame(10000, $this->car->fresh()->odometer);
        $this->service()->recordWash($this->car, ['kind' => 'complete', 'washed_on' => now(), 'odometer' => 10100, 'cost_cents' => 8000], $mechanic);
        $this->assertSame(10100, $this->car->fresh()->odometer);
        $this->assertSame(2, Wash::count());

        // Expenses only by the manager.
        $this->assertThrows(fn () => $this->service()->recordExpense(['category' => 'fine', 'amount_cents' => 19500, 'spent_on' => now()], $mechanic), FleetException::class);
        $this->service()->recordExpense(['vehicle_id' => $this->car->id, 'category' => 'fine', 'amount_cents' => 19500, 'spent_on' => now()], $this->manager);
        $this->service()->recordExpense(['category' => 'insurance', 'amount_cents' => 100000, 'spent_on' => now()], $this->manager);
        $this->assertSame(2, Expense::count());

        // Canceling an appointment sends the driver's request back to "waiting".
        $driver = $this->driver([$this->car]);
        $request = $this->service()->request($this->car, ServiceKind::Wash, 'Lavar por dentro', $driver);
        $appointment = $this->service()->scheduleRequest($request, ['scheduled_at' => now()->addDays(2)], $mechanic);
        $this->service()->cancel($appointment, 'Chuva', $mechanic);
        $this->assertSame(AppointmentStatus::Canceled, $appointment->fresh()->status);
        $this->assertSame(RequestStatus::Open, $request->fresh()->status);
        $this->assertThrows(fn () => $this->service()->recordWash($this->car, ['kind' => 'simple', 'washed_on' => now()], $mechanic, $appointment->fresh()), FleetException::class);

        // Rejecting needs an answer and closes it.
        $this->service()->rejectRequest($request->fresh(), 'Já foi lavado ontem', $mechanic);
        $this->assertSame(RequestStatus::Rejected, $request->fresh()->status);
    }

    public function test_menus_of_each_role(): void
    {
        $mechanic = $this->mechanic();
        $this->actingAs($mechanic);
        $this->get($this->company->url('/frota/solicitacoes'))->assertOk();
        $this->get($this->company->url('/frota/agenda'))->assertOk();
        $this->get($this->company->url('/frota/agenda/calendario'))->assertOk()->assertSee(__('fleet.appointments.calendar'));
        $this->get($this->company->url('/frota/manutencoes'))->assertOk();
        $this->get($this->company->url('/frota/lavagens'))->assertOk();
        $this->get($this->company->url('/frota/oficinas'))->assertOk();
        $this->get($this->company->url('/frota/despesas'))->assertForbidden();
        $this->get($this->company->url('/frota/painel'))->assertForbidden();

        // Driver: only their requests.
        $driver = $this->driver([$this->car]);
        $other = app(VehicleService::class)->register(['plate' => 'OTH3C33', 'brand' => 'Ford', 'model' => 'Ka', 'type' => 'car', 'fuel_type' => 'flex'], 5000, $this->manager);
        $this->service()->request($other, ServiceKind::Maintenance, 'Pedido de outro carro', $this->manager);
        $this->actingAs($driver);
        $this->assertThrows(fn () => $this->service()->request($other, ServiceKind::Maintenance, 'Não é meu', $driver), FleetException::class);
        $this->get($this->company->url('/frota/solicitacoes'))->assertOk()->assertDontSee('Pedido de outro carro');
        $this->get($this->company->url('/'))->assertOk()->assertSee(__('fleet.requests.create'));
        $this->get($this->company->url('/frota/agenda'))->assertForbidden();
        $this->get($this->company->url('/frota/manutencoes'))->assertForbidden();
        $this->get($this->company->url('/frota/despesas'))->assertForbidden();

        // Manager: everything.
        $this->actingAs($this->manager);
        $this->get($this->company->url('/frota/despesas'))->assertOk();
        $this->get($this->company->url('/frota/veiculos/'.$this->car->id))->assertOk();
    }

    public function test_dashboard_adds_every_cost(): void
    {
        $station = FuelStation::create(['name' => 'Posto Centro']);
        foreach ([[10000, 40, 24000], [10500, 40, 24000]] as [$odometer, $volume, $cents]) {
            $fuelUp = app(FuelService::class)->authorize($this->car, $station, $odometer, $this->manager);
            app(FuelService::class)->complete($fuelUp, $volume, $cents, true, $this->manager, null, FuelType::Gasoline);
        }
        $this->service()->recordMaintenance($this->car, ['type' => 'preventive', 'services' => ['oil_change'], 'performed_on' => now(), 'odometer' => 10500, 'parts_cents' => 20000, 'labor_cents' => 5000], $this->manager);
        $this->service()->recordWash($this->car, ['kind' => 'simple', 'washed_on' => now(), 'cost_cents' => 5000], $this->manager);
        $this->service()->recordExpense(['vehicle_id' => $this->car->id, 'category' => 'toll', 'amount_cents' => 10000, 'spent_on' => now()], $this->manager);

        $totals = app(FleetReports::class)->totals(3);
        $this->assertSame(48000, $totals['spent_cents']);
        $this->assertSame(25000, $totals['maintenance_cents']);
        $this->assertSame(5000, $totals['wash_cents']);
        $this->assertSame(10000, $totals['expense_cents']);
        $this->assertSame(88000, $totals['total_cents']);
        // Drove 10.000 → 10.500 km: R$ 880 ÷ 500 km = R$ 1,76/km (total spent ÷ distance really driven).
        $this->assertSame(500, $totals['driven']);
        $this->assertSame(176, $totals['cost_per_distance_cents']);

        // A maintenance at a higher odometer counts as driven too (not only the stretches between full tanks).
        app(MaintenanceService::class)->recordMaintenance($this->car, ['type' => 'corrective', 'services' => ['brakes'], 'performed_on' => now(), 'odometer' => 11000], $this->manager);
        $this->assertSame(1000, app(FleetReports::class)->totals(3)['driven']);
        $this->assertSame(500, app(FleetReports::class)->totals(3)['distance']); // the consumption average still uses the full tanks
        $this->assertSame(25000, collect(app(FleetReports::class)->monthly(3))->sum('maintenance_cents'));

        $this->get($this->company->url('/frota/painel'))->assertOk();
        Livewire::test(FleetStatsWidget::class)->assertSee(__('fleet.dashboard.total_spent'));
    }

    public function test_companies_never_mix_maintenance(): void
    {
        $this->service()->recordWash($this->car, ['kind' => 'simple', 'washed_on' => now(), 'cost_cents' => 4000], $this->manager);
        Workshop::create(['name' => 'Oficina A']);

        $other = $this->tenant('logistica', ['frota' => []]);
        $this->signIn($other);
        $this->assertSame(0, Wash::count());
        $this->assertSame(0, Workshop::count());
        $this->assertSame(0, Appointment::count());
    }
}
