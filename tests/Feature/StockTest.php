<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\Material;
use App\Modules\Field\Models\OrderMaterial;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Services\WorkOrderService;
use App\Modules\Stock\Filament\Balances\Pages\ManageBalances;
use App\Modules\Stock\Filament\Locations\Pages\ManageLocations;
use App\Modules\Stock\Filament\MyStockPage;
use App\Modules\Stock\Filament\Units\Pages\ManageUnits;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Models\Movement;
use App\Modules\Stock\Models\Unit;
use App\Modules\Stock\Services\StockException;
use App\Modules\Stock\Services\StockFormat;
use App\Modules\Stock\Services\StockService;
use App\Modules\Stock\StockModule;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Core Estoque: entries, transfers, counts, the technician's stock used up by Campo work orders by itself,
 * and equipment tracked by serial number lent to customers (comodato).
 */
class StockTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected StockService $stock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $tenant = $this->tenant('provedor', ['campo' => [], 'estoque' => []]);
        $this->owner = $this->signIn($tenant);
        $this->stock = app(StockService::class);
    }

    public function test_entries_transfers_and_counts(): void
    {
        $warehouse = Location::create(['name' => 'Almoxarifado']);
        $van = Location::create(['name' => 'Carro do Lucas', 'kind' => Location::TECHNICIAN]);
        $cable = Item::create(['name' => 'Cabo drop', 'unit' => 'm', 'min_quantity' => 500]);

        $this->stock->receive($cable, $warehouse, 1000, 'NF 1234');
        $this->stock->transfer($cable, $warehouse, $van, 300);
        $this->assertSame([700.0, 300.0], [$this->stock->balance($cable, $warehouse), $this->stock->balance($cable, $van)]);

        // Never more than there is, never to the same place.
        $this->assertThrows(fn () => $this->stock->transfer($cable, $van, $warehouse, 301), StockException::class);
        $this->assertThrows(fn () => $this->stock->transfer($cable, $van, $van, 1), StockException::class);

        // A count: the difference is an adjustment.
        $adjust = $this->stock->adjust($cable, $van, 280.5, 'Contagem de novembro');
        $this->assertSame(-19.5, $adjust->quantity);
        $this->assertNull($this->stock->adjust($cable, $van, 280.5));

        // 980.5 m in total, above the 500 m minimum; after using 600 m it is low and shows in "Attention".
        $this->assertCount(0, $this->stock->lowStock());
        $this->stock->consume($cable, $warehouse, 600);
        $this->assertSame('Cabo drop', $this->stock->lowStock()->first()['item']->name);
        $this->assertNotEmpty(app(StockModule::class)->attention($this->owner));
        $this->assertSame('1.250,5 m', StockFormat::quantity(1250.5, 'm'));
    }

    public function test_a_work_order_material_leaves_the_technicians_stock(): void
    {
        FieldSetting::current()->update(['business' => 'isp', 'configured_at' => now()]);
        $lucas = User::factory()->memberOf(app(TenantContext::class)->get(), 'member')->create(['name' => 'Lucas']);
        $this->coreMember(app(TenantContext::class)->get(), $lucas);
        app(AccessManager::class)->set($lucas, 'campo', FieldRole::Technician->value);

        // "Create a place for each technician" (Campo).
        Livewire::test(ManageLocations::class)->callAction('technicians')->assertNotified();
        $van = Location::query()->where('user_id', $lucas->getKey())->sole();
        $this->assertSame(__('stock.locations.vehicle_of', ['name' => 'Lucas']), $van->name);

        $connector = Material::create(['name' => 'Conector SC/APC', 'unit' => 'un']);
        $item = Item::create(['name' => 'Conector SC/APC', 'unit' => 'un', 'material_id' => $connector->getKey()]);
        $this->stock->receive($item, $van, 10);

        $type = ServiceType::create(['name' => 'Instalação']);
        $customer = Customer::create(['name' => 'Maria']);
        $order = app(WorkOrderService::class)->open(['customer_id' => $customer->getKey(), 'service_type_id' => $type->getKey(), 'technician_id' => $lucas->getKey(), 'scheduled_at' => '2026-11-03 14:00'], $this->owner);

        // The technician writes down 2 connectors: they leave his stock. Removed from the order, they come back.
        $line = OrderMaterial::create(['order_id' => $order->getKey(), 'material_id' => $connector->getKey(), 'description' => 'Conector', 'unit' => 'un', 'quantity' => 2, 'client_id' => (string) Str::uuid()]);
        $this->assertSame(8.0, $this->stock->balance($item, $van));
        $this->assertSame($order->getKey(), Movement::query()->where('kind', 'use')->sole()->work_order_id);
        $line->delete();
        $this->assertSame(10.0, $this->stock->balance($item, $van));

        // Synced without a session (the phone's queue): the movement still says who used it.
        auth()->forgetGuards();
        $line = OrderMaterial::create(['order_id' => $order->getKey(), 'material_id' => $connector->getKey(), 'description' => 'Conector', 'unit' => 'un', 'quantity' => 1, 'client_id' => (string) Str::uuid()]);
        $this->assertSame($lucas->getKey(), Movement::query()->where('kind', 'use')->latest('id')->first()->user_id);
        $line->delete();

        // The technician sees only his own stock.
        $this->actingAs($lucas);
        app(AccessManager::class)->forget();
        $this->assertTrue(MyStockPage::canAccess());
        $this->assertFalse($lucas->can('viewAny', Item::class));
        Livewire::test(MyStockPage::class)->assertSee('Conector SC/APC')->assertSee('10 un');
    }

    public function test_equipment_lent_to_customers(): void
    {
        $warehouse = Location::create(['name' => 'Almoxarifado']);
        $van = Location::create(['name' => 'Carro do Lucas', 'kind' => Location::TECHNICIAN]);
        $router = Item::create(['name' => 'Roteador Wi-Fi 6', 'serialized' => true]);
        $customer = Customer::create(['name' => 'João Pereira']);

        Livewire::test(ManageUnits::class)
            ->callAction('addUnits', ['item_id' => $router->getKey(), 'location_id' => $warehouse->getKey(), 'serials' => "abc123\nABC124\nabc123\n"])
            ->assertNotified(trans_choice('stock.units.added', 2, ['count' => 2]));
        $this->assertSame(2.0, $this->stock->balance($router, $warehouse));

        // Serialized items move only one by one (not by quantity).
        $this->assertThrows(fn () => $this->stock->receive($router, $warehouse, 5), StockException::class);

        $unit = Unit::query()->where('serial', 'ABC123')->sole();
        $this->stock->moveUnit($unit, $van);
        $this->stock->lend($unit->fresh(), $customer->getKey(), null);
        $unit->refresh();
        $this->assertSame([Unit::WITH_CUSTOMER, 'João Pereira', 0.0], [$unit->status, $unit->whereabouts(), $this->stock->balance($router, $van)]);

        // Back from the customer; the other one is lost.
        $this->stock->giveBack($unit, $warehouse);
        $this->stock->writeOff(Unit::query()->where('serial', 'ABC124')->sole(), Unit::LOST);
        $this->assertSame(1.0, $this->stock->balance($router, $warehouse));
        $this->assertSame(['entry', 'entry', 'transfer', 'lend', 'return', 'loss'], Movement::query()->orderBy('id')->pluck('kind')->all());
    }

    public function test_the_screens_and_the_places_limit(): void
    {
        $tenant = $this->tenant('pequena', ['estoque' => ['limits' => ['locations' => 1]]]);
        $this->signIn($tenant);
        $warehouse = Location::create(['name' => 'Depósito']);
        $item = Item::create(['name' => 'Parafuso']);

        Livewire::test(ManageBalances::class)
            ->callAction('entry', ['item_id' => $item->getKey(), 'location_id' => $warehouse->getKey(), 'quantity' => 50])
            ->assertNotified(__('stock.actions.entry_done'));
        Livewire::test(ManageBalances::class)
            ->callAction('transfer', ['item_id' => $item->getKey(), 'from_id' => $warehouse->getKey(), 'to_id' => $warehouse->getKey(), 'quantity' => 1])
            ->assertNotified(__('stock.errors.same_place'));

        // One place allowed: a second one is refused with the reason.
        $this->assertFalse(auth()->user()->can('createLocation', Location::class));
    }

    public function test_companies_never_see_each_others_stock(): void
    {
        Item::create(['name' => 'Segredo']);
        $other = $this->tenant('outra', ['estoque' => []]);
        app(TenantContext::class)->run($other, fn () => $this->assertSame(0, Item::count()));
    }

    public function test_every_text_in_the_three_languages(): void
    {
        foreach (['pt_BR', 'en', 'es'] as $locale) {
            app()->setLocale($locale);

            foreach (['module', 'units.statuses.customer', 'movements.kinds.lend', 'errors.not_enough', 'my.title', 'attention.low', 'locations.for_technicians'] as $key) {
                $this->assertNotSame('stock.'.$key, __('stock.'.$key), $locale.' '.$key);
            }
        }
    }
}
