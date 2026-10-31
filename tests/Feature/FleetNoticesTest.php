<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Enums\ServiceKind;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\Inspection;
use App\Modules\Fleet\Models\ServiceRequest;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\VehicleDocument;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetNotices;
use App\Modules\Fleet\Services\FuelService;
use App\Modules\Fleet\Services\InspectionService;
use App\Modules\Fleet\Services\MaintenanceService;
use App\Modules\Fleet\Services\VehicleService;
use Carbon\CarbonImmutable;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Core Frota, part 5: warnings in the bell (never by e-mail), each one to the right people, in their
 * language, only once, only in their company; and the "Attention" box for what is urgent and still open.
 */
class FleetNoticesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $manager;

    protected User $mechanic;

    protected User $driver;

    protected Vehicle $car;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('transportes', ['frota' => []]);
        $this->manager = $this->signIn($this->company);
        $this->car = app(VehicleService::class)->register(['plate' => 'CAR1A11', 'brand' => 'Fiat', 'model' => 'Strada', 'type' => 'pickup', 'fuel_type' => 'flex', 'tank_capacity' => 55], 10000, $this->manager);

        $this->mechanic = User::factory()->memberOf($this->company)->create();
        app(AccessManager::class)->set($this->mechanic, 'frota', 'mechanic');

        $this->driver = User::factory()->memberOf($this->company)->create();
        Driver::create(['name' => $this->driver->name, 'email' => $this->driver->email])->vehicles()->attach($this->car);
        app(FleetAccess::class)->driverFor($this->driver); // links the record to the login
    }

    /** @return array<int, string> titles of the bell of this person (current company) */
    protected function titles(User $user): array
    {
        return $user->notifications()->get()->map(fn (DatabaseNotification $notice): string => $notice->data['title'])->all();
    }

    /** @param  array<string, string>  $problems */
    protected function inspect(User $by, array $problems = [], int $odometer = 10100): Inspection
    {
        $answers = [];
        foreach (app(InspectionService::class)->items() as $item) {
            $answers[$item->id] = isset($problems[$item->key]) ? ['ok' => false, 'note' => $problems[$item->key]] : ['ok' => true];
        }

        return app(InspectionService::class)->record($this->car, [
            'odometer' => $odometer,
            'photos' => array_fill_keys(Inspection::PHOTOS, 'tenants/1/fleet/inspections/x.jpg'),
            'answers' => $answers,
        ], $by);
    }

    public function test_critical_inspection_warns_and_the_attention_box_follows_the_problem(): void
    {
        $this->inspect($this->driver, ['brakes' => 'Pedal baixo']);

        $title = __('fleet.notices.inspection_critical', ['plate' => 'CAR1A11']);
        $this->assertSame([$title], $this->titles($this->manager));
        $this->assertSame([$title], $this->titles($this->mechanic));
        $this->assertSame([], $this->titles($this->driver)); // who did it is not warned
        $this->assertSame($this->company->id, DB::table('notifications')->value('tenant_id'));

        // Attention box: the manager sees the stopped vehicle; the driver is told not to use it.
        $this->actingAs($this->manager);
        $this->get($this->company->url('/'))->assertOk()->assertSee(__('app.notices.attention'))->assertSee(__('fleet.checklist.brakes'));
        $this->actingAs($this->driver);
        $this->get($this->company->url('/'))->assertSee(__('fleet.attention.do_not_use', ['plate' => 'CAR1A11']));

        // The mechanic schedules and finishes it: the driver hears about each step; the box disappears.
        $request = ServiceRequest::sole();
        $appointment = app(MaintenanceService::class)->scheduleRequest($request, ['scheduled_at' => now()->addDay()], $this->mechanic);
        app(MaintenanceService::class)->recordMaintenance($this->car->fresh(), ['type' => 'corrective', 'services' => ['brakes'], 'performed_on' => now(), 'odometer' => 10120], $this->mechanic, $appointment);

        $this->assertEqualsCanonicalizing([
            __('fleet.notices.request_done', ['plate' => 'CAR1A11', 'kind' => __('fleet.enums.service_kind.maintenance')]),
            __('fleet.notices.request_scheduled', ['plate' => 'CAR1A11', 'kind' => __('fleet.enums.service_kind.maintenance')]),
        ], $this->titles($this->driver));

        $this->actingAs($this->manager);
        $this->get($this->company->url('/'))->assertDontSee(__('app.notices.attention'));
        $this->actingAs($this->driver);
        $this->get($this->company->url('/'))->assertDontSee(__('fleet.attention.do_not_use', ['plate' => 'CAR1A11']));
    }

    public function test_requests_reach_the_right_people(): void
    {
        $request = app(MaintenanceService::class)->request($this->car, ServiceKind::Wash, 'Lavar por dentro', $this->driver);
        $title = __('fleet.notices.request_new', ['plate' => 'CAR1A11', 'kind' => __('fleet.enums.service_kind.wash')]);
        $this->assertSame([$title], $this->titles($this->manager));
        $this->assertSame([$title], $this->titles($this->mechanic));

        app(MaintenanceService::class)->rejectRequest($request, 'Já foi lavado ontem', $this->mechanic);
        $notice = $this->driver->notifications()->sole();
        $this->assertSame(__('fleet.notices.request_rejected', ['plate' => 'CAR1A11', 'kind' => __('fleet.enums.service_kind.wash')]), $notice->data['title']);
        $this->assertStringContainsString('Já foi lavado ontem', $notice->data['body']);
        // The button opens the company's own address (only the path, with the company, is kept).
        $this->assertSame('/transportes/frota/solicitacoes', $notice->data['actions'][0]['url']);
    }

    public function test_each_person_reads_in_their_language(): void
    {
        $this->mechanic->forceFill(['app_locale' => 'en'])->save();
        app(MaintenanceService::class)->request($this->car, ServiceKind::Maintenance, 'Barulho', $this->driver);

        $this->assertSame(['New request (Maintenance): CAR1A11'], $this->titles($this->mechanic->fresh()));
        $this->assertSame(['Novo pedido (Manutenção): CAR1A11'], $this->titles($this->manager));
    }

    public function test_the_hourly_routine_warns_only_once(): void
    {
        $this->travelTo(CarbonImmutable::now(FilamentTimezone::get())->setTime(12, 0));
        $this->car->forceFill(['next_oil_change_odometer' => 10300])->save(); // 300 km away: soon
        VehicleDocument::create(['vehicle_id' => $this->car->id, 'type' => 'insurance', 'expires_on' => now()->addDays(5)]);
        app(MaintenanceService::class)->schedule($this->car, ServiceKind::Wash, ['scheduled_at' => now()->addDay(), 'assigned_to' => $this->mechanic->id], $this->manager);

        $notices = app(FleetNotices::class);
        $this->assertSame(4, $notices->scan()); // oil, document, appointment, missing inspection
        $notices->scan();
        $notices->scan();

        $this->assertEqualsCanonicalizing([
            __('fleet.notices.oil_change_soon', ['plate' => 'CAR1A11']),
            __('fleet.notices.document_soon', ['plate' => 'CAR1A11', 'document' => __('fleet.enums.document_type.insurance')]),
            __('fleet.notices.inspections_missing', ['count' => 1]),
        ], $this->titles($this->manager));
        $this->assertSame([__('fleet.notices.oil_change_soon', ['plate' => 'CAR1A11'])], $this->titles($this->driver));
        $this->assertSame([__('fleet.notices.appointment_tomorrow', ['plate' => 'CAR1A11', 'kind' => __('fleet.enums.service_kind.wash')])], $this->titles($this->mechanic));

        // When it becomes overdue, it is a new warning (once again).
        $this->car->forceFill(['next_oil_change_odometer' => 10000])->save();
        $notices->scan();
        $notices->scan();
        $this->assertContains(__('fleet.notices.oil_change_overdue', ['plate' => 'CAR1A11']), $this->titles($this->manager));
        $this->assertCount(2, $this->driver->notifications()->get());

        // Before 11:00 nobody hears about missing inspections; with "free" never.
        DB::table('notifications')->delete();
        DB::table('notice_log')->delete();
        $this->travelTo(CarbonImmutable::now(FilamentTimezone::get())->addDay()->setTime(9, 0));
        $notices->scan();
        $this->assertNotContains(__('fleet.notices.inspections_missing', ['count' => 1]), $this->titles($this->manager));
        FleetSetting::current()->update(['inspection_frequency' => 'free']);
        $this->travelTo(CarbonImmutable::now(FilamentTimezone::get())->setTime(15, 0));
        $notices->scan();
        $this->assertNotContains(__('fleet.notices.inspections_missing', ['count' => 1]), $this->titles($this->manager));
    }

    public function test_fuel_ups_with_warnings_reach_the_manager(): void
    {
        FleetSetting::current()->update(['inspection_frequency' => 'free']);
        $station = FuelStation::create(['name' => 'Posto Centro']);
        $fuel = app(FuelService::class);

        $ok = $fuel->authorize($this->car, $station, 10100, $this->driver);
        $fuel->complete($ok, 40, 24000, true, $this->driver, null, FuelType::Gasoline);
        $this->assertSame([], $this->titles($this->manager));

        // The driver finishes it (who did it is never warned about their own action).
        $this->actingAs($this->driver);
        $over = $fuel->authorize($this->car, $station, 10500, $this->driver);
        $fuel->complete($over, 70, 42000, true, $this->driver, null, FuelType::Diesel);
        $notice = $this->manager->notifications()->sole();
        $this->assertSame(__('fleet.notices.fuel_flagged', ['plate' => 'CAR1A11']), $notice->data['title']);
        $this->assertStringContainsString(__('fleet.enums.fuel_flag.over_capacity'), $notice->data['body']);
        $this->assertStringContainsString(__('fleet.enums.fuel_flag.wrong_fuel'), $notice->data['body']);
    }

    public function test_the_bell_shows_only_the_current_company(): void
    {
        $other = $this->tenant('logistica', ['frota' => []]);
        $other->users()->attach($this->mechanic, ['role' => 'member']);

        app(MaintenanceService::class)->request($this->car, ServiceKind::Maintenance, 'Barulho', $this->driver);
        $this->assertCount(1, $this->mechanic->notifications()->get());

        $this->useTenant($other);
        $this->assertCount(0, $this->mechanic->notifications()->get());

        // The bell of each company.
        $this->actingAs($this->mechanic);
        Livewire::test(DatabaseNotifications::class)->assertDontSee('CAR1A11');
        $this->useTenant($this->company);
        Livewire::test(DatabaseNotifications::class)->assertSee(__('fleet.notices.request_new', ['plate' => 'CAR1A11', 'kind' => __('fleet.enums.service_kind.maintenance')]));
    }

    public function test_old_warnings_are_removed(): void
    {
        app(MaintenanceService::class)->request($this->car, ServiceKind::Maintenance, 'Barulho', $this->driver);
        $this->travel(91)->days();
        app(MaintenanceService::class)->request($this->car, ServiceKind::Wash, 'Lavar', $this->driver);

        $this->artisan('kiban:notices-cleanup')->assertSuccessful();
        $this->assertSame(2, DB::table('notifications')->count()); // the new one, for the manager and the mechanic
    }
}
