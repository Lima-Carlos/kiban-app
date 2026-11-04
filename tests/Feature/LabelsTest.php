<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Models\Customer;
use App\Modules\Pmoc\Filament\EquipmentPage;
use App\Modules\Pmoc\Models\Plan;
use App\Modules\Stock\Filament\UnitPage;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Models\Unit;
use App\Modules\Stock\Services\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * QR labels to print (PMOC equipment and serialized units) and the cards their QR codes open (sign-in required).
 */
class LabelsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
    }

    public function test_pmoc_labels_and_the_equipment_card(): void
    {
        $tenant = $this->tenant('climafrio', ['campo' => [], 'pmoc' => []]);
        $this->signIn($tenant);
        $customer = Customer::create(['name' => 'Clínica Sol']);
        $plan = Plan::create(['customer_id' => $customer->getKey(), 'name' => 'PMOC Clínica', 'starts_on' => '2026-11-10', 'next_due_on' => '2026-11-10']);
        $item = $plan->equipment()->create(['tag' => 'EV-01', 'kind' => 'split', 'room' => 'Recepção', 'serial' => 'SN-77']);
        $plan->equipment()->create(['tag' => 'EV-99', 'kind' => 'split', 'is_active' => false]);

        // One label per active unit, each QR pointing to the unit's card.
        $this->get($tenant->url('/etiquetas/pmoc/'.$plan->getKey()))->assertOk()
            ->assertSee('data-labels="1"', false)
            ->assertSee('EV-01')->assertDontSee('EV-99')
            ->assertSee('<svg', false);

        $this->get(EquipmentPage::getUrl(['equipamento' => $item->getKey()]))->assertOk()->assertSee('SN-77')->assertSee('PMOC Clínica');

        // The Campo technician who scans it sees the card; a person outside the Campo does not.
        $technician = User::factory()->memberOf($tenant, 'member')->create();
        $this->coreMember($tenant, $technician);
        app(AccessManager::class)->set($technician, 'campo', FieldRole::Technician->value);
        app(AccessManager::class)->forget();
        $this->actingAs($technician);
        $this->get(EquipmentPage::getUrl(['equipamento' => $item->getKey()]))->assertOk();
        $this->get($tenant->url('/etiquetas/pmoc/'.$plan->getKey()))->assertForbidden();
    }

    public function test_comodato_labels_and_the_unit_card(): void
    {
        $tenant = $this->tenant('provedor', ['estoque' => []]);
        $this->signIn($tenant);
        $warehouse = Location::create(['name' => 'Almoxarifado']);
        $router = Item::create(['name' => 'Roteador', 'serialized' => true]);
        app(StockService::class)->addUnits($router, $warehouse, ['R-1', 'R-2']);
        $unit = Unit::query()->where('serial', 'R-1')->sole();

        $this->get($tenant->url('/etiquetas/comodato?item='.$router->getKey()))->assertOk()->assertSee('data-labels="2"', false)->assertSee('R-2');
        $this->get(UnitPage::getUrl(['equipamento' => $unit->getKey()]))->assertOk()->assertSee('R-1')->assertSee(__('stock.units.statuses.stock'))->assertSee('Almoxarifado');
    }

    public function test_texts_in_the_three_languages(): void
    {
        foreach (['pt_BR', 'en', 'es'] as $locale) {
            app()->setLocale($locale);

            foreach (['app.labels.print', 'app.labels.empty', 'pmoc.labels.print', 'stock.labels.print'] as $key) {
                $this->assertNotSame($key, __($key), $locale.' '.$key);
            }
        }
    }
}
