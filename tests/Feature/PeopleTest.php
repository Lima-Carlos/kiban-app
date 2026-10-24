<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Filament\Pages\PeoplePage;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Filament\Drivers\Pages\ManageDrivers;
use App\Modules\Fleet\Filament\FuelUps\FuelUpResource;
use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\VehicleService;
use App\People\Person;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "People": the manager adds drivers and mechanics inside the app (Core creates the login), sends the
 * access by WhatsApp or e-mail, edits what they do and removes them. Menus follow the role.
 */
class PeopleTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('transportes', ['frota' => []]);
        $this->owner = $this->signIn($this->company);
        $this->car = app(VehicleService::class)->register(['plate' => 'CAR1A11', 'brand' => 'Fiat', 'model' => 'Strada', 'type' => 'pickup', 'fuel_type' => 'flex'], 10000, $this->owner);
    }

    protected function addPerson(array $data): Testable
    {
        return Livewire::test(PeoplePage::class)->callAction('add', $data);
    }

    public function test_manager_adds_a_driver_by_mobile_and_sends_the_access(): void
    {
        $this->addPerson([
            'name' => 'João Pereira',
            'phone' => '(11) 98888-7777',
            'modules' => ['frota' => ['role' => 'driver', 'vehicles' => [$this->car->id], 'license_number' => '0123', 'license_category' => 'B']],
        ])
            ->assertHasNoActionErrors()
            ->assertNotified(__('app.people.access_ready', ['name' => 'João Pereira']));

        // The Core was asked to create the login, with the way back to this company and who invited.
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/tenants/transportes/members')
            && $request->method() === 'POST'
            && $request['phone'] === '(11) 98888-7777'
            && $request['invited_by'] === $this->owner->name);
        $this->assertSame('http://transportes.kiban.test/auth/login', $this->lastInvitationNext);

        $person = User::query()->where('name', 'João Pereira')->sole();
        $this->assertSame('5511988887777', $person->phone);
        $this->assertNull($person->email);
        $this->assertSame(User::ROLE_MEMBER, $person->roleIn($this->company));

        // Already linked to the driver record with the vehicle and license; role "driver".
        $driver = Driver::query()->where('user_id', $person->id)->sole();
        $this->assertSame('0123', $driver->license_number);
        $this->assertSame([$this->car->id], $driver->vehicles()->pluck('fleet_vehicles.id')->all());
        $this->assertSame(FleetRole::Driver, app(FleetAccess::class)->fleetRole($person));

        // The list shows it as "invitation sent".
        Livewire::test(PeoplePage::class)
            ->assertCanSeeTableRecords([Person::query()->where('user_id', $person->id)->sole()])
            ->assertSee(__('app.people.statuses.invited'));
    }

    public function test_mobile_or_email_is_required(): void
    {
        $this->addPerson(['name' => 'Sem contato', 'modules' => ['frota' => ['role' => 'mechanic']]])
            ->assertHasActionErrors(['phone']);
    }

    public function test_mechanic_edit_new_link_and_remove(): void
    {
        $this->addPerson(['name' => 'Rui Mecânico', 'phone' => '11977776666', 'email' => 'rui@oficina.test', 'modules' => ['frota' => ['role' => 'mechanic']]])
            ->assertHasNoActionErrors();
        $rui = User::query()->where('name', 'Rui Mecânico')->sole();
        $ruiPerson = Person::query()->where('user_id', $rui->id)->sole();
        $this->assertSame(FleetRole::Mechanic, app(FleetAccess::class)->fleetRole($rui));

        // Edit: becomes a driver of the car.
        Livewire::test(PeoplePage::class)
            ->callTableAction('edit', $ruiPerson, ['modules' => ['frota' => ['role' => 'driver', 'vehicles' => [$this->car->id]]]])
            ->assertHasNoTableActionErrors();
        $this->assertSame(FleetRole::Driver, app(FleetAccess::class)->fleetRole($rui->fresh()));
        $this->assertSame('driver', app(AccessManager::class)->role($rui, 'frota'));

        // And back to mechanic: the driver record stays in the history, inactive.
        Livewire::test(PeoplePage::class)->callTableAction('edit', $ruiPerson, ['modules' => ['frota' => ['role' => 'mechanic']]]);
        $this->assertFalse(Driver::query()->where('user_id', $rui->id)->sole()->is_active);

        // New access link.
        Livewire::test(PeoplePage::class)
            ->callTableAction('access', $ruiPerson)
            ->assertNotified(__('app.people.access_ready', ['name' => 'Rui Mecânico']));

        // Deactivate: out of the company in the Core and here; the record stays (inactive) and the driver record loses the login.
        Livewire::test(PeoplePage::class)->callTableAction('deactivate', $ruiPerson)->assertNotified(__('app.people.deactivated'));
        $this->assertFalse($ruiPerson->fresh()->is_active);
        $this->assertNull($ruiPerson->fresh()->user_id);
        $this->assertArrayNotHasKey($rui->core_id, $this->coreMembers['transportes']);
        $this->assertNull($rui->fresh()->roleIn($this->company));
        $this->assertNull(Driver::query()->where('name', 'Rui Mecânico')->sole()->user_id);
    }

    public function test_owners_are_locked_and_only_managers_open_people(): void
    {
        // The owner cannot be deactivated (they can still edit their team and documents).
        Livewire::test(PeoplePage::class)
            ->assertTableActionHidden('deactivate', Person::query()->where('user_id', $this->owner->id)->sole());

        // A member made fleet manager can manage people; drivers and mechanics cannot.
        $manager = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($manager, 'frota', 'manager');
        $this->actingAs($manager);
        $this->coreMember($this->company, $manager);
        $this->get($this->company->url('/pessoas'))->assertOk();

        $mechanic = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($mechanic, 'frota', 'mechanic');
        $this->actingAs($mechanic);
        $this->coreMember($this->company, $mechanic);
        $this->get($this->company->url('/pessoas'))->assertForbidden();
    }

    public function test_menus_follow_the_role(): void
    {
        // Manager: everything.
        $this->assertTrue(VehicleResource::shouldRegisterNavigation());
        $this->assertTrue(FuelUpResource::shouldRegisterNavigation());

        // Driver: fuel-ups yes, vehicles only on the start page.
        $driver = User::factory()->memberOf($this->company, 'member')->create();
        Driver::create(['name' => 'Ana', 'email' => $driver->email])->vehicles()->attach($this->car);
        $this->actingAs($driver);
        $this->assertFalse(VehicleResource::shouldRegisterNavigation());
        $this->assertTrue(FuelUpResource::shouldRegisterNavigation());

        // Mechanic: vehicles yes, fuel-ups no.
        $mechanic = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($mechanic, 'frota', 'mechanic');
        $this->actingAs($mechanic);
        $this->assertTrue(VehicleResource::shouldRegisterNavigation());
        $this->assertFalse(FuelUpResource::shouldRegisterNavigation());
    }

    public function test_send_access_from_the_drivers_screen(): void
    {
        $driver = Driver::create(['name' => 'Carlos Motorista']);

        Livewire::test(ManageDrivers::class)
            ->callTableAction('sendAccess', $driver, ['phone' => '11955554444'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('app.people.access_ready', ['name' => 'Carlos Motorista']));

        $person = User::query()->where('phone', '5511955554444')->sole();
        $this->assertSame($person->id, $driver->fresh()->user_id);
        $this->assertSame(FleetRole::Driver, app(FleetAccess::class)->fleetRole($person));
    }

    public function test_installable_app(): void
    {
        $this->get($this->company->url('/manifest.webmanifest'))
            ->assertOk()
            ->assertJsonPath('display', 'standalone')
            ->assertJsonPath('start_url', '/')
            ->assertJsonPath('short_name', 'Transportes');

        $this->get($this->company->url('/'))->assertSee('manifest.webmanifest', false)->assertSee(__('app.install.title'));
        $this->assertFileExists(public_path('sw.js'));
    }
}
