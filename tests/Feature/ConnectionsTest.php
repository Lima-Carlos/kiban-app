<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Connections\Bridges\FieldFleet;
use App\Connections\Bridges\FieldTimeClock;
use App\Connections\Bridges\FleetTimeClock;
use App\Connections\Connection;
use App\Connections\ConnectionCatalog;
use App\Connections\ConnectionDismissal;
use App\Filament\Pages\ConnectionsPage;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Filament\Dashboard\FieldTechniciansWidget;
use App\Modules\Field\Filament\ExecuteOrderPage;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\Field\Services\OrderExecutionService;
use App\Modules\Field\Services\WorkOrderService;
use App\Modules\Fleet\Enums\InspectionFrequency;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\Expense;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\Inspection;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetReports;
use App\Modules\Fleet\Services\OdometerService;
use App\Modules\Fleet\Services\VehicleService;
use App\Modules\ModuleRegistry;
use App\Modules\TimeClock\Filament\PunchPage;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use App\People\Person;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Connections between the systems: the catalog (which work for the company and matter to each person), the window
 * "your systems now work together" (dismissed per person, per connection and per company; a new connection shows
 * up again), the "Systems together" page, and the new features: the time clock reminder at the first and last work
 * order and the technicians' utilization (Campo + Ponto), the inspection suggested after clocking in (Frota +
 * Ponto) and the estimated travel cost (Campo + Frota).
 */
class ConnectionsTest extends TestCase
{
    use RefreshDatabase;

    protected const TZ = 'America/Sao_Paulo';

    protected const ALL = ['frota' => [], 'ponto' => [], 'campo' => []];

    protected Tenant $company;

    protected User $owner;

    protected int $cpf = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        FilamentTimezone::set(self::TZ);
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     */
    protected function company(array $modules = self::ALL, string $subdomain = 'aurora'): void
    {
        $this->company = $this->tenant($subdomain, $modules);
        $this->owner = $this->signIn($this->company);
    }

    /**
     * @param  array<string, string>  $roles  module => role
     */
    protected function member(string $name, array $roles = []): User
    {
        $user = User::factory()->memberOf($this->company, 'member')->create(['name' => $name]);
        $this->coreMember($this->company, $user);

        foreach ($roles as $module => $role) {
            app(AccessManager::class)->set($user, $module, $role);
        }

        return $user;
    }

