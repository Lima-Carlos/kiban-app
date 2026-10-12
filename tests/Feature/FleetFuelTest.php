<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Enums\FuelUpFlag;
use App\Modules\Fleet\Enums\FuelUpStatus;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Filament\FuelUps\Pages\RequestFuelCode;
use App\Modules\Fleet\Filament\FuelUps\Pages\ViewFuelUp;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\FleetReports;
use App\Modules\Fleet\Services\FuelService;
use App\Modules\Fleet\Services\OdometerService;
use App\Modules\Fleet\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Frota, part 2: fuel-ups with a code, the station page, consumption, warnings, team roles, dashboard.
 */
class FleetFuelTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $manager;

    protected Vehicle $car;

    protected FuelStation $station;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('transportes', ['frota' => []]);
        $this->manager = $this->signIn($this->company);
        $this->car = app(VehicleService::class)->register(['plate' => 'CAR1A11', 'brand' => 'Fiat', 'model' => 'Strada', 'type' => 'pickup', 'fuel_type' => 'flex', 'tank_capacity' => 55], 10000, $this->manager);
        $this->station = FuelStation::create(['name' => 'Posto Centro']);
    }

    /**
     * A member linked to a driver record (same e-mail) and, optionally, to vehicles.
     *
     * @param  array<int, Vehicle>  $vehicles
     */
    protected function driver(array $vehicles = [], array $attributes = []): User
    {
        $user = User::factory()->memberOf($this->company, 'member')->create();
        $driver = Driver::create($attributes + ['name' => $user->name, 'email' => $user->email]);
        $driver->vehicles()->attach(collect($vehicles)->map->getKey()->all());

        return $user;
    }

    protected function fuel(): FuelService
    {
        return app(FuelService::class);
    }

    public function test_driver_generates_a_code_and_the_station_checks_it(): void
    {
        $driver = $this->driver([$this->car]);
        $this->actingAs($driver);

        $fuelUp = $this->fuel()->authorize($this->car, $this->station, 10420, $driver, 'tenants/1/fleet/fuel/painel.jpg');

        $this->assertSame(FuelUpStatus::Authorized, $fuelUp->status);
        $this->assertMatchesRegularExpression('/^[ACDEFGHJKMNPQRTUVWXY34679]{6}$/', $fuelUp->code);
        $this->assertSame(10420, $this->car->fresh()->odometer);
        $reading = $this->car->odometerReadings()->latest('id')->first();
        $this->assertSame(OdometerSource::Fuel, $reading->source);
        $this->assertTrue($reading->record->is($fuelUp));

        // Only one open code per vehicle.
        $this->assertThrows(fn () => $this->fuel()->authorize($this->car, $this->station, 10430, $driver), FleetException::class);

        // Station page (no login): typed with dash and lower case, it is found; wrong code is refused.
        auth()->logout();
        $this->get($this->company->url('/posto'))->assertOk()->assertSee(__('fleet.station.title'));
        $this->post($this->company->url('/posto'), ['code' => strtolower(FuelService::formatCode($fuelUp->code))])
            ->assertOk()->assertSee(__('fleet.station.valid'))->assertSee('CAR1A11')->assertSee('Gasolina / Etanol');
        $this->assertNotNull($fuelUp->fresh()->station_checked_at);
        $this->post($this->company->url('/posto'), ['code' => 'XXX-XXX'])->assertOk()->assertSee(__('fleet.station.invalid'));

        // After the 2 hours the code no longer works at the station.
        $this->travel(2)->hours();
        $this->travel(1)->minutes();
        $this->post($this->company->url('/posto'), ['code' => $fuelUp->code])->assertSee(__('fleet.station.invalid'));
    }

    public function test_station_page_limits_attempts_and_codes_are_per_company(): void
    {
        $other = $this->tenant('logistica', ['frota' => []]);
        $fuelUp = $this->fuel()->authorize($this->car, $this->station, 10100, $this->manager);

        // A code of one company does not work on another company's address.
        $this->post($other->url('/posto'), ['code' => $fuelUp->code])->assertSee(__('fleet.station.invalid'));

        for ($i = 0; $i < 9; $i++) {
            $this->post($this->company->url('/posto'), ['code' => 'AAA-'.$i.$i.$i]);
        }

        $this->post($this->company->url('/posto'), ['code' => 'AAA-999'])->assertStatus(429);
    }

    public function test_consumption_from_full_tank_to_full_tank_and_warnings(): void
    {
        $user = $this->manager;

        // First full tank: no consumption yet (nothing to compare).
        $first = $this->fuel()->authorize($this->car, $this->station, 10000, $user);
        $this->fuel()->complete($first, 40, 24000, true, $user, null, FuelType::Gasoline);
        $this->assertNull($first->fresh()->consumption);
        $this->assertSame('6.000', $first->fresh()->unit_price);

        // A partial fuel-up in between counts in the next full tank.
        $partial = $this->fuel()->authorize($this->car, $this->station, 10200, $user);
        $this->fuel()->complete($partial, 10, 6000, false, $user, null, FuelType::Gasoline);

        $second = $this->fuel()->authorize($this->car, $this->station, 10500, $user);
        $this->fuel()->complete($second, 30, 18000, true, $user, null, FuelType::Gasoline);
        $second->refresh();
        $this->assertSame(500, $second->distance);
        $this->assertSame('40.000', $second->segment_volume);
        $this->assertSame('12.500', $second->consumption);
        $this->assertSame([], $second->flags);

        // Much worse than the average (12.5 → 5 km/l), diesel in a flex car and more than the tank: warnings.
        $third = $this->fuel()->authorize($this->car, $this->station, 10800, $user);
        $this->fuel()->complete($third, 60, 36000, true, $user, null, FuelType::Diesel);
        $flags = $third->fresh()->flagList();
        $this->assertContains(FuelUpFlag::HighConsumption, $flags);
        $this->assertContains(FuelUpFlag::WrongFuel, $flags);
        $this->assertContains(FuelUpFlag::OverCapacity, $flags);

        $this->assertSame(12.5, $this->fuel()->averageConsumption($this->car, null, $third->id));

        // Closed fuel-ups cannot be finished again; a finished one is never edited.
        $this->assertThrows(fn () => $this->fuel()->complete($third->fresh(), 10, 100, true, $user), FleetException::class);
        $this->assertFalse($user->can('update', $third->fresh()));
        $this->assertFalse($user->can('delete', $third->fresh()));
    }

    public function test_fueling_a_vehicle_that_is_not_yours(): void
    {
        $truck = app(VehicleService::class)->register(['plate' => 'TRK2B22', 'brand' => 'VW', 'model' => 'Delivery', 'type' => 'truck', 'fuel_type' => 'diesel'], 50000, $this->manager);
        $driver = $this->driver([$truck]);
        $this->actingAs($driver);

        // Not their vehicle: a reason is required, and the fuel-up is flagged for the manager.
        $this->assertThrows(fn () => $this->fuel()->authorize($this->car, $this->station, 10100, $driver), FleetException::class);
        $fuelUp = $this->fuel()->authorize($this->car, $this->station, 10100, $driver, null, 'Gestor pediu para levar a Strada');
        $this->assertSame([FuelUpFlag::OtherVehicle], $fuelUp->flagList());
        $this->assertSame('Gestor pediu para levar a Strada', $fuelUp->reason);
        $this->fuel()->cancel($fuelUp, 'Teste', $driver);

        // The company can turn this off.
        FleetSetting::current()->update(['allow_other_vehicle' => false]);
        $this->assertThrows(fn () => $this->fuel()->authorize($this->car, $this->station, 10200, $driver, null, 'Motivo'), FleetException::class);

        // A backup driver ("any vehicle") fuels any vehicle without a reason or flag.
        $backup = $this->driver([], ['any_vehicle' => true]);
        $this->actingAs($backup);
        $fuelUp = $this->fuel()->authorize($this->car, $this->station, 10200, $backup);
        $this->assertSame([], $fuelUp->flagList());

        // A manager fuels any vehicle too, without being a driver.
        $this->actingAs($this->manager);
        $this->assertNull($this->fuel()->ownVehicleIds($this->manager));

        // A viewer cannot fuel.
        $viewer = User::factory()->memberOf($this->company, 'member')->create();
        $this->assertFalse($this->fuel()->canFuel($viewer));
        $this->assertThrows(fn () => $this->fuel()->authorize($truck, $this->station, 50100, $viewer), FleetException::class);
    }

    public function test_manager_types_a_receipt_from_the_past(): void
    {
        // Registered at 10,000 three days ago; today the odometer is 11,000.
        $this->travel(3)->days();
        app(OdometerService::class)->record($this->car, 11000, OdometerSource::Manual, user: $this->manager);

        // A receipt from two days ago, between those readings: accepted.
        $fuelUp = $this->fuel()->register([
            'vehicle' => $this->car, 'station' => $this->station, 'odometer' => 10700, 'volume' => 35.5, 'total_cents' => 21300,
            'full_tank' => true, 'fueled_at' => now()->subDays(2),
        ], $this->manager);
        $this->assertSame(FuelUpStatus::Completed, $fuelUp->status);
        $this->assertSame(11000, $this->car->fresh()->odometer); // the current odometer does not change

        // Out of order for that date: refused.
        $this->assertThrows(fn () => $this->fuel()->register([
            'vehicle' => $this->car, 'station' => null, 'odometer' => 12000, 'volume' => 10, 'total_cents' => 6000,
            'full_tank' => false, 'fueled_at' => now()->subDays(2),
        ], $this->manager), FleetException::class);

        // Only managers type fuel-ups directly.
        $driver = $this->driver([$this->car]);
        $this->assertThrows(fn () => $this->fuel()->register([
            'vehicle' => $this->car, 'station' => null, 'odometer' => 11100, 'volume' => 10, 'total_cents' => 6000, 'full_tank' => false,
        ], $driver), FleetException::class);
    }

    public function test_unfinished_codes_expire(): void
    {
        $fuelUp = $this->fuel()->authorize($this->car, $this->station, 10100, $this->manager);

        $this->travel(25)->hours();
        $this->artisan('kiban:fleet')->assertSuccessful();

        $this->assertSame(FuelUpStatus::Expired, $fuelUp->fresh()->status);
    }

    public function test_team_roles_and_what_each_one_sees(): void
    {
        $member = User::factory()->memberOf($this->company, 'member')->create();
        $access = app(FleetAccess::class);
        // Without a role: no access at all (the Frota does not even appear).
        $this->assertSame(FleetRole::None, $access->fleetRole($member));

        app(AccessManager::class)->set($member, 'frota', 'mechanic');
        $this->assertSame(FleetRole::Mechanic, $access->fleetRole($member));
        $this->assertTrue($this->fuel()->canFuel($member));
        $this->assertNull($this->fuel()->ownVehicleIds($member));

        // Mechanic: vehicles and fuel-ups yes; dashboard, team, drivers and stations no.
        $this->actingAs($member);
        $this->get($this->company->url('/frota/veiculos'))->assertOk();
        $this->get($this->company->url('/frota/abastecimentos'))->assertOk();
        $this->get($this->company->url('/frota/painel'))->assertForbidden();
        $this->get($this->company->url('/pessoas'))->assertForbidden();
        $this->get($this->company->url('/frota/motoristas'))->assertForbidden();
        $this->get($this->company->url('/frota/postos'))->assertForbidden();

        // A member made "manager of the fleet" (without being company administrator).
        app(AccessManager::class)->set($member, 'frota', 'manager');
        $this->assertTrue($access->isManager($member));
        $this->get($this->company->url('/frota/painel'))->assertOk();
        $this->get($this->company->url('/pessoas'))->assertOk()->assertSee($member->email);

        // Driver: home with their vehicles and the "fuel up" button, no setup steps.
        $driver = $this->driver([$this->car]);
        $this->actingAs($driver);
        $this->get($this->company->url('/'))->assertOk()
            ->assertSee(__('fleet.home.my_vehicles'))
            ->assertSee('CAR1A11')
            ->assertDontSee(__('fleet.steps.vehicle'));
        $this->get($this->company->url('/frota/painel'))->assertForbidden();
        $this->get($this->company->url('/frota/abastecimentos/gerar-codigo'))->assertOk();
    }

    public function test_drivers_and_stations_with_history_are_not_deleted(): void
    {
        $driverUser = $this->driver([$this->car]);
        $driver = Driver::query()->where('user_id', $driverUser->id)->first() ?? Driver::query()->where('email', $driverUser->email)->sole();
        $this->actingAs($driverUser);
        $this->fuel()->authorize($this->car, $this->station, 10100, $driverUser);

        $this->actingAs($this->manager);
        $this->assertFalse($this->manager->can('delete', $driver->fresh()));
        $this->assertFalse($this->manager->can('delete', $this->station->fresh()));
        $this->assertTrue($this->manager->can('delete', FuelStation::create(['name' => 'Sem uso'])));
    }

    public function test_screens_generate_and_finish(): void
    {
        $driver = $this->driver([$this->car]);
        $this->actingAs($driver);

        Livewire::test(RequestFuelCode::class)
            ->assertSet('data.vehicle_id', $this->car->id) // their only vehicle is already selected
            ->fillForm([
                'station_id' => $this->station->id,
                'odometer' => 9000,
                'odometer_photo_path' => UploadedFile::fake()->image('painel.jpg'),
            ])
            ->call('generate')
            ->assertHasFormErrors(['odometer']);

        Livewire::test(RequestFuelCode::class)
            ->fillForm([
                'station_id' => $this->station->id,
                'odometer' => 10250,
                'odometer_photo_path' => UploadedFile::fake()->image('painel.jpg'),
            ])
            ->call('generate')
            ->assertHasNoFormErrors();

        $fuelUp = FuelUp::sole();
        $this->assertNotNull($fuelUp->odometer_photo_path);
        $this->assertStringStartsWith('tenants/'.$this->company->id.'/fleet/fuel/', $fuelUp->odometer_photo_path);

        $this->get($this->company->url('/'))->assertSee(FuelService::formatCode($fuelUp->code));
        $this->get($this->company->url('/frota/abastecimentos/'.$fuelUp->id))->assertOk()->assertSee(FuelService::formatCode($fuelUp->code));

        Livewire::test(ViewFuelUp::class, ['record' => $fuelUp->getRouteKey()])
            ->callAction('finish', [
                'fuel' => 'ethanol',
                'volume' => 42.37,
                'total' => 199.14,
                'full_tank' => true,
                'receipt_path' => UploadedFile::fake()->image('nota.jpg'),
            ])
            ->assertHasNoActionErrors();

        $fuelUp->refresh();
        $this->assertSame(FuelUpStatus::Completed, $fuelUp->status);
        $this->assertSame(19914, $fuelUp->total_cents);
        $this->assertSame(FuelType::Ethanol, $fuelUp->fuel);
        $this->assertNotNull($fuelUp->receipt_path);
    }

    public function test_dashboard_numbers(): void
    {
        $user = $this->manager;
        foreach ([[10000, 40, 24000], [10500, 40, 24000], [11100, 50, 30000]] as [$odometer, $volume, $cents]) {
            $fuelUp = $this->fuel()->authorize($this->car, $this->station, $odometer, $user);
            $this->fuel()->complete($fuelUp, $volume, $cents, true, $user, null, FuelType::Gasoline);
        }

        $totals = app(FleetReports::class)->totals(3);
        $this->assertSame(78000, $totals['spent_cents']);
        $this->assertSame(130.0, $totals['volume']);
        $this->assertSame(1100, $totals['distance']); // 500 + 600, between full tanks
        $this->assertSame(12.22, $totals['consumption']); // 1100 / 90
        $this->assertSame(3, $totals['fuel_ups']);
        $this->assertCount(3, app(FleetReports::class)->monthly(3));

        $this->get($this->company->url('/frota/painel'))->assertOk()->assertSee(__('fleet.dashboard.title'));
    }
}
