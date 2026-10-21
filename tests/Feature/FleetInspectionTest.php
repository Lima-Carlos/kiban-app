<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Fleet\Enums\FuelUpFlag;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Enums\RequestStatus;
use App\Modules\Fleet\Enums\VehicleEventType;
use App\Modules\Fleet\Enums\VehicleStatus;
use App\Modules\Fleet\Filament\Checklist\Pages\ManageChecklistItems;
use App\Modules\Fleet\Filament\Dashboard\InspectionStatusWidget;
use App\Modules\Fleet\Filament\Inspections\Pages\DoInspection;
use App\Modules\Fleet\Models\ChecklistItem;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\Inspection;
use App\Modules\Fleet\Models\ServiceRequest;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\FuelService;
use App\Modules\Fleet\Services\InspectionService;
use App\Modules\Fleet\Services\MaintenanceService;
use App\Modules\Fleet\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Frota, part 4: inspections with the company's checklist, photos, problems that become maintenance
 * requests, critical items that stop the vehicle, and the reminder (never a block) of how often to inspect.
 */
class FleetInspectionTest extends TestCase
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
        $this->car = app(VehicleService::class)->register(['plate' => 'CAR1A11', 'brand' => 'Fiat', 'model' => 'Strada', 'type' => 'pickup', 'fuel_type' => 'flex'], 10000, $this->manager);
    }

    protected function service(): InspectionService
    {
        return app(InspectionService::class);
    }

    /** @param  array<int, Vehicle>  $vehicles */
    protected function driver(array $vehicles = []): User
    {
        $user = User::factory()->memberOf($this->company, 'member')->create();
        Driver::create(['name' => $user->name, 'email' => $user->email])->vehicles()->attach(collect($vehicles)->map->getKey()->all());

        return $user;
    }

    protected function item(string $key): ChecklistItem
    {
        $this->service()->ensureDefaultItems();

        return ChecklistItem::query()->where('key', $key)->sole();
    }

    /**
     * Every active item OK, except the ones given (key => note).
     *
     * @param  array<string, string>  $problems
     * @return array<string, mixed>
     */
    protected function data(int $odometer, array $problems = []): array
    {
        $answers = [];

        foreach ($this->service()->items() as $item) {
            $answers[$item->id] = isset($problems[$item->key]) ? ['ok' => false, 'note' => $problems[$item->key]] : ['ok' => true];
        }

        return [
            'odometer' => $odometer,
            'photos' => array_combine(Inspection::PHOTOS, array_map(fn (string $photo): string => "tenants/1/fleet/inspections/{$photo}.jpg", Inspection::PHOTOS)),
            'answers' => $answers,
        ];
    }

    public function test_driver_does_the_inspection_on_the_phone_and_problems_become_requests(): void
    {
        $driver = $this->driver([$this->car]);
        $this->actingAs($driver);

        $this->get($this->company->url('/'))->assertOk()->assertSee(__('fleet.inspections.do_pending'));

        $answers = [];
        foreach ($this->service()->items() as $item) {
            $answers[$item->id] = ['ok' => $item->key === 'bodywork' ? 0 : 1];
        }
        $photos = collect(Inspection::PHOTOS)->mapWithKeys(fn (string $photo): array => [$photo => UploadedFile::fake()->image("{$photo}.jpg")])->all();

        // The vehicle is already selected (their only one). A problem without a note is refused.
        Livewire::test(DoInspection::class)
            ->assertSet('data.vehicle_id', $this->car->id)
            ->fillForm(['odometer' => 10350, 'answers' => $answers, 'photos' => $photos])
            ->call('finish')
            ->assertHasFormErrors(["answers.{$this->item('bodywork')->id}.note"]);

        $answers[$this->item('bodywork')->id]['note'] = 'Risco na porta';
        Livewire::test(DoInspection::class)
            ->fillForm(['odometer' => 10350, 'answers' => $answers, 'photos' => $photos])
            ->call('finish')
            ->assertHasNoFormErrors()
            ->assertNotified(__('fleet.inspections.saved'));

        $inspection = Inspection::sole();
        $this->assertSame(1, $inspection->problems);
        $this->assertFalse($inspection->has_critical);
        $this->assertSame($driver->id, $inspection->performed_by);
        $this->assertCount(9, $inspection->answers);
        $this->assertStringStartsWith('tenants/'.$this->company->id.'/fleet/inspections/', $inspection->photos['front']);

        // The odometer goes to the vehicle's history.
        $this->assertSame(10350, $this->car->fresh()->odometer);
        $this->assertSame(OdometerSource::Inspection, $this->car->odometerReadings()->latest('id')->first()->source);

        // The problem became a maintenance request (same path as the driver's button).
        $request = ServiceRequest::sole();
        $this->assertSame($inspection->id, $request->inspection_id);
        $this->assertSame(RequestStatus::Open, $request->status);
        $this->assertStringContainsString('Risco na porta', $request->description);
        $this->assertSame(VehicleStatus::Active, $this->car->fresh()->status); // not a critical item

        // Done for today: no longer pending; history cannot be changed.
        $this->get($this->company->url('/'))->assertDontSee(__('fleet.inspections.do_pending'));
        $this->get($this->company->url('/frota/vistorias/'.$inspection->id))->assertOk()->assertSee('Risco na porta');
        $this->assertFalse($driver->can('update', $inspection));
        $this->assertFalse($this->manager->can('delete', $inspection));
    }

    public function test_a_critical_problem_stops_the_vehicle_until_maintenance(): void
    {
        $inspection = $this->service()->record($this->car, $this->data(10100, ['brakes' => 'Pedal baixo']), $this->manager);

        $this->assertTrue($inspection->has_critical);
        $car = $this->car->fresh();
        $this->assertSame(VehicleStatus::Maintenance, $car->status);
        $event = $car->events()->latest('id')->first();
        $this->assertSame(VehicleEventType::StoppedByInspection, $event->type);
        $this->assertStringContainsString(__('fleet.checklist.brakes'), $event->reason);

        // A vehicle in maintenance is not asked for an inspection.
        $this->assertFalse($this->service()->isDue($car));

        // The maintenance is recorded: the vehicle is active again.
        app(MaintenanceService::class)->recordMaintenance($car, ['type' => 'corrective', 'services' => ['brakes'], 'performed_on' => now(), 'odometer' => 10120], $this->manager);
        $this->assertSame(VehicleStatus::Active, $this->car->fresh()->status);
    }

    public function test_photos_and_every_item_are_required(): void
    {
        $data = $this->data(10100);
        unset($data['photos']['dashboard']);
        $this->assertThrows(fn () => $this->service()->record($this->car, $data, $this->manager), FleetException::class, __('fleet.errors.inspection_photos'));

        $data = $this->data(10100);
        unset($data['answers'][$this->item('lights')->id]);
        $this->assertThrows(fn () => $this->service()->record($this->car, $data, $this->manager), FleetException::class);

        $data = $this->data(10100, ['tires' => '  ']);
        $this->assertThrows(fn () => $this->service()->record($this->car, $data, $this->manager), FleetException::class);

        // The odometer never goes back.
        $this->assertThrows(fn () => $this->service()->record($this->car, $this->data(9000), $this->manager), FleetException::class);
        $this->assertSame(0, Inspection::count());
    }

    public function test_how_often_and_the_reminder_when_fueling(): void
    {
        $station = FuelStation::create(['name' => 'Posto Centro']);
        $fuel = app(FuelService::class);

        // Daily (default): due until today's inspection is done; fueling is not blocked, only flagged.
        $this->assertTrue($this->service()->isDue($this->car));
        $first = $fuel->authorize($this->car, $station, 10050, $this->manager);
        $this->assertContains(FuelUpFlag::NoInspection, $first->flagList());
        $fuel->cancel($first, 'teste', $this->manager);

        $this->service()->record($this->car, $this->data(10100), $this->manager);
        $this->assertFalse($this->service()->isDue($this->car));
        $second = $fuel->authorize($this->car, $station, 10150, $this->manager);
        $this->assertNotContains(FuelUpFlag::NoInspection, $second->flagList());
        $fuel->cancel($second, 'teste', $this->manager);

        $this->travel(1)->days();
        $this->assertTrue($this->service()->isDue($this->car));
        $this->assertCount(1, $this->service()->dueVehicles());

        // Monthly: yesterday's inspection still counts (same month, unless it just turned).
        FleetSetting::current()->update(['inspection_frequency' => 'monthly']);
        $this->assertSame(now()->day !== 1, ! $this->service()->isDue($this->car));

        // Free: never asked.
        FleetSetting::current()->update(['inspection_frequency' => 'free']);
        $this->assertFalse($this->service()->isDue($this->car));
        $this->assertCount(0, $this->service()->dueVehicles());
    }

    public function test_the_manager_shapes_the_checklist(): void
    {
        $this->actingAs($this->manager);
        Livewire::test(ManageChecklistItems::class)
            ->assertSee(__('fleet.checklist.tires'))
            ->callAction('create', ['label' => 'Tacógrafo', 'is_critical' => true, 'is_active' => true])
            ->assertHasNoActionErrors();

        $own = ChecklistItem::query()->where('label', 'Tacógrafo')->sole();
        $this->assertTrue($own->is_critical);
        $this->item('cleanliness')->update(['is_active' => false]);

        // Ready items are turned off, not deleted; the company's own items can be deleted.
        $this->assertFalse($this->manager->can('delete', $this->item('tires')));
        $this->assertTrue($this->manager->can('delete', $own));

        $labels = $this->service()->items()->map->name()->all();
        $this->assertContains('Tacógrafo', $labels);
        $this->assertNotContains(__('fleet.checklist.cleanliness'), $labels);

        // The answers keep the names as they were (history), even if the item is renamed later.
        $inspection = $this->service()->record($this->car, $this->data(10100), $this->manager);
        $this->item('tires')->update(['label' => 'Pneus novos']);
        $this->assertContains(__('fleet.checklist.tires'), collect($inspection->fresh()->answers)->pluck('label')->all());
        $this->assertCount(9, $inspection->answers); // 9 ready - cleanliness + tacógrafo
    }

    public function test_who_does_and_who_sees(): void
    {
        $driver = $this->driver([$this->car]);
        $other = app(VehicleService::class)->register(['plate' => 'OTH2B22', 'brand' => 'Ford', 'model' => 'Ka', 'type' => 'car', 'fuel_type' => 'flex'], 5000, $this->manager);
        $this->service()->record($other, $this->data(5100), $this->manager);

        // A driver only inspects their vehicles and only sees their inspections.
        $this->assertThrows(fn () => $this->service()->record($other, $this->data(5200), $driver), FleetException::class);
        $this->actingAs($driver);
        $this->get($this->company->url('/frota/vistorias'))->assertOk()->assertDontSee('OTH2B22');
        $this->get($this->company->url('/frota/vistorias/fazer'))->assertOk();
        $this->get($this->company->url('/frota/vistorias/'.Inspection::sole()->id))->assertNotFound();
        $this->get($this->company->url('/frota/itens-da-vistoria'))->assertForbidden();

        // Viewer: looks, does not inspect.
        $viewer = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($viewer, 'frota', 'viewer');
        $this->actingAs($viewer);
        $this->get($this->company->url('/frota/vistorias'))->assertOk()->assertSee('OTH2B22');
        $this->get($this->company->url('/frota/vistorias/fazer'))->assertForbidden();

        // Manager: dashboard with the inspections of today, the checklist and the settings.
        $this->actingAs($this->manager);
        $this->get($this->company->url('/frota/itens-da-vistoria'))->assertOk();
        $this->get($this->company->url('/frota/configuracoes'))->assertOk()->assertSee(__('fleet.settings.inspection_frequency'));
        $this->get($this->company->url('/frota/veiculos/'.$this->car->id))->assertOk();
        $this->service()->record($other, $this->data(5300, ['bodywork' => 'Para-choque solto']), $this->manager);
        Livewire::test(InspectionStatusWidget::class)
            ->assertSee(__('fleet.inspections.done_in_period.daily', ['done' => 1, 'total' => 2]))
            ->assertSee('CAR1A11')
            ->assertSee('Para-choque solto');
    }

    public function test_companies_never_mix_inspections(): void
    {
        $this->service()->record($this->car, $this->data(10100), $this->manager);

        $other = $this->tenant('logistica', ['frota' => []]);
        $this->signIn($other);
        $this->assertSame(0, Inspection::count());
        $this->assertSame(0, ChecklistItem::count());
        $this->assertCount(9, $this->service()->items()); // each company gets its own ready items
    }
}
