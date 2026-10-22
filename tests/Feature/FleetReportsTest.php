<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Filament\FleetReportsPage;
use App\Modules\Fleet\Filament\Inspections\Pages\ViewInspection;
use App\Modules\Fleet\Filament\Vehicles\Pages\ViewVehicle;
use App\Modules\Fleet\Models\Expense;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\Fleet\Models\Inspection;
use App\Modules\Fleet\Models\Maintenance;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\VehicleDocument;
use App\Modules\Fleet\Services\FleetCleanup;
use App\Modules\Fleet\Services\FleetExporter;
use App\Modules\Fleet\Services\FleetNotices;
use App\Modules\Fleet\Services\FuelService;
use App\Modules\Fleet\Services\InspectionService;
use App\Modules\Fleet\Services\MaintenanceService;
use App\Modules\Fleet\Services\VehicleRecord;
use App\Modules\Fleet\Services\VehicleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Kiban Frota, part 6: Excel spreadsheets, the vehicle record in PDF and the removal of old photos
 * and receipts (the data is never deleted).
 */
class FleetReportsTest extends TestCase
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
        FleetSetting::current()->update(['inspection_frequency' => 'free']);
        $this->car = app(VehicleService::class)->register(['plate' => 'CAR1A11', 'brand' => 'Fiat', 'model' => 'Strada', 'type' => 'pickup', 'fuel_type' => 'flex', 'tank_capacity' => 55], 10000, $this->manager);
    }

    /**
     * @return array<string, array<int, array<int, mixed>>> sheet name => rows
     */
    protected function read(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }

        $reader->close();
        unlink($path);

        return $sheets;
    }

    protected function fuel(int $odometer, float $volume, int $cents): FuelUp
    {
        $fuelUp = app(FuelService::class)->authorize($this->car, FuelStation::firstOrCreate(['name' => 'Posto Centro']), $odometer, $this->manager);

        return app(FuelService::class)->complete($fuelUp, $volume, $cents, true, $this->manager, null, FuelType::Gasoline);
    }

    /** @param  array<string, string>  $problems */
    protected function inspect(int $odometer, array $problems = []): Inspection
    {
        $answers = [];
        foreach (app(InspectionService::class)->items() as $item) {
            $answers[$item->id] = isset($problems[$item->key]) ? ['ok' => false, 'note' => $problems[$item->key], 'photo' => "tenants/{$this->company->id}/fleet/inspections/problem.jpg"] : ['ok' => true];
        }

        return app(InspectionService::class)->record($this->car, [
            'odometer' => $odometer,
            'photos' => collect(Inspection::PHOTOS)->mapWithKeys(fn (string $photo): array => [$photo => "tenants/{$this->company->id}/fleet/inspections/{$photo}.jpg"])->all(),
            'answers' => $answers,
        ], $this->manager);
    }

    public function test_spreadsheets_have_the_numbers_of_the_dashboard(): void
    {
        $this->fuel(10000, 40, 24000);
        $this->fuel(10500, 40, 24000);
        app(MaintenanceService::class)->recordMaintenance($this->car, ['type' => 'preventive', 'services' => ['oil_change', 'filters'], 'performed_on' => now(), 'odometer' => 10500, 'parts_cents' => 20000, 'labor_cents' => 5000], $this->manager);
        app(MaintenanceService::class)->recordWash($this->car, ['kind' => 'simple', 'washed_on' => now(), 'cost_cents' => 5000], $this->manager);
        app(MaintenanceService::class)->recordExpense(['vehicle_id' => $this->car->id, 'category' => 'toll', 'amount_cents' => 10000, 'spent_on' => now()], $this->manager);
        app(MaintenanceService::class)->recordExpense(['category' => 'insurance', 'amount_cents' => 100000, 'spent_on' => now()], $this->manager);
        $this->inspect(10550, ['bodywork' => 'Risco na porta']);

        $exporter = app(FleetExporter::class);
        $from = now()->subMonth()->toDateString();
        $to = now()->toDateString();

        $fuel = $this->read($exporter->export('fuel_ups', $from, $to))[__('fleet.fuel.plural_title')];
        $this->assertSame(__('fleet.fields.vehicle'), $fuel[0][1]);
        $this->assertCount(4, $fuel); // header, 2 fuel-ups, total (the reader skips the blank line)
        $this->assertSame('CAR1A11', $fuel[1][1]);
        $this->assertEquals(80.0, end($fuel)[5]); // total under the right columns
        $this->assertEquals(480.0, end($fuel)[7]);

        $costs = $this->read($exporter->export('costs', $from, $to));
        $lines = $costs[__('fleet.reports.sheet.costs')];
        $this->assertCount(1 + 6 + 1, $lines); // header, 2 fuel, maintenance, wash, 2 expenses, total
        $this->assertContains(__('fleet.helpers.whole_fleet'), array_column($lines, 1));
        $this->assertEquals(1880.0, end($lines)[4]);
        $summary = $costs[__('fleet.reports.sheet.summary')];
        $this->assertSame(['CAR1A11', 480.0, 250.0, 50.0, 100.0, 880.0, 500, 1.28], array_map(fn ($value) => is_int($value) && $value !== 500 ? (float) $value : $value, $summary[1]));

        $maintenances = $this->read($exporter->export('maintenances', $from, $to))[__('fleet.maintenances.plural_title')];
        $this->assertStringContainsString(__('fleet.enums.maintenance_item.oil_change'), $maintenances[1][3]);
        $this->assertEquals(250.0, $maintenances[1][9]);

        $inspections = $this->read($exporter->export('inspections', $from, $to))[__('fleet.inspections.plural_title')];
        $this->assertStringContainsString('Risco na porta', $inspections[1][5]);

        // One vehicle only.
        $other = app(VehicleService::class)->register(['plate' => 'OTH2B22', 'brand' => 'Ford', 'model' => 'Ka', 'type' => 'car', 'fuel_type' => 'flex'], 5000, $this->manager);
        $this->assertCount(2, $this->read($exporter->export('fuel_ups', $from, $to, $other->id))[__('fleet.fuel.plural_title')]);
    }

    public function test_reports_page_and_vehicle_record(): void
    {
        $this->fuel(10000, 40, 24000);
        VehicleDocument::create(['vehicle_id' => $this->car->id, 'type' => 'insurance', 'number' => 'AP-123', 'expires_on' => now()->addMonths(3)]);
        $this->inspect(10100);

        $this->actingAs($this->manager);
        Livewire::test(FleetReportsPage::class)
            ->fillForm(['report' => 'costs'])
            ->call('download')
            ->assertFileDownloaded();

        // The PDF: a real PDF with the vehicle's data.
        $html = view('fleet.pdf.vehicle', app(VehicleRecord::class)->data($this->car))->render();
        $this->assertStringContainsString('AP-123', $html);
        $this->assertStringContainsString(__('fleet.inspections.all_ok'), $html);
        $this->assertStringStartsWith('%PDF', app(VehicleRecord::class)->pdf($this->car));
        Livewire::test(ViewVehicle::class, ['record' => $this->car->getRouteKey()])
            ->callAction('record')
            ->assertFileDownloaded('ficha-car1a11.pdf');

        // Mechanics and drivers: no reports (they show costs), no record.
        $mechanic = User::factory()->memberOf($this->company)->create();
        app(AccessManager::class)->set($mechanic, 'frota', 'mechanic');
        $this->actingAs($mechanic);
        $this->get($this->company->url('/frota/relatorios'))->assertForbidden();
        Livewire::test(ViewVehicle::class, ['record' => $this->car->getRouteKey()])->assertActionHidden('record');

        $viewer = User::factory()->memberOf($this->company)->create();
        app(AccessManager::class)->set($viewer, 'frota', 'viewer');
        $this->actingAs($viewer);
        $this->get($this->company->url('/frota/relatorios'))->assertOk()->assertSee(__('fleet.reports.type.costs'));
    }

    public function test_old_photos_and_receipts_are_removed_but_the_data_stays(): void
    {
        Storage::fake('local');
        $disk = Storage::disk('local');
        $folder = "tenants/{$this->company->id}/fleet";

        // 7 months ago: an inspection (photos) and a fuel-up (dashboard photo + receipt).
        $this->travel(-7)->months();
        $inspection = $this->inspect(10100, ['bodywork' => 'Risco']);
        $old = app(FuelService::class)->authorize($this->car, FuelStation::create(['name' => 'Posto']), 10200, $this->manager, "{$folder}/fuel/painel.jpg");
        app(FuelService::class)->complete($old, 40, 24000, true, $this->manager, "{$folder}/fuel/nota.jpg", FuelType::Gasoline);
        $this->travelBack();

        // 6 years ago: a maintenance invoice. Vehicle documents are always kept.
        $maintenance = new Maintenance(['vehicle_id' => $this->car->id, 'type' => 'corrective', 'services' => ['brakes'], 'performed_on' => now()->subYears(6), 'odometer' => 9000, 'invoice_path' => "{$folder}/maintenance/nf.pdf"]);
        $maintenance->forceFill(['total_cents' => 50000])->save();
        $expense = new Expense(['category' => 'tax', 'amount_cents' => 1000, 'spent_on' => now()->subYears(2), 'receipt_path' => "{$folder}/expenses/ipva.pdf"]);
        $expense->forceFill([])->save();
        VehicleDocument::create(['vehicle_id' => $this->car->id, 'type' => 'registration', 'expires_on' => now()->subYears(6), 'file_path' => "{$folder}/documents/crlv.pdf"]);

        foreach ([...array_values($inspection->photos), "{$folder}/inspections/problem.jpg", "{$folder}/fuel/painel.jpg", "{$folder}/fuel/nota.jpg", "{$folder}/maintenance/nf.pdf", "{$folder}/expenses/ipva.pdf", "{$folder}/documents/crlv.pdf"] as $path) {
            $disk->put($path, 'x');
        }

        // The manager is warned first (once a month).
        $this->assertSame(9, app(FleetCleanup::class)->upcoming()); // 5 + 1 inspection photos, the same problem photo in its request, dashboard, invoice
        app(FleetNotices::class)->scan();
        app(FleetNotices::class)->scan();
        $titles = $this->manager->notifications()->get()->pluck('data.title');
        $this->assertSame(1, $titles->filter(fn (string $title): bool => $title === __('fleet.notices.cleanup', ['count' => 9]))->count());

        $this->artisan('kiban:fleet')->assertSuccessful();

        $disk->assertMissing("{$folder}/inspections/front.jpg");
        $disk->assertMissing("{$folder}/inspections/problem.jpg");
        $disk->assertMissing("{$folder}/fuel/painel.jpg");
        $disk->assertMissing("{$folder}/maintenance/nf.pdf");
        $disk->assertExists("{$folder}/fuel/nota.jpg"); // receipt: 5 years
        $disk->assertExists("{$folder}/expenses/ipva.pdf");
        $disk->assertExists("{$folder}/documents/crlv.pdf");

        // The data stays: only the file links are cleared.
        $inspection->refresh();
        $this->assertSame([], $inspection->photos);
        $this->assertNull($inspection->problemAnswers()[0]['photo']);
        $this->assertSame('Risco', $inspection->problemAnswers()[0]['note']);
        $this->assertNull($old->fresh()->odometer_photo_path);
        $this->assertSame(24000, $old->fresh()->total_cents);
        $this->assertNull($maintenance->fresh()->invoice_path);
        $this->assertSame(50000, $maintenance->fresh()->total_cents);
        $this->assertSame(0, app(FleetCleanup::class)->upcoming());

        $this->actingAs($this->manager);
        Livewire::test(ViewInspection::class, ['record' => $inspection->getRouteKey()])->assertSee(__('fleet.cleanup.removed'));
    }

    public function test_cleanup_never_touches_another_company(): void
    {
        Storage::fake('local');
        $this->travel(-7)->months();
        $inspection = $this->inspect(10100);
        $this->travelBack();

        $other = $this->tenant('logistica', ['frota' => []]);
        $this->useTenant($other);
        $this->assertSame(0, app(FleetCleanup::class)->run());

        $this->useTenant($this->company);
        $this->assertNotSame([], $inspection->fresh()->photos);
    }
}
