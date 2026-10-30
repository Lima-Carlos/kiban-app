<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Filament\ExecuteOrderPage;
use App\Modules\Field\Filament\MyOrdersPage;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\Material;
use App\Modules\Field\Models\OrderPhoto;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldException;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\Field\Services\OrderExecutionService;
use App\Modules\Field\Services\WorkOrderService;
use App\Modules\Fleet\Enums\OdometerSource;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\OdometerReading;
use App\Modules\Fleet\Services\VehicleService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Kiban Campo, part 4: the technician's phone sends the work piece by piece (arrival with location and km,
 * answers, photos, materials, signature, pause, finish), also hours later; a piece sent twice counts once;
 * finishing checks what is required; only the order's technician sends; the km goes to Kiban Frota.
 */
class FieldExecutionTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected User $technician;

    protected WorkOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $this->company = $this->tenant('provedor', ['campo' => [], 'frota' => []]);
        $this->owner = $this->signIn($this->company);

        FieldSetting::current()->update(['business' => 'isp', 'configured_at' => now()]);
        app(FieldTemplates::class)->apply('isp');

        $this->technician = User::factory()->memberOf($this->company, 'member')->create(['name' => 'Lucas Técnico']);
        app(AccessManager::class)->set($this->technician, 'campo', FieldRole::Technician->value);
        $this->coreMember($this->company, $this->technician);

        $customer = Customer::create(['name' => 'Maria da Silva']);
        $customer->sites()->create(['street' => 'Rua da Bahia', 'number' => '10', 'city' => 'Belo Horizonte', 'latitude' => -19.92, 'longitude' => -43.94, 'is_main' => true]);

        $this->order = app(WorkOrderService::class)->open([
            'customer_id' => $customer->getKey(),
            'service_type_id' => ServiceType::query()->where('name', 'Instalação')->sole()->getKey(),
            'technician_id' => $this->technician->getKey(),
            'scheduled_at' => '2026-11-03 13:00:00',
        ], $this->owner);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(string $type, array $data = [], ?string $id = null, ?CarbonInterface $at = null): void
    {
        app(OrderExecutionService::class)->apply($this->order->fresh(), $this->technician, ['id' => $id ?? (string) Str::uuid(), 'type' => $type, 'taken_at' => ($at ?? now())->getTimestampMs(), 'data' => $data]);
    }

    protected function image(): string
    {
        return 'data:image/jpeg;base64,'.base64_encode(UploadedFile::fake()->image('foto.jpg', 60, 60)->getContent());
    }

    /** Everything the installation checklist requires, plus the customer's signature. */
    protected function doTheWork(): void
    {
        $this->send('answer', ['key' => 'signal_power', 'value' => '-19.5']);
        $this->send('answer', ['key' => 'onu_serial', 'value' => 'ZTEG1234']);
        $this->send('photo', ['key' => 'cto_photo', 'image' => $this->image()]);
        $this->send('answer', ['key' => 'speed_test', 'value' => '512']);
        $this->send('signature', ['name' => 'Maria da Silva', 'image' => $this->image()]);
    }

    public function test_the_technician_does_the_work(): void
    {
        // Arrival, 80 m from the customer's point.
        $this->send('check_in', ['latitude' => -19.9207, 'longitude' => -43.94, 'accuracy' => 12]);
        $order = $this->order->fresh();
        $this->assertSame([OrderStatus::InProgress, '2026-11-03 12:00:00'], [$order->status, $order->checked_in_at->toDateTimeString()]);
        $this->assertEqualsWithDelta(55, $order->check_in_distance, 30);

        // Finishing too early says what is missing.
        try {
            $this->send('finish');
            $this->fail('Finishing without the required items should be refused.');
        } catch (FieldException $exception) {
            $this->assertStringContainsString('Potência do sinal na ONU', $exception->getMessage());
            $this->assertStringContainsString(__('field.execute.signature_required'), $exception->getMessage());
        }

        $this->doTheWork();
        $this->send('material', ['material_id' => Material::query()->where('name', 'Cabo drop')->sole()->getKey(), 'quantity' => 35.5]);
        $removed = (string) Str::uuid();
        $this->send('material', ['description' => 'Conector extra', 'quantity' => 1], $removed);
        $this->send('material_removed', ['piece' => $removed]);
        $this->send('note', ['text' => 'Instalado com cabo novo.']);
        $this->send('finish');

        $order = $this->order->fresh();
        $this->assertSame([OrderStatus::Done, 'Maria da Silva', 'Instalado com cabo novo.'], [$order->status, $order->signed_by, $order->work_done]);
        $this->assertSame(['Cabo drop'], $order->materials->pluck('description')->all());
        $this->assertSame('35.50', $order->materials->first()->quantity);
        $this->assertSame('cto_photo', OrderPhoto::query()->sole()->item_key);
        Storage::disk('local')->assertExists($order->signature_path);
        $this->assertSame(['finished', 'checked_in'], $order->events()->whereIn('type', ['checked_in', 'finished'])->pluck('type')->all());

        // A done order takes nothing more.
        $this->expectException(FieldException::class);
        $this->send('note', ['text' => 'depois']);
    }

    public function test_a_piece_sent_twice_counts_once_and_late_answers_do_not_win(): void
    {
        $id = (string) Str::uuid();
        $this->send('material', ['description' => 'Conector', 'quantity' => 2], $id);
        $this->send('material', ['description' => 'Conector', 'quantity' => 2], $id);
        $this->assertSame(1, $this->order->materials()->count());

        // The phone answered at 11:00 and at 10:00 (sent later, without signal): 11:00 wins.
        $this->send('answer', ['key' => 'speed_test', 'value' => '300'], null, now()->subHour());
        $this->send('answer', ['key' => 'speed_test', 'value' => '100'], null, now()->subHours(2));
        $this->assertSame('300', $this->order->answers()->sole()->value);
    }

    public function test_offline_pieces_keep_their_time(): void
    {
        $this->send('check_in', [], null, now()->subHours(3));

        // Too old: refused with the reason (the office fixes it by hand).
        try {
            $this->send('note', ['text' => 'x'], null, now()->subDays(8));
            $this->fail('A piece older than 7 days should be refused.');
        } catch (FieldException $exception) {
            $this->assertSame(__('field.errors.piece_too_old', ['days' => 7]), $exception->getMessage());
        }

        $this->doTheWork();
        // From the future (the phone's clock ahead): counts as now.
        $this->send('note', ['text' => 'y'], null, now()->addHour());
        $this->send('finish', [], null, now()->subHours(1));

        $order = $this->order->fresh();
        $this->assertSame(['2026-11-03 09:00:00', '2026-11-03 11:00:00', 'y'], [$order->checked_in_at->toDateTimeString(), $order->finished_at->toDateTimeString(), $order->work_done]);
    }

    public function test_pause_with_the_reason_then_continue(): void
    {
        $this->send('check_in');
        $this->send('pause', ['reason' => 'Falta o conector, volto amanhã']);
        $this->assertSame([OrderStatus::Paused, 'Falta o conector, volto amanhã'], [$this->order->fresh()->status, $this->order->fresh()->pause_reason]);

        $this->send('check_in');
        $this->assertSame(OrderStatus::InProgress, $this->order->fresh()->status);
    }

    public function test_a_customer_without_a_point_gets_the_technicians(): void
    {
        $site = $this->order->site;
        $site->forceFill(['latitude' => null, 'longitude' => null, 'location_source' => null])->save();

        $this->send('check_in', ['latitude' => -19.93, 'longitude' => -43.95]);

        $site->refresh();
        $this->assertSame([-19.93, -43.95, Site::TECHNICIAN], [$site->latitude, $site->longitude, $site->location_source]);
    }

    public function test_the_km_goes_to_the_fleet(): void
    {
        $vehicle = app(VehicleService::class)->register(['plate' => 'REX1A23', 'brand' => 'Fiat', 'model' => 'Strada', 'type' => 'pickup', 'fuel_type' => 'flex'], 20000, $this->owner);
        $driver = Driver::create(['name' => 'Lucas Técnico']);
        $driver->forceFill(['user_id' => $this->technician->getKey()])->save();
        $driver->vehicles()->attach($vehicle);

        $this->send('check_in', ['km' => 20150, 'vehicle_id' => $vehicle->getKey()]);

        $this->assertSame([20150, $vehicle->getKey()], [$this->order->fresh()->check_in_km, $this->order->fresh()->vehicle_id]);
        $reading = OdometerReading::query()->where('vehicle_id', $vehicle->getKey())->latest('id')->first();
        $this->assertSame([20150, OdometerSource::WorkOrder], [$reading->value, $reading->source]);
        $this->assertSame(20150, $vehicle->fresh()->odometer);
    }

    public function test_the_technicians_screens(): void
    {
        $this->actingAs($this->technician);

        // Start page: today's services and the button; "My work orders" with the order, how to get there.
        $this->get($this->company->url('/?sistema=campo'))->assertOk()->assertSee(__('field.my_orders.home_title'))->assertSee('Maria da Silva');
        $this->get(MyOrdersPage::getUrl())
            ->assertOk()
            ->assertSee('10:00')
            ->assertSee('Maria da Silva')
            ->assertSee('https://www.google.com/maps/dir/?api=1&amp;destination=-19.92%2C-43.94', false)
            ->assertSee(ExecuteOrderPage::getUrl(['order' => $this->order->getKey()]), false);

        // The order's page carries everything to work without signal.
        $this->get(ExecuteOrderPage::getUrl(['order' => $this->order->getKey()]))
            ->assertOk()
            ->assertSee('js/field-queue.js', false)
            ->assertSee('Potência do sinal na ONU')
            ->assertSee(__('field.execute.arrived'));

        // Another technician cannot open it; the manager has the office screens instead.
        $other = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($other, 'campo', FieldRole::Technician->value);
        $this->coreMember($this->company, $other);
        $this->actingAs($other);
        $this->get(ExecuteOrderPage::getUrl(['order' => $this->order->getKey()]))->assertForbidden();

        $this->actingAs($this->owner);
        $this->get(MyOrdersPage::getUrl())->assertForbidden();

        // The phone keeps these pages for when there is no signal.
        $this->assertStringContainsString('campo\/minhas-os', file_get_contents(public_path('sw.js')));
    }

    public function test_the_office_sees_what_the_technician_did(): void
    {
        $this->send('check_in', ['latitude' => -19.92, 'longitude' => -43.94]);
        $this->doTheWork();
        $this->send('material', ['material_id' => Material::query()->where('name', 'Cabo drop')->sole()->getKey(), 'quantity' => 20]);
        $this->send('finish');

        $this->get(OrderResource::getUrl('view', ['record' => $this->order]))
            ->assertOk()
            ->assertSee(__('field.orders.sections.execution'))
            ->assertSee('Potência do sinal na ONU: -19.5 dBm')
            ->assertSee('Foto da caixa de atendimento (CTO): 1 foto(s)')
            ->assertSee('20 m · Cabo drop')
            ->assertSee(__('field.execute.signed_by', ['name' => 'Maria da Silva']))
            ->assertSee(__('field.events.finished'));
    }

    public function test_only_the_orders_technician_sends(): void
    {
        $other = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($other, 'campo', FieldRole::Technician->value);
        $this->coreMember($this->company, $other);

        $this->actingAs($other);
        $this->postJson($this->company->url('/campo/os/'.$this->order->getKey().'/enviar'), ['piece' => ['id' => (string) Str::uuid(), 'type' => 'check_in']])
            ->assertOk()
            ->assertJson(['status' => 'refused', 'message' => __('field.errors.not_your_order')]);

        $this->actingAs($this->technician);
        $this->postJson($this->company->url('/campo/os/'.$this->order->getKey().'/enviar'), ['piece' => ['id' => (string) Str::uuid(), 'type' => 'check_in', 'taken_at' => now()->getTimestampMs()]])
            ->assertOk()
            ->assertJson(['status' => 'ok', 'order_status' => 'in_progress']);

        auth()->logout();
        $this->postJson($this->company->url('/campo/os/'.$this->order->getKey().'/enviar'), ['piece' => ['id' => (string) Str::uuid(), 'type' => 'note']])->assertUnauthorized();
    }
}