    /** The person clocks in (an employee of the time clock, with a valid CPF). */
    protected function clocksIn(User $user): Employee
    {
        $workplace = Workplace::query()->first() ?? Workplace::create(['name' => 'Base', 'timezone' => self::TZ]);
        $schedule = Schedule::query()->first() ?? Schedule::create(['name' => 'Comercial'] + ScheduleService::presets()['office']);
        $person = Person::forUser($user);
        $person->setDocument(['529.982.247-25', '111.444.777-35', '390.533.447-05'][$this->cpf++]);
        $person->save();
        app(PeopleService::class)->saveModules($person, ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $workplace->getKey(), 'schedule_id' => $schedule->getKey(),
        ]]);
        app(AccessManager::class)->forget();

        return Employee::query()->where('person_id', $person->getKey())->sole();
    }

    protected function drives(User $user, Vehicle $vehicle): void
    {
        $driver = Driver::create(['name' => $user->name]);
        $driver->forceFill(['user_id' => $user->getKey()])->save();
        $driver->vehicles()->attach($vehicle);
        app(AccessManager::class)->forget();
    }

    protected function vehicle(int $km = 20000): Vehicle
    {
        return app(VehicleService::class)->register(['plate' => 'AUR'.random_int(1000, 9999), 'brand' => 'Fiat', 'model' => 'Strada', 'type' => 'pickup', 'fuel_type' => 'flex'], $km, $this->owner);
    }

    protected function setUpField(): void
    {
        FieldSetting::current()->update(['business' => 'isp', 'configured_at' => now()]);
        app(FieldTemplates::class)->apply('isp');
    }

    protected function order(User $technician, string $at): WorkOrder
    {
        $customer = Customer::create(['name' => 'Cliente '.Str::random(4)]);
        $customer->sites()->create(['street' => 'Rua A', 'number' => '1', 'city' => 'Belo Horizonte', 'latitude' => -19.92, 'longitude' => -43.94, 'is_main' => true]);

        return app(WorkOrderService::class)->open([
            'customer_id' => $customer->getKey(),
            'service_type_id' => ServiceType::query()->where('name', 'Visita técnica')->sole()->getKey(),
            'technician_id' => $technician->getKey(),
            'scheduled_at' => $at,
        ], $this->owner);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(WorkOrder $order, User $technician, string $type, array $data = []): void
    {
        app(OrderExecutionService::class)->apply($order->fresh(), $technician, ['id' => (string) Str::uuid(), 'type' => $type, 'taken_at' => now()->getTimestampMs(), 'data' => $data]);
    }

    /**
     * @return array<int, string>
     */
    protected function sortedKeys(array $connections): array
    {
        $keys = array_keys($connections);
        sort($keys);

        return $keys;
    }

    public function test_a_company_with_one_system_has_no_connections_and_no_window(): void
    {
        $this->company(['campo' => []]);

        $this->assertSame([], app(ConnectionCatalog::class)->active());
        $this->get($this->company->url('/'))->assertOk()->assertDontSee('data-connections-modal', false);
    }

    public function test_the_catalog_follows_the_systems_of_the_company(): void
    {
        $this->company(['campo' => [], 'ponto' => []]);
        $catalog = app(ConnectionCatalog::class);

        $this->assertSame(['documents_training', 'epi_field', 'one_person', 'punch_reminder', 'utilization'], $this->sortedKeys($catalog->active()));

        // With the Frota, four more would start working by themselves.
        $potential = $catalog->potential();
        $this->assertSame(['frota'], array_keys($potential));
        $this->assertSame(['documents_license', 'field_km_to_fleet', 'inspection_after_punch', 'travel_cost'], $this->sortedKeys($potential['frota']['connections']));

        // Every connection has its texts in the three languages.
        foreach (['pt_BR', 'en', 'es'] as $locale) {
            app()->setLocale($locale);

            foreach ($catalog->all() as $connection) {
                $this->assertNotSame('connections.items.'.$connection->key.'.title', $connection->title());
                $this->assertNotSame('connections.items.'.$connection->key.'.body', $connection->body());
            }
        }
    }

    public function test_the_owner_sees_the_window_with_every_connection(): void
    {
        $this->company();

        $this->get($this->company->url('/'))->assertOk()
            ->assertSee('data-connections-modal', false)
            ->assertSee(__('connections.window.title'))
            ->assertSee(__('connections.window.forever'))
            ->assertSee(__('connections.items.field_km_to_fleet.title'))
            ->assertSee(__('connections.items.punch_reminder.title'))
            ->assertSee(__('connections.items.inspection_after_punch.title'));

        $this->assertCount(9, app(ConnectionCatalog::class)->pending($this->owner));
    }

    public function test_each_person_sees_only_what_matters_to_them(): void
    {
        $this->company();
        $catalog = app(ConnectionCatalog::class);
        $vehicle = $this->vehicle();

        // A technician who clocks in and drives a company vehicle: only what changes something on their phone.
        $lucas = $this->member('Lucas Técnico', ['campo' => 'technician']);
        $this->clocksIn($lucas);
        $this->drives($lucas, $vehicle);
        $this->assertSame(['field_km_to_fleet', 'inspection_after_punch', 'punch_reminder'], $this->sortedKeys($catalog->relevant($lucas)));

        // A technician who neither clocks in nor drives: nothing.
        $ana = $this->member('Ana Técnica', ['campo' => 'technician']);
        $this->assertSame([], $catalog->relevant($ana));

        // HR (time clock manager only): the documents it registers reach the other systems; the people register.
        $rh = $this->member('Rita RH', ['ponto' => 'manager']);
        $this->assertSame(['documents_license', 'documents_training', 'epi_field', 'one_person', 'punch_reminder'], $this->sortedKeys($catalog->relevant($rh)));

        // Service desk: only the safety trainings when scheduling.
        $desk = $this->member('Davi Atendimento', ['campo' => 'dispatcher']);
        $this->assertSame(['documents_training', 'epi_field'], $this->sortedKeys($catalog->relevant($desk)));

        // Someone without any role: nothing, and no window.
        $this->assertSame([], $catalog->relevant($this->member('Sem Acesso')));
        $this->actingAs($ana);
        $this->get($this->company->url('/'))->assertOk()->assertDontSee('data-connections-modal', false);
    }

    public function test_do_not_show_again_is_per_person_and_per_connection(): void
    {
        $this->company();
        $admin = $this->member('Paula Admin', ['campo' => 'manager', 'ponto' => 'manager', 'frota' => 'manager']);

        $this->postJson(route('connections.dismiss'), ['keys' => ['punch_reminder', 'utilization', 'not_a_connection'], 'forever' => true])
            ->assertOk()->assertJson(['status' => 'ok']);

        $this->assertSame(['punch_reminder', 'utilization'], ConnectionDismissal::query()->where('user_id', $this->owner->getKey())->orderBy('connection')->pluck('connection')->all());
        $pending = app(ConnectionCatalog::class)->pending($this->owner);
        $this->assertArrayNotHasKey('punch_reminder', $pending);
        $this->assertArrayHasKey('travel_cost', $pending);

        // Hidden for the rest of the day; tomorrow the others come back, never the dismissed ones.
        $this->get($this->company->url('/'))->assertOk()->assertDontSee('data-connections-modal', false);
        $this->travelTo(now()->addDay());
        $this->get($this->company->url('/'))->assertOk()
            ->assertSee('data-connections-modal', false)
            ->assertSee('data-connection="travel_cost"', false)
            ->assertDontSee('data-connection="punch_reminder"', false);

        // Another person of the company still sees all of them.
        $this->assertArrayHasKey('punch_reminder', app(ConnectionCatalog::class)->pending($admin));
    }

    public function test_got_it_without_the_box_brings_the_window_back_the_next_day(): void
    {
        $this->company();

        $this->postJson(route('connections.dismiss'), ['keys' => ['punch_reminder'], 'forever' => false])->assertOk();

        $this->assertSame(0, ConnectionDismissal::query()->count());
        $this->get($this->company->url('/'))->assertOk()->assertDontSee('data-connections-modal', false);

        $this->travelTo(now()->addDay());
        $this->get($this->company->url('/'))->assertOk()->assertSee('data-connection="punch_reminder"', false);
    }

    public function test_a_person_cannot_dismiss_what_does_not_concern_them(): void
    {
        $this->company();
        $lucas = $this->member('Lucas Técnico', ['campo' => 'technician']);
        $this->clocksIn($lucas);
        $this->actingAs($lucas);

        $this->postJson(route('connections.dismiss'), ['keys' => ['utilization', 'punch_reminder'], 'forever' => true])->assertOk();

        $this->assertSame(['punch_reminder'], ConnectionDismissal::query()->pluck('connection')->all());
    }

    public function test_the_same_login_in_another_company_still_sees_the_window(): void
    {
        $this->company();
        app(ConnectionCatalog::class)->dismissAllFor([$this->owner]);
        $this->assertSame([], app(ConnectionCatalog::class)->pending($this->owner));

        $other = $this->tenant('outra', self::ALL);
        $this->owner->tenants()->attach($other, ['role' => 'owner']);
        $this->owner->forgetRoles();
        $this->useTenant($other);
        app(AccessManager::class)->forget();

        // Dismissals belong to the company where they were made.
        $this->assertSame(0, ConnectionDismissal::query()->count());
        $this->assertCount(9, app(ConnectionCatalog::class)->pending($this->owner));
    }

    public function test_a_new_connection_shows_up_even_for_who_dismissed_the_old_ones(): void
    {
        $this->company();
        app(ConnectionCatalog::class)->dismissAllFor([$this->owner]);
        $this->assertSame([], app(ConnectionCatalog::class)->pending($this->owner));

        // A later version brings a new connection.
        $this->app->instance(ConnectionCatalog::class, new class(app(ModuleRegistry::class), app(AccessManager::class), app(TenantContext::class)) extends ConnectionCatalog
        {
            public function all(): array
            {
                return parent::all() + ['future_feature' => new Connection('future_feature', ['campo', 'frota'], 'heroicon-o-star', fn (User $user): bool => true, fn (): ?string => null)];
            }
        });

        $this->assertSame(['future_feature'], array_keys(app(ConnectionCatalog::class)->pending($this->owner)));
        $this->get($this->company->url('/'))->assertOk()->assertSee('data-connection="future_feature"', false);
    }

    public function test_the_systems_together_page(): void
    {
        $this->company(['campo' => [], 'ponto' => []]);

        $this->get(ConnectionsPage::getUrl())->assertOk()
            ->assertSee(__('connections.page.title'))
            ->assertSee(__('connections.items.utilization.title'))
            ->assertSee(__('connections.page.with', ['system' => 'Frota']))
            ->assertSee(__('connections.items.travel_cost.title'))
            ->assertSee(config('kiban.core.url').'/conta/sistemas', false)
            ->assertDontSee('data-connections-modal', false);

        // A technician who clocks in opens it (from the window), without "what you would have" and not in the menu.
        $lucas = $this->member('Lucas Técnico', ['campo' => 'technician']);
        $this->clocksIn($lucas);
        $this->actingAs($lucas);
        $this->get(ConnectionsPage::getUrl())->assertOk()
            ->assertSee(__('connections.items.punch_reminder.title'))
            ->assertDontSee(__('connections.items.utilization.title'))
            ->assertDontSee(__('connections.page.with', ['system' => 'Frota']));
        $this->assertFalse(ConnectionsPage::shouldRegisterNavigation());

        // Nothing that matters to this person: no page.
        $this->actingAs($this->member('Sem Acesso'));
        $this->get(ConnectionsPage::getUrl())->assertForbidden();
    }

    public function test_the_time_clock_reminder_at_the_first_and_last_order(): void
    {
        $this->company();
        $this->setUpField();
        $lucas = $this->member('Lucas Técnico', ['campo' => 'technician']);
        $employee = $this->clocksIn($lucas);
        $first = $this->order($lucas, '2026-11-03 13:00:00');
        $second = $this->order($lucas, '2026-11-03 15:00:00');
        $bridge = app(FieldTimeClock::class);

        $reminder = $bridge->reminder($lucas, $first);
        $this->assertSame([0, true, false], [$reminder['punches'], $reminder['first'], $reminder['last']]);
        $this->assertSame(PunchPage::getUrl(), $reminder['url']);

        // Arriving never records a punch: clocking in stays the employee's act (Portaria 671).
        $this->send($first, $lucas, 'check_in', ['latitude' => -19.92, 'longitude' => -43.94]);
        $this->assertSame(0, Punch::query()->count());

        WorkOrder::query()->whereKey($first->getKey())->update(['status' => OrderStatus::Done->value, 'finished_at' => now()]);
        app(PunchService::class)->punch($employee, ['photo_path' => 'x.jpg']);

        $reminder = $bridge->reminder($lucas, $second->fresh());
        $this->assertSame([1, false, true], [$reminder['punches'], $reminder['first'], $reminder['last']]);

        // On the technician's screen, the reminder comes with the order (it also works without signal).
        $this->actingAs($lucas);
        $page = Livewire::test(ExecuteOrderPage::class, ['order' => $second->getKey()]);
        $this->assertSame(1, $page->instance()->payload()['clock']['punches']);

        // Who does not clock in gets no reminder.
        $ana = $this->member('Ana Técnica', ['campo' => 'technician']);
        $this->assertNull($bridge->reminder($ana, $this->order($ana, '2026-11-03 16:00:00')));
    }

    public function test_no_time_clock_reminder_without_the_time_clock(): void
    {
        $this->company(['campo' => []]);
        $this->setUpField();
        $lucas = $this->member('Lucas Técnico', ['campo' => 'technician']);

        $this->assertNull(app(FieldTimeClock::class)->reminder($lucas, $this->order($lucas, '2026-11-03 13:00:00')));
        $this->assertFalse(app(FieldTimeClock::class)->enabled());
        $this->assertSame([], app(FieldTimeClock::class)->utilization(CarbonImmutable::parse('2026-11-01')));
    }

    public function test_the_utilization_of_each_technician(): void
    {
        $this->company(['campo' => [], 'ponto' => []]);
        $this->setUpField();
        $lucas = $this->member('Lucas Técnico', ['campo' => 'technician']);
        $employee = $this->clocksIn($lucas);
        $ana = $this->member('Ana Técnica', ['campo' => 'technician']);
        $this->clocksIn($ana);

        // Lucas: 08:00 to 12:00 and 13:00 to 18:00 on the time clock (9 h), 3 h at two orders.
        foreach (['08:00', '12:00', '13:00', '18:00'] as $time) {
            $this->travelTo(CarbonImmutable::parse('2026-11-03 '.$time, self::TZ));
            app(PunchService::class)->punch($employee, ['photo_path' => 'x.jpg']);
        }

        $first = $this->order($lucas, '2026-11-03 12:00:00');
        $second = $this->order($lucas, '2026-11-03 17:00:00');
        WorkOrder::query()->whereKey($first->getKey())->update(['status' => OrderStatus::Done->value, 'checked_in_at' => '2026-11-03 12:00:00', 'finished_at' => '2026-11-03 14:00:00']);
        WorkOrder::query()->whereKey($second->getKey())->update(['status' => OrderStatus::Done->value, 'checked_in_at' => '2026-11-03 17:00:00', 'finished_at' => '2026-11-03 18:00:00']);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 22:00', self::TZ));

        $utilization = app(FieldTimeClock::class)->utilization(CarbonImmutable::parse('2026-11-01'));
        $this->assertSame(['worked' => 540, 'service' => 180, 'percent' => 33], $utilization[$lucas->getKey()]);
        // Ana clocked in on no day: no line.
        $this->assertArrayNotHasKey($ana->getKey(), $utilization);

        // Managers see the column on the dashboard; the service desk does not.
        Livewire::test(FieldTechniciansWidget::class, ['pageFilters' => ['month' => '2026-11']])
            ->assertSee(__('connections.dashboard.utilization'))
            ->assertSee('33%')
            ->assertSee(__('connections.dashboard.utilization_help'));

        $this->actingAs($this->member('Davi Atendimento', ['campo' => 'dispatcher']));
        Livewire::test(FieldTechniciansWidget::class, ['pageFilters' => ['month' => '2026-11']])
            ->assertDontSee(__('connections.dashboard.utilization'));
    }

    public function test_the_estimated_travel_cost(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00'));
        $this->company(['campo' => [], 'frota' => []]);
        $this->setUpField();
        $vehicle = $this->vehicle(20000);
        Expense::create(['vehicle_id' => $vehicle->getKey(), 'category' => 'tax', 'description' => 'IPVA', 'amount_cents' => 13000, 'spent_on' => '2026-11-02']);

        $lucas = $this->member('Lucas Técnico', ['campo' => 'technician']);
        $this->drives($lucas, $vehicle);

        // The morning reading (e.g. the inspection), then the arrival at the customer with the km.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 11:00:00'));
        app(OdometerService::class)->record($vehicle->fresh(), 20100, OdometerSource::Manual, null, $this->owner);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:30:00'));
        $order = $this->order($lucas, '2026-11-03 12:30:00');
        $this->send($order, $lucas, 'check_in', ['km' => 20130, 'vehicle_id' => $vehicle->getKey()]);
        WorkOrder::query()->whereKey($order->getKey())->update(['status' => OrderStatus::Done->value, 'finished_at' => now()->addHour()]);

        // 130 km driven and R$ 130,00 of costs: R$ 1,00 per km; 30 km to this customer.
        $this->assertSame(['km' => 30, 'cost_cents' => 3000, 'per_km_cents' => 100], app(FieldFleet::class)->estimate($order->fresh()));
        $this->assertSame([$lucas->getKey() => ['km' => 30, 'cost_cents' => 3000, 'orders' => 1]], app(FieldFleet::class)->byTechnician(collect([$order->fresh()])));

        // The manager sees it on the order and on the dashboard; the service desk does not.
        $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk()->assertSee(__('connections.order.travel'));
        Livewire::test(FieldTechniciansWidget::class, ['pageFilters' => ['month' => '2026-11']])->assertSee(__('connections.dashboard.travel'));

        $this->actingAs($this->member('Davi Atendimento', ['campo' => 'dispatcher']));
        $this->get(OrderResource::getUrl('view', ['record' => $order]))->assertOk()->assertDontSee(__('connections.order.travel'));

        // Without costs in the Frota there is no cost per km: nothing is shown.
        Expense::query()->delete();
        $this->assertNull((new FieldFleet(app(TenantContext::class), app(FleetReports::class)))->estimate($order->fresh()));
    }

    public function test_no_travel_cost_without_the_reading_of_the_same_day(): void
    {
        $this->company(['campo' => [], 'frota' => []]);
        $this->setUpField();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00:00'));
        $vehicle = $this->vehicle(20000);
        Expense::create(['vehicle_id' => $vehicle->getKey(), 'category' => 'tax', 'description' => 'IPVA', 'amount_cents' => 13000, 'spent_on' => '2026-11-02']);
        $lucas = $this->member('Lucas Técnico', ['campo' => 'technician']);
        $this->drives($lucas, $vehicle);

        // The only earlier reading is from yesterday: the trip to this customer is unknown.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 13:00:00'));
        $order = $this->order($lucas, '2026-11-03 13:00:00');
        $this->send($order, $lucas, 'check_in', ['km' => 20130, 'vehicle_id' => $vehicle->getKey()]);

        $this->assertNull(app(FieldFleet::class)->estimate($order->fresh()));
    }

    public function test_the_inspection_suggested_after_clocking_in(): void
    {
        $this->company(['frota' => [], 'ponto' => []]);
        $vehicle = $this->vehicle();
        $joao = $this->member('João Motorista');
        $employee = $this->clocksIn($joao);
        $this->drives($joao, $vehicle);
        $bridge = app(FleetTimeClock::class);

        // Not at work yet: no suggestion.
        $this->assertCount(0, $bridge->inspectionsAfterPunch($joao, 0));

        app(PunchService::class)->punch($employee, ['photo_path' => 'x.jpg']);
        $this->actingAs($joao);
        $this->get(PunchPage::getUrl())->assertOk()
            ->assertSee('data-inspection-suggestion', false)
            ->assertSee('/frota/vistorias/fazer?vehicle='.$vehicle->getKey(), false);

        // Inspection done today: the suggestion is gone.
        Inspection::create(['vehicle_id' => $vehicle->getKey(), 'performed_at' => now(), 'odometer' => 20000, 'photos' => [], 'answers' => []]);
        $this->get(PunchPage::getUrl())->assertOk()->assertDontSee('data-inspection-suggestion', false);

        // A company that does not ask for inspections never sees it.
        Inspection::query()->delete();
        FleetSetting::current()->update(['inspection_frequency' => InspectionFrequency::Free]);
        $this->assertCount(0, $bridge->inspectionsAfterPunch($joao, 1));
    }

    public function test_no_inspection_suggestion_without_the_fleet(): void
    {
        $this->company(['ponto' => []]);
        $joao = $this->member('João Motorista');
        $employee = $this->clocksIn($joao);
        app(PunchService::class)->punch($employee, ['photo_path' => 'x.jpg']);

        $this->assertCount(0, app(FleetTimeClock::class)->inspectionsAfterPunch($joao, 1));
        $this->actingAs($joao);
        $this->get(PunchPage::getUrl())->assertOk()->assertDontSee('data-inspection-suggestion', false);
    }
}
