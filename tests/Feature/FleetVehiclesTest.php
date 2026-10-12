<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Fleet\Enums\DueStatus;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Enums\VehicleEventType;
use App\Modules\Fleet\Enums\VehicleStatus;
use App\Modules\Fleet\Filament\Vehicles\Pages\CreateVehicle;
use App\Modules\Fleet\Filament\Vehicles\Pages\ListVehicles;
use App\Modules\Fleet\Filament\Vehicles\Pages\ViewVehicle;
use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\OdometerService;
use App\Modules\Fleet\Services\VehicleService;
use App\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Frota, part 1: vehicles, odometer always up to date, protections against reusing plan slots,
 * drivers seeing only their vehicles.
 */
class FleetVehiclesTest extends TestCase
{
    use RefreshDatabase;

    protected function fleetTenant(string $subdomain = 'transportes', array $module = [], array $tenantClaims = []): Tenant
    {
        return $this->tenant($subdomain, ['frota' => $module], $tenantClaims);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function vehicle(array $data = [], int $odometer = 10000): Vehicle
    {
        return app(VehicleService::class)->register($data + [
            'plate' => 'ABC-1D'.random_int(10, 99),
            'brand' => 'Fiat',
            'model' => 'Strada',
            'type' => 'pickup',
            'fuel_type' => 'flex',
            'tank_capacity' => 55,
        ], $odometer, auth()->user());
    }

    public function test_registering_a_vehicle_keeps_the_odometer_history(): void
    {
        $this->signIn($this->fleetTenant());

        $vehicle = $this->vehicle(['plate' => 'abc 1d23', 'next_oil_change_odometer' => 15000, 'oil_change_interval_distance' => 5000]);

        $this->assertSame('ABC1D23', $vehicle->plate);
        $this->assertSame(10000, $vehicle->odometer);
        $this->assertSame(OdometerSource::Registration, $vehicle->odometerReadings()->sole()->source);
        $this->assertSame(VehicleEventType::Created, $vehicle->events()->sole()->type);
        $this->assertNull($vehicle->identity_locked_at);

        // Brazil: km, liters, real.
        $settings = FleetSetting::current();
        $this->assertSame(['km', 'l', 'BRL'], [$settings->distance_unit, $settings->volume_unit, $settings->currency]);
        $this->assertSame(['mi', 'gal', 'USD'], array_values(array_intersect_key(FleetSetting::defaultsFor('US'), array_flip(['distance_unit', 'volume_unit', 'currency']))));
    }

    public function test_the_odometer_never_goes_back_and_big_jumps_need_confirmation(): void
    {
        $user = $this->signIn($this->fleetTenant());
        $vehicle = $this->vehicle();
        $odometer = app(OdometerService::class);

        $this->assertThrows(fn () => $odometer->record($vehicle, 9999, OdometerSource::Manual, user: $user), FleetException::class);
        $this->assertThrows(fn () => $odometer->record($vehicle, 40000, OdometerSource::Manual, user: $user), FleetException::class);

        $odometer->record($vehicle, 10350, OdometerSource::Manual, user: $user);
        $vehicle->refresh();
        $this->assertSame(10350, $vehicle->odometer);
        // The first record after the registration locks plate and chassis.
        $this->assertNotNull($vehicle->identity_locked_at);

        $odometer->record($vehicle, 40350, OdometerSource::Manual, user: $user, confirmedJump: true);
        $this->assertSame(40350, $vehicle->fresh()->odometer);

        // A manager corrects with a reason, and the correction is logged.
        $this->assertThrows(fn () => $odometer->correct($vehicle, 10400, '  ', $user), FleetException::class);
        $odometer->correct($vehicle, 10400, 'Digitou um zero a mais', $user);
        $this->assertSame(10400, $vehicle->fresh()->odometer);
        $event = $vehicle->events()->where('type', VehicleEventType::OdometerCorrected->value)->sole();
        $this->assertSame(['from' => 40350, 'to' => 10400], $event->data);
        $this->assertSame(4, $vehicle->odometerReadings()->count());
    }

    public function test_plate_and_chassis_lock_after_the_first_record(): void
    {
        $user = $this->signIn($this->fleetTenant());
        $vehicle = $this->vehicle(['plate' => 'AAA1A11']);
        $service = app(VehicleService::class);

        // Before any record: free to fix typos.
        $service->update($vehicle, ['plate' => 'AAA1A12']);
        $this->assertSame('AAA1A12', $vehicle->fresh()->plate);

        app(OdometerService::class)->record($vehicle, 10100, OdometerSource::Manual, user: $user);
        $vehicle->refresh();

        $this->assertThrows(fn () => $service->update($vehicle, ['plate' => 'ZZZ9Z99']), FleetException::class);
        $this->assertSame('AAA1A12', $vehicle->fresh()->plate);

        // Changing it on purpose needs a reason and is logged.
        $service->changeIdentity($vehicle, 'BBB2B22', null, 'Placa Mercosul', $user);
        $event = $vehicle->events()->where('type', VehicleEventType::IdentityChanged->value)->sole();
        $this->assertSame('AAA1A12', $event->data['from']['plate']);
        $this->assertSame('Placa Mercosul', $event->reason);

        // A vehicle with history cannot be deleted, only deactivated.
        $this->assertFalse($service->canDelete($vehicle->fresh()));
        $this->assertFalse($user->can('delete', $vehicle->fresh()));
    }

    public function test_plan_limit_counts_active_vehicles_and_deactivation_is_logged(): void
    {
        $user = $this->signIn($this->fleetTenant(module: ['limits' => ['vehicles' => 2]]));
        $service = app(VehicleService::class);

        $first = $this->vehicle(['plate' => 'AAA1111']);
        $this->vehicle(['plate' => 'BBB2222']);

        $this->assertFalse($user->can('create', Vehicle::class));
        $this->assertThrows(fn () => $this->vehicle(['plate' => 'CCC3333']), FleetException::class);

        // Deactivated vehicles do not count; a reason is required and logged.
        $this->assertThrows(fn () => $service->deactivate($first, ''), FleetException::class);
        $service->deactivate($first, 'Vendido', $user);
        $this->assertTrue($user->can('create', Vehicle::class));
        $third = $this->vehicle(['plate' => 'CCC3333']);

        // Reactivating needs a free slot.
        $this->assertThrows(fn () => $service->reactivate($first->fresh(), user: $user), FleetException::class);
        $service->deactivate($third, 'Devolvido', $user);
        $service->reactivate($first->fresh(), user: $user);
        $this->assertSame(VehicleStatus::Active, $first->fresh()->status);
        $this->assertSame(
            [VehicleEventType::Created, VehicleEventType::Deactivated, VehicleEventType::Reactivated],
            $first->events()->orderBy('id')->get()->pluck('type')->all(),
        );

        // Inactive vehicles accept no records.
        $this->assertThrows(fn () => app(OdometerService::class)->record($third->fresh(), 20000, OdometerSource::Manual), FleetException::class);
    }

    public function test_oil_change_and_revision_status(): void
    {
        $this->signIn($this->fleetTenant());
        $vehicle = $this->vehicle(['next_oil_change_odometer' => 10400, 'next_revision_on' => now()->addDays(60)->toDateString()]);

        $this->assertSame(DueStatus::Soon, $vehicle->oilChangeStatus()); // 400 km left, alert at 500
        $this->assertSame(DueStatus::Ok, $vehicle->revisionStatus());

        $vehicle->forceFill(['next_oil_change_odometer' => 9000, 'next_revision_on' => now()->addDays(10)->toDateString()])->save();
        $this->assertSame(DueStatus::Overdue, $vehicle->fresh()->oilChangeStatus());
        $this->assertSame(DueStatus::Soon, $vehicle->fresh()->revisionStatus());

        $vehicle->forceFill(['next_oil_change_odometer' => null, 'next_revision_on' => null])->save();
        $this->assertSame(DueStatus::NotSet, $vehicle->fresh()->oilChangeStatus());
    }

    public function test_drivers_only_see_their_vehicles(): void
    {
        $tenant = $this->fleetTenant();
        $manager = $this->signIn($tenant);
        $mine = $this->vehicle(['plate' => 'MEU1234']);
        $other = $this->vehicle(['plate' => 'OUT1234']);

        $driver = Driver::create(['name' => 'João', 'email' => 'JOAO@Transportes.test']);
        $driver->vehicles()->attach($mine);
        $this->assertSame($tenant->id, DB::table('fleet_driver_vehicle')->value('tenant_id'));

        // The driver signs in with the same e-mail: linked automatically, sees only their vehicle.
        $person = User::factory()->memberOf($tenant, 'member')->create(['email' => 'joao@transportes.test']);
        $this->actingAs($person);
        $this->assertSame(FleetAccess::DRIVER, app(FleetAccess::class)->role($person));
        $this->assertSame($person->id, $driver->fresh()->user_id);

        $this->get($tenant->url('/frota/veiculos'))->assertOk()->assertSee('MEU1234')->assertDontSee('OUT1234');
        $this->get(VehicleResource::getUrl('view', ['record' => $mine], tenant: null))->assertOk();
        // Another vehicle does not even "exist" for this driver.
        $this->get($tenant->url('/frota/veiculos/'.$other->id))->assertNotFound();
        $this->get($tenant->url('/frota/motoristas'))->assertForbidden();
        $this->assertFalse($person->can('create', Vehicle::class));
        $this->assertFalse($person->can('update', $mine));

        // Any other member only looks.
        $viewer = User::factory()->memberOf($tenant, 'member')->create();
        $this->actingAs($viewer);
        $this->coreMember($tenant, $viewer);
        // Without a role in the Frota: no access, the module does not appear.
        $this->get($tenant->url('/frota/veiculos'))->assertForbidden();
        app(AccessManager::class)->set($viewer, 'frota', 'viewer');
        $this->assertSame(FleetAccess::VIEWER, app(FleetAccess::class)->role($viewer));
        $this->get($tenant->url('/frota/veiculos'))->assertOk()->assertSee('OUT1234');
        $this->assertFalse($viewer->can('update', $other));

        $this->actingAs($manager);
        $this->assertSame(FleetAccess::MANAGER, app(FleetAccess::class)->role($manager));
    }

    public function test_companies_never_mix_vehicles(): void
    {
        $context = app(TenantContext::class);
        $one = $this->fleetTenant('transportes');
        $two = $this->fleetTenant('logistica');

        $this->signIn($one);
        $vehicleOne = $this->vehicle(['plate' => 'SAME123']);

        // Same plate in another company is fine (unique only inside the company).
        $this->signIn($two);
        $vehicleTwo = $this->vehicle(['plate' => 'SAME123']);
        $this->assertSame(['SAME123'], Vehicle::pluck('plate')->all());
        $this->assertNull(Vehicle::find($vehicleOne->id));

        // A driver of one company cannot be linked to a vehicle of another (checked by the database).
        $driverTwo = Driver::create(['name' => 'Ana']);
        $this->expectException(QueryException::class);
        $context->run($two, fn () => DB::table('fleet_driver_vehicle')->insert([
            'tenant_id' => $two->id, 'driver_id' => $driverTwo->id, 'vehicle_id' => $vehicleOne->id, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->assertNotNull($vehicleTwo);
    }

    public function test_screens_for_the_manager(): void
    {
        $tenant = $this->fleetTenant();
        $this->signIn($tenant);

        Livewire::test(CreateVehicle::class)
            ->fillForm([
                'plate' => 'qwe-4r56',
                'brand' => 'Volkswagen',
                'model' => 'Saveiro',
                'type' => 'pickup',
                'fuel_type' => 'flex',
                'odometer' => 52300,
                'next_oil_change_odometer' => 55000,
                'oil_change_interval_distance' => 5000,
                'next_revision_on' => now()->addMonths(3)->toDateString(),
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $vehicle = Vehicle::sole();
        $this->assertSame('QWE4R56', $vehicle->plate);
        $this->assertSame(52300, $vehicle->odometer);

        // Same plate again (any format): refused.
        Livewire::test(CreateVehicle::class)
            ->fillForm(['plate' => 'QWE 4R56', 'brand' => 'X', 'model' => 'Y', 'type' => 'car', 'fuel_type' => 'flex', 'odometer' => 1])
            ->call('create')
            ->assertHasFormErrors(['plate']);

        foreach (['/frota/veiculos', '/frota/veiculos/novo', '/frota/veiculos/'.$vehicle->id, '/frota/veiculos/'.$vehicle->id.'/editar', '/frota/motoristas', '/frota/postos', '/frota/configuracoes', '/'] as $path) {
            $this->get($tenant->url($path))->assertOk();
        }

        $this->get($tenant->url('/'))->assertSee(__('fleet.steps.vehicle'))->assertSee(__('fleet.steps.driver'));
        Livewire::test(ListVehicles::class)->assertCanSeeTableRecords([$vehicle]);
    }

    public function test_vehicle_page_buttons(): void
    {
        $tenant = $this->fleetTenant();
        $this->signIn($tenant);
        $vehicle = $this->vehicle(['plate' => 'BTN1A23']);
        $other = $this->vehicle(['plate' => 'OTH1A23']);

        Livewire::test(ViewVehicle::class, ['record' => $vehicle->getRouteKey()])
            ->callAction('informOdometer', ['value' => 9000])
            ->assertHasActionErrors(['value']);
        Livewire::test(ViewVehicle::class, ['record' => $vehicle->getRouteKey()])
            ->callAction('informOdometer', ['value' => 10500])
            ->assertHasNoActionErrors()
            ->assertNotified(__('fleet.notifications.saved'));
        $this->assertSame(10500, $vehicle->fresh()->odometer);

        Livewire::test(ViewVehicle::class, ['record' => $vehicle->getRouteKey()])
            ->callAction('changeIdentity', ['plate' => 'oth-1a23', 'reason' => 'Teste'])
            ->assertHasActionErrors(['plate']);
        Livewire::test(ViewVehicle::class, ['record' => $vehicle->getRouteKey()])
            ->callAction('changeIdentity', ['plate' => 'NEW-2B34', 'reason' => 'Placa nova'])
            ->assertHasNoActionErrors();
        $this->assertSame('NEW2B34', $vehicle->fresh()->plate);

        Livewire::test(ViewVehicle::class, ['record' => $vehicle->getRouteKey()])
            ->callAction('changeStatus', ['status' => 'inactive', 'reason' => ''])
            ->assertHasActionErrors(['reason']);
        Livewire::test(ViewVehicle::class, ['record' => $vehicle->getRouteKey()])
            ->callAction('changeStatus', ['status' => 'inactive', 'reason' => 'Vendido'])
            ->assertHasNoActionErrors();
        $this->assertSame(VehicleStatus::Inactive, $vehicle->fresh()->status);
        $this->assertNotNull($other);
    }

    public function test_read_only_contract_blocks_changes(): void
    {
        $tenant = $this->fleetTenant(module: ['read_only' => true, 'status' => 'read_only']);
        $user = $this->signIn($tenant);

        $vehicle = Vehicle::create(['plate' => 'RRR1R11', 'brand' => 'Fiat', 'model' => 'Uno', 'type' => 'car', 'fuel_type' => 'flex']);

        $this->get($tenant->url('/frota/veiculos/'.$vehicle->id))->assertOk();
        $this->assertFalse($user->can('create', Vehicle::class));
        $this->assertFalse($user->can('update', $vehicle));
        $this->get($tenant->url('/frota/configuracoes'))->assertForbidden();
    }
}
