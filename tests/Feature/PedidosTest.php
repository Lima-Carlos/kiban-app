<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Models\Site;
use App\Modules\ModuleRegistry;
use App\Modules\Pedidos\Enums\OrderStatus;
use App\Modules\Pedidos\Enums\Rhythm;
use App\Modules\Pedidos\Filament\BoardPage;
use App\Modules\Pedidos\Filament\Clients\ClientResource;
use App\Modules\Pedidos\Filament\Clients\Pages\CreateClient;
use App\Modules\Pedidos\Filament\Clients\Pages\ListClients;
use App\Modules\Pedidos\Filament\ClientsMapPage;
use App\Modules\Pedidos\Filament\DeliveriesPage;
use App\Modules\Pedidos\Filament\MenuItems\MenuItemResource;
use App\Modules\Pedidos\Filament\MenuItems\Pages\ManageMenuItems;
use App\Modules\Pedidos\Filament\MissingClientsPage;
use App\Modules\Pedidos\Filament\OrderDeskPage;
use App\Modules\Pedidos\Filament\Orders\OrderResource;
use App\Modules\Pedidos\Filament\Orders\Pages\ViewOrder;
use App\Modules\Pedidos\Filament\PedidosSettingsPage;
use App\Modules\Pedidos\Models\Client;
use App\Modules\Pedidos\Models\MenuItem;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Models\PedidosSetting;
use App\Modules\Pedidos\Models\Run;
use App\Modules\Pedidos\Models\Staff;
use App\Modules\Pedidos\Services\ClientService;
use App\Modules\Pedidos\Services\CustomerRhythm;
use App\Modules\Pedidos\Services\OrderService;
use App\Modules\Pedidos\Services\PedidosException;
use App\Modules\Pedidos\Services\PedidosMessages;
use App\Modules\Pedidos\Services\PedidosPeople;
use App\Modules\Pedidos\Services\PedidosReports;
use App\Modules\Pedidos\Services\RoutePlanner;
use App\People\Person;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Core Pedidos (orders by phone/WhatsApp for small food businesses): the quick order screen, the way of an
 * order and who prepared/delivered, the delivery runs in the suggested order, each customer's rhythm (green, yellow,
 * red) with the "come back" message only with consent, the roles, the screens and one company never seeing another
 * one's orders.
 */
class PedidosTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected MenuItem $pizza;

    protected MenuItem $soda;

    protected function setUp(): void
    {
        parent::setUp();

        // Tuesday 24/11/2026, 19:00 in São Paulo.
        $this->travelTo(CarbonImmutable::parse('2026-11-24 22:00:00', 'UTC'));
        $this->company = $this->tenant('pizzariaexemplo', ['pedidos' => []]);
        $this->owner = $this->signIn($this->company);

        PedidosSetting::current()->update(['address' => 'Rua da Loja, 1', 'city' => 'Cidade Exemplo', 'state' => 'SP', 'latitude' => -23.5500, 'longitude' => -46.6300, 'delivery_fee_cents' => 500, 'configured_at' => now()]);
        $this->pizza = MenuItem::create(['name' => 'Pizza Calabresa', 'category' => 'Pizzas', 'price_cents' => 4500]);
        $this->soda = MenuItem::create(['name' => 'Refrigerante 2L', 'category' => 'Bebidas', 'price_cents' => 1200]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    protected function client(string $name, string $phone, ?array $point = null, array $extra = []): Client
    {
        $client = app(ClientService::class)->create(['name' => $name, 'phone' => $phone, 'street' => 'Rua Inventada', 'number' => (string) random_int(1, 999)] + $extra);

        if ($point !== null) {
            $client->mainSite->forceFill(['latitude' => $point[0], 'longitude' => $point[1]])->save();
        }

        return $client->refresh();
    }

    protected function order(Client $client, int $daysAgo = 0, string $fulfillment = 'delivery'): Order
    {
        $order = app(OrderService::class)->create([
            'customer_id' => $client->getKey(),
            'fulfillment' => $fulfillment,
            'lines' => [['menu_item_id' => $this->pizza->getKey(), 'quantity' => 1]],
        ], $this->owner);

        if ($daysAgo > 0) {
            $order->forceFill(['created_at' => now()->subDays($daysAgo)])->save();
        }

        return $order;
    }

    public function test_the_route_goes_to_the_nearest_first_and_ignores_the_order_of_the_calls(): void
    {
        $planner = app(RoutePlanner::class);
        $shop = [-23.5500, -46.6300];

        // Called first, but the farthest; one stop without a point.
        $plan = $planner->plan($shop, [
            10 => [-23.5500, -46.5800],
            11 => [-23.5500, -46.6200],
            12 => null,
            13 => [-23.5500, -46.6000],
        ]);

        $this->assertSame([11, 13, 10, 12], $plan['order']);
        $this->assertSame([12], $plan['missing']);
        $this->assertEqualsWithDelta(5100, $plan['distance'], 100);

        // Nearest neighbour alone would zig-zag here; swapping pieces of the way makes it shorter.
        $zigzag = [1 => [-23.5500, -46.6290], 2 => [-23.5500, -46.6320], 3 => [-23.5500, -46.6250], 4 => [-23.5500, -46.6400]];
        $best = $planner->plan($shop, $zigzag);
        // Nearest neighbour: 1, 2, 3, 4 = about 2.65 km; the best ways (east first, then west) = about 2.04 km.
        $this->assertSame(4, end($best['order']));
        $this->assertLessThanOrEqual(2100, $best['distance']);

        $url = $planner->mapsUrl($shop, [[-23.55, -46.62], [-23.55, -46.60], [-23.55, -46.58]]);
        $this->assertStringStartsWith('https://www.google.com/maps/dir/?api=1', $url);
        $this->assertStringContainsString('origin=-23.550000%2C-46.630000', $url);
        $this->assertStringContainsString('destination=-23.550000%2C-46.580000', $url);
        $this->assertStringContainsString('waypoints=-23.550000%2C-46.620000%7C-23.550000%2C-46.600000', $url);
        $this->assertNull($planner->mapsUrl($shop, []));
    }

    public function test_a_new_customer_and_order_on_the_phone_in_a_few_taps(): void
    {
        Livewire::test(OrderDeskPage::class)
            ->set('phone', '(11) 98888-0001')
            ->assertSet('searched', true)
            ->assertSet('clientId', null)
            ->assertSee(__('pedidos.desk.new_customer'))
            ->set('clientName', 'Joana Exemplo')
            ->set('address.street', 'Rua das Flores')
            ->set('address.number', '10')
            ->set('address.reference', 'Portão azul')
            ->set('consent', true)
            ->call('add', $this->pizza->getKey())
            ->call('add', $this->pizza->getKey())
            ->call('add', $this->soda->getKey())
            ->set('cart.0.notes', 'meio a meio com mussarela')
            ->set('payment', 'cash')
            ->set('changeFor', '150,00')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('cart', []);

        $order = Order::query()->with('lines')->sole();
        $joana = Client::query()->with(['mainSite', 'details'])->sole();
        $this->assertSame([1, 'Joana Exemplo', '5511988880001', Client::PERSON], [$order->number, $joana->name, $joana->phone, $joana->kind]);
        $this->assertSame(['Rua das Flores', 'Cidade Exemplo', 'Portão azul'], [$joana->mainSite->street, $joana->mainSite->city, $joana->mainSite->reference]);
        $this->assertSame(Site::PENDING, $joana->mainSite->locate_status); // searched on the map in the background
        $this->assertTrue($joana->hasWhatsAppConsent());
        $this->assertNotNull($joana->details->whatsapp_consent_at);
        $this->assertSame([9000 + 1200, 500, 10700, 15000], [$order->subtotal_cents, $order->delivery_fee_cents, $order->total_cents, $order->change_for_cents]);
        $this->assertSame(['2× Pizza Calabresa (meio a meio com mussarela)', '1× Refrigerante 2L'], $order->lines->map->label()->all());
        $this->assertSame($joana->mainSite->getKey(), $order->site_id);
        $this->assertStringContainsString('Rua das Flores, 10', $order->address);
        $this->assertSame(OrderStatus::New, $order->status);

        // The ticket for the kitchen.
        $this->get(route('pedidos.ticket', ['order' => $order->getKey()]))->assertOk()
            ->assertSee('#1')->assertSee('meio a meio com mussarela')->assertSee('Portão azul');
    }

    public function test_a_known_customer_appears_with_the_last_order_and_the_usual(): void
    {
        $joana = $this->client('Joana Exemplo', '11988880001', null, ['payment_preference' => 'pix']);
        foreach ([3, 2, 1] as $days) {
            $order = app(OrderService::class)->create(['customer_id' => $joana->getKey(), 'lines' => [['menu_item_id' => $this->pizza->getKey(), 'quantity' => 2, 'notes' => 'sem cebola']]]);
            $order->forceFill(['created_at' => now()->subDays($days)])->save();
        }
        app(OrderService::class)->create(['customer_id' => $joana->getKey(), 'lines' => [['menu_item_id' => $this->soda->getKey(), 'quantity' => 1]]]);
        $this->pizza->update(['price_cents' => 4900]);

        Livewire::test(OrderDeskPage::class)
            ->set('phone', '+55 11 98888-0001')
            ->assertSet('clientId', $joana->getKey())
            ->assertSet('payment', 'pix')
            ->assertSee('Joana Exemplo')
            ->assertSee(__('pedidos.desk.repeat_usual'))
            ->call('repeatUsual')
            ->assertSet('cart', [['menu_item_id' => $this->pizza->getKey(), 'name' => 'Pizza Calabresa', 'unit_price_cents' => 4900, 'quantity' => 2, 'notes' => 'sem cebola']])
            ->set('fulfillment', 'pickup')
            ->call('save');

        $last = Order::query()->latest('id')->first();
        $this->assertSame([5, 9800, 0, 'pickup'], [$last->number, $last->total_cents, $last->delivery_fee_cents, $last->fulfillment->value]);
        $this->assertSame(1, Client::query()->count());

        // Opening a customer's page goes straight to a new order with the phone.
        Livewire::withQueryParams(['telefone' => $joana->phone])->test(OrderDeskPage::class)->assertSet('clientId', $joana->getKey());
    }

    public function test_the_phone_is_unique_and_a_delivery_needs_an_address(): void
    {
        $this->client('Joana Exemplo', '11988880001');

        $this->assertTrue(app(ClientService::class)->phoneTaken('(11) 98888-0001'));
        $this->expectExceptionObject(new PedidosException(__('pedidos.errors.phone_taken')));

        try {
            Livewire::test(CreateClient::class)
                ->fillForm(['name' => 'Outra Joana', 'phone' => '(11) 98888-0001', 'sites' => []])
                ->call('create')
                ->assertHasFormErrors(['phone']);

            $counter = app(OrderService::class)->create(['fulfillment' => 'pickup', 'source' => 'counter', 'lines' => [['name' => 'Esfiha avulsa', 'unit_price_cents' => 700, 'quantity' => 3]]]);
            $this->assertSame([2100, null, __('pedidos.orders.no_customer')], [$counter->total_cents, $counter->customer_id, $counter->customerName()]);

            $ifood = app(OrderService::class)->create(['fulfillment' => 'pickup', 'source' => 'app', 'lines' => [['menu_item_id' => $this->soda->getKey()]]]);
            $this->assertSame('app', $ifood->payment_method->value);
        } catch (PedidosException $exception) {
            $this->fail($exception->getMessage());
        }

        $nowhere = Client::query()->create(['name' => 'Sem Endereço', 'phone' => '5511977770000', 'kind' => Client::PERSON]);

        try {
            app(OrderService::class)->create(['customer_id' => $nowhere->getKey(), 'lines' => [['menu_item_id' => $this->pizza->getKey()]]]);
            $this->fail('A delivery without an address was taken.');
        } catch (PedidosException $exception) {
            $this->assertSame(__('pedidos.errors.no_address'), $exception->getMessage());
        }

        app(ClientService::class)->create(['name' => 'Repetida', 'phone' => '11988880001']);
    }

    public function test_the_way_of_an_order_with_who_prepared_and_who_delivered(): void
    {
        $ana = Person::create(['name' => 'Ana Cozinha']);
        $beto = Person::create(['name' => 'Beto Moto']);
        app(PedidosPeople::class)->save($ana, ['cooks' => true]);
        app(PedidosPeople::class)->save($beto, ['delivers' => true]);
        $this->assertSame(['Ana Cozinha'], app(PedidosPeople::class)->team('cooks')->pluck('name')->all());

        $joana = $this->client('Joana Exemplo', '11988880001');
        $order = $this->order($joana);

        // The board: one tap with the cook's name.
        Livewire::test(BoardPage::class)->assertSee('#1')->call('start', $order->getKey(), $ana->getKey());
        $order->refresh();
        $this->assertSame([OrderStatus::Preparing, $ana->getKey()], [$order->status, $order->prepared_by]);

        $this->travel(20)->minutes();
        Livewire::test(BoardPage::class)->call('ready', $order->getKey());
        $this->assertSame(OrderStatus::Ready, $order->fresh()->status);

        // Changing who prepared later; a finished order never moves again.
        app(OrderService::class)->setPreparer($order->fresh(), null);
        $this->assertNull($order->fresh()->prepared_by);

        $this->travel(15)->minutes();
        $delivered = app(OrderService::class)->deliver($order->fresh(), $beto->getKey());
        $this->assertSame([OrderStatus::Delivered, $beto->getKey(), 35], [$delivered->status, $delivered->delivered_by, $delivered->minutes()]);

        try {
            app(OrderService::class)->cancel($delivered, 'Desistiu');
            $this->fail('A delivered order was cancelled.');
        } catch (PedidosException $exception) {
            $this->assertSame(__('pedidos.errors.final'), $exception->getMessage());
        }

        // Cancel with the reason; a pickup is "picked up" straight from ready.
        $other = $this->order($joana);
        Livewire::test(ViewOrder::class, ['record' => $other->getKey()])
            ->callAction('cancel', ['reason' => 'Cliente desistiu'])
            ->assertHasNoActionErrors();
        $this->assertSame([OrderStatus::Cancelled, 'Cliente desistiu'], [$other->fresh()->status, $other->fresh()->cancel_reason]);

        $pickup = $this->order($joana, 0, 'pickup');
        Livewire::test(BoardPage::class)->call('ready', $pickup->getKey())->call('deliver', $pickup->getKey());
        $this->assertSame([OrderStatus::Delivered, null], [$pickup->fresh()->status, $pickup->fresh()->delivered_by]);

        // Orders are never deleted.
        $this->assertFalse(OrderResource::canDelete($other));
    }

    public function test_delivery_runs_in_the_suggested_order_with_the_maps_link(): void
    {
        $beto = Person::create(['name' => 'Beto Moto']);
        app(PedidosPeople::class)->save($beto, ['delivers' => true]);

        $far = $this->order($this->client('Longe', '11900000001', [-23.5500, -46.5800]));
        $near = $this->order($this->client('Perto', '11900000002', [-23.5500, -46.6200]));
        $middle = $this->order($this->client('Meio', '11900000003', [-23.5500, -46.6000]));
        $still = $this->order($this->client('Ainda Na Cozinha', '11900000004', [-23.5600, -46.6300]));
        foreach ([$far, $near, $middle] as $order) {
            app(OrderService::class)->ready($order);
        }

        Livewire::test(DeliveriesPage::class)
            ->assertSee('Longe')->assertDontSee('Ainda Na Cozinha')
            ->assertSee('https://www.google.com/maps/dir/', false)
            ->set('driverId', $beto->getKey())
            ->call('send');

        $run = Run::query()->sole();
        $this->assertSame([$near->getKey(), $middle->getKey(), $far->getKey()], $run->orders->pluck('id')->all());
        $this->assertSame([1, 2, 3], $run->orders->pluck('run_position')->all());
        $this->assertTrue($run->orders->every(fn (Order $order): bool => $order->status === OrderStatus::Out && $order->delivered_by === $beto->getKey()));
        $this->assertStringContainsString('waypoints=', app(OrderService::class)->runMapsUrl($run));

        // A not-ready order never leaves.
        try {
            app(OrderService::class)->sendRun([$still->getKey()], $beto->getKey());
            $this->fail('A not-ready order left.');
        } catch (PedidosException $exception) {
            $this->assertSame(__('pedidos.errors.not_ready', ['number' => $still->number]), $exception->getMessage());
        }

        $page = Livewire::test(DeliveriesPage::class);
        foreach ([$near, $middle, $far] as $order) {
            $page->call('delivered', $order->getKey());
        }
        $this->assertSame(Run::DONE, $run->fresh()->status);
        $this->assertSame(3, Order::query()->where('status', 'delivered')->count());
    }

    public function test_the_rhythm_of_each_customer(): void
    {
        // Every 7 days: green up to 10.5 days, yellow up to 21, red after that.
        $green = $this->client('Verde', '11900000001');
        $yellow = $this->client('Amarelo', '11900000002');
        $red = $this->client('Vermelho', '11900000003');
        $new = $this->client('Novo', '11900000004');
        foreach ([[$green, [21, 14, 7, 2]], [$yellow, [35, 28, 21, 14]], [$red, [50, 43, 36, 29, 22]], [$new, [3]]] as [$client, $days]) {
            foreach ($days as $ago) {
                $this->order($client, $ago);
            }
        }
        $this->order($this->client('Cancelado', '11900000005'), 1)->update(['status' => OrderStatus::Cancelled]);

        $rows = app(CustomerRhythm::class)->all();
        $this->assertSame(Rhythm::OnRhythm, $rows[$green->getKey()]['rhythm']);
        $this->assertEqualsWithDelta(6.33, $rows[$green->getKey()]['average_days'], 0.01);
        $this->assertSame(Rhythm::Late, $rows[$yellow->getKey()]['rhythm']);
        $this->assertSame(Rhythm::Stopped, $rows[$red->getKey()]['rhythm']);
        $this->assertSame(Rhythm::New, $rows[$new->getKey()]['rhythm']);
        $this->assertCount(4, $rows); // cancelled orders do not count

        // Red since day 22 + 21 = 1 day ago: it just stopped (the attention box tells the desk).
        $this->assertSame([$red->getKey()], app(CustomerRhythm::class)->justStopped()->keys()->all());
        $this->assertContains(trans_choice('pedidos.attention.stopped', 1, ['count' => 1]), array_column(app(PedidosReports::class)->attention($this->owner), 'text'));

        // A stricter company: stopped after 190% of the rhythm (13.3 days for a weekly customer).
        PedidosSetting::current()->update(['stopped_percent' => 190]);
        $this->assertSame(Rhythm::Stopped, app(CustomerRhythm::class)->for($yellow->getKey())['rhythm']);
        PedidosSetting::current()->update(['stopped_percent' => 300]);

        // The list and the map.
        Livewire::test(ListClients::class)
            ->assertCanSeeTableRecords([$red])
            ->filterTable('rhythm', 'red')
            ->assertCanSeeTableRecords([$red])
            ->assertCanNotSeeTableRecords([$green, $new]);
        $red->mainSite->forceFill(['latitude' => -23.56, 'longitude' => -46.64])->save();
        $map = Livewire::test(ClientsMapPage::class)->instance()->data();
        $this->assertSame(['#dc2626'], array_column($map['pins'], 'color'));
        $this->assertSame(1, $map['counts']['red']);
    }

    public function test_missing_customers_get_the_whatsapp_message_only_with_consent(): void
    {
        $ana = Person::create(['name' => 'Ana Cozinha']);
        $agrees = $this->client('Carla Sumida', '11900000001', null, ['whatsapp_consent' => true]);
        $refuses = $this->client('Davi Sumido', '11900000002');
        foreach ([$agrees, $refuses] as $client) {
            foreach ([60, 53, 46] as $ago) {
                $order = $this->order($client, $ago);
                app(OrderService::class)->start($order, $ana->getKey());
            }
        }

        $page = Livewire::test(MissingClientsPage::class)
            ->assertSee('Carla Sumida')->assertSee('Davi Sumido')
            ->assertSee(__('pedidos.orders.prepared_by', ['name' => 'Ana Cozinha']))
            ->assertSee(__('pedidos.missing.no_consent'));

        $url = app(PedidosMessages::class)->promo($agrees->refresh(), 'Oi {nome}, volta pra {empresa}!');
        $this->assertStringStartsWith('https://wa.me/5511900000001?text=', $url);
        $this->assertStringContainsString(rawurlencode('Oi Carla, volta pra '), $url);
        $this->assertNull(app(PedidosMessages::class)->promo($refuses->refresh(), 'Oi {nome}'));

        $page->set('message', 'Saudade, {nome}!')->call('saveMessage')->call('markSent', $agrees->getKey());
        $this->assertSame('Saudade, {nome}!', PedidosSetting::current()->promo_message);
        $this->assertNotNull($agrees->details()->first()->last_promo_at);
    }

    public function test_roles_desk_kitchen_delivery_and_viewer(): void
    {
        $desk = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($desk, 'pedidos', 'desk');
        $this->actingAs($desk);
        $this->assertTrue(OrderDeskPage::canAccess());
        $this->assertTrue(ClientResource::canCreate());
        $this->assertFalse(MenuItemResource::canCreate());
        $this->assertFalse(PedidosSettingsPage::canAccess());
        $this->assertTrue(DeliveriesPage::canAccess());
        $this->assertTrue(MissingClientsPage::canAccess());

        // A person marked "works in the kitchen" with a login: the board, nothing else.
        $cook = User::factory()->memberOf($this->company, 'member')->create();
        $person = Person::create(['name' => 'Ana Cozinha']);
        $person->forceFill(['user_id' => $cook->getKey()])->save();
        app(PedidosPeople::class)->save($person, ['cooks' => true]);
        app(AccessManager::class)->forget();
        $this->actingAs($cook);
        $this->assertSame('kitchen', app(AccessManager::class)->role($cook, 'pedidos'));
        $this->assertTrue(BoardPage::canAccess());
        $this->assertTrue(OrderResource::can('work'));
        $this->assertFalse(OrderDeskPage::canAccess());
        $this->assertFalse(DeliveriesPage::canAccess());
        $this->assertFalse(ClientResource::canViewAny());
        $this->assertSame(__('pedidos.enums.role.kitchen'), app(PedidosPeople::class)->roleLabel($person));

        $driver = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($driver, 'pedidos', 'delivery');
        $this->actingAs($driver);
        $this->assertTrue(DeliveriesPage::canAccess());
        $this->assertFalse(OrderDeskPage::canAccess());

        $viewer = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($viewer, 'pedidos', 'viewer');
        $this->actingAs($viewer);
        $this->assertTrue(OrderResource::canViewAny());
        $this->assertTrue(ClientsMapPage::canAccess());
        $this->assertFalse(OrderResource::can('work'));
        $this->assertFalse(OrderDeskPage::canAccess());

        // Someone without a role in the Pedidos sees nothing of it.
        $other = User::factory()->memberOf($this->company, 'member')->create();
        $this->actingAs($other);
        $this->assertFalse(BoardPage::canAccess());
        $this->assertFalse(OrderResource::canViewAny());
    }

    public function test_screens_open_and_the_start_page(): void
    {
        $joana = $this->client('Joana Exemplo', '11988880001', [-23.56, -46.64]);
        $order = $this->order($joana);

        foreach ([OrderDeskPage::getUrl(), BoardPage::getUrl(), DeliveriesPage::getUrl(), MissingClientsPage::getUrl(), ClientsMapPage::getUrl(), PedidosSettingsPage::getUrl(),
            MenuItemResource::getUrl('index'), ClientResource::getUrl('index'), ClientResource::getUrl('create'), ClientResource::getUrl('view', ['record' => $joana]), ClientResource::getUrl('edit', ['record' => $joana]),
            OrderResource::getUrl('index'), OrderResource::getUrl('view', ['record' => $order])] as $url) {
            $this->get($url)->assertOk();
        }

        Livewire::test(PedidosSettingsPage::class)->call('save')->assertHasNoErrors();
        Livewire::test(ManageMenuItems::class)
            ->callAction('create', ['name' => 'Esfiha de Carne', 'category' => 'Esfihas', 'price_cents' => '6.50', 'is_active' => true])
            ->assertHasNoActionErrors();
        $this->assertSame(650, MenuItem::query()->where('name', 'Esfiha de Carne')->value('price_cents'));

        $this->get($this->company->url('/'))->assertOk()
            ->assertSee(__('pedidos.today.title'))
            ->assertSee(trans_choice('pedidos.attention.waiting', 1, ['count' => 1]));

        // An item already ordered and a customer with orders are turned off, never deleted.
        $this->assertFalse(MenuItemResource::canDelete($this->pizza));
        $this->assertFalse(ClientResource::canDelete($joana));
        $this->assertTrue(MenuItemResource::canDelete(MenuItem::query()->where('name', 'Esfiha de Carne')->sole()));
    }

    public function test_the_first_steps_and_the_menu_before_the_first_order(): void
    {
        $this->company = $this->tenant('lanchonetenova', ['pedidos' => []]);
        $this->owner = $this->signIn($this->company);

        $this->assertNotNull(Livewire::test(OrderDeskPage::class)->instance()->blocked());
        MenuItem::create(['name' => 'X-Burguer', 'price_cents' => 2500]);
        $this->assertNull(Livewire::test(OrderDeskPage::class)->instance()->blocked());

        $steps = collect(app(ModuleRegistry::class)->get('pedidos')->progress($this->company))->pluck('done', 'key')->all();
        $this->assertSame(['shop' => false, 'menu' => true, 'order' => false], $steps);
    }

    public function test_each_company_only_sees_its_own_orders(): void
    {
        $joana = $this->client('Joana Exemplo', '11988880001');
        $order = $this->order($joana);
        $other = $this->tenant('outrapizzaria', ['pedidos' => []]);

        app(TenantContext::class)->run($other, function () use ($joana): void {
            $this->assertSame([0, 0, 0, 0], [Order::count(), MenuItem::count(), Staff::count(), Run::count()]);
            $this->assertNull(Client::query()->find($joana->getKey()));
            $this->assertNull(app(ClientService::class)->findByPhone('11988880001'));
            $this->assertSame([], app(CustomerRhythm::class)->all()->all());
            $this->assertSame(1, app(OrderService::class)->create(['fulfillment' => 'pickup', 'lines' => [['name' => 'Pastel', 'unit_price_cents' => 900]]])->number);
        });

        // The ticket of another company's order does not exist on this address.
        $this->get(route('pedidos.ticket', ['order' => Order::withoutGlobalScopes()->where('tenant_id', $other->getKey())->value('id')]))->assertNotFound();

        // The database refuses an order of one company for a customer of another.
        $this->expectException(QueryException::class);
        DB::table('pd_orders')->insert(['tenant_id' => $other->id, 'number' => 99, 'customer_id' => $joana->getKey(), 'created_at' => now(), 'updated_at' => now()]);
    }
}
