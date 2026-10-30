<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Filament\Dashboard\FieldDashboard;
use App\Modules\Field\Filament\Dashboard\FieldMonthWidget;
use App\Modules\Field\Filament\Dashboard\FieldNowWidget;
use App\Modules\Field\Filament\Dashboard\FieldTechniciansWidget;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldReports;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\Field\Services\OrderExecutionService;
use App\Modules\Field\Services\WorkOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Campo dashboard: where each technician is now (late, at a service, on the way, free), the month's
 * numbers per technician, the "Attention" box of the office and the nearest free technician for an order.
 */
class FieldDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected User $lucas;

    protected User $ana;

    protected WorkOrder $first;

    protected WorkOrder $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $this->company = $this->tenant('provedor', ['campo' => []]);
        $this->owner = $this->signIn($this->company);

        FieldSetting::current()->update(['business' => 'isp', 'configured_at' => now(), 'base_latitude' => -19.92, 'base_longitude' => -44.10]);
        app(FieldTemplates::class)->apply('isp');

        $this->lucas = $this->technician('Lucas Técnico');
        $this->ana = $this->technician('Ana Técnica');

        $this->first = $this->order('Maria da Silva', -43.94, '2026-11-03 13:00:00', $this->lucas);
        $this->second = $this->order('Padaria Central', -43.91, '2026-11-03 15:00:00', $this->lucas);
    }

    protected function technician(string $name): User
    {
        $user = User::factory()->memberOf($this->company, 'member')->create(['name' => $name]);
        app(AccessManager::class)->set($user, 'campo', FieldRole::Technician->value);
        $this->coreMember($this->company, $user);

        return $user;
    }

    protected function order(string $customer, float $longitude, ?string $at, ?User $technician): WorkOrder
    {
        $record = Customer::create(['name' => $customer, 'phone' => '5531988887777']);
        $record->sites()->create(['street' => 'Rua A', 'number' => '1', 'city' => 'Belo Horizonte', 'latitude' => -19.92, 'longitude' => $longitude, 'is_main' => true]);

        return app(WorkOrderService::class)->open(array_filter([
            'customer_id' => $record->getKey(),
            'service_type_id' => ServiceType::query()->where('name', 'Visita técnica')->sole()->getKey(),
            'technician_id' => $technician?->getKey(),
            'scheduled_at' => $at,
        ]), $this->owner);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(WorkOrder $order, User $technician, string $type, array $data = []): void
    {
        app(OrderExecutionService::class)->apply($order->fresh(), $technician, ['id' => (string) Str::uuid(), 'type' => $type, 'taken_at' => now()->getTimestampMs(), 'data' => $data]);
    }

    /** @return array<string, string> technician name => state */
    protected function states(): array
    {
        return collect(app(FieldReports::class)->today()['technicians'])->mapWithKeys(fn (array $row): array => [$row['technician']->name => $row['state']])->all();
    }

    public function test_where_each_technician_is_now(): void
    {
        $this->assertSame(['Lucas Técnico' => 'free', 'Ana Técnica' => 'off'], $this->states());

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:50:00'));
        $this->send($this->first, $this->lucas, 'on_the_way');
        $this->assertSame('on_the_way', $this->states()['Lucas Técnico']);

        // 13:20 without arriving: late (the announced delay moves the time).
        $this->travelTo(CarbonImmutable::parse('2026-11-03 13:20:00'));
        $today = app(FieldReports::class)->today();
        $this->assertSame(['late', 20], [$today['technicians'][0]['state'], $today['technicians'][0]['minutes']]);
        $this->assertSame(1, $today['counts']['late']);

        $this->send($this->first, $this->lucas, 'delay', ['minutes' => 30]);
        $this->assertSame('on_the_way', $this->states()['Lucas Técnico']);

        $this->send($this->first, $this->lucas, 'check_in', ['latitude' => -19.92, 'longitude' => -43.94]);
        $this->assertSame('working', $this->states()['Lucas Técnico']);
        $this->assertSame(['scheduled' => 2, 'done' => 0, 'in_progress' => 1, 'late' => 0, 'unassigned' => 0], app(FieldReports::class)->today()['counts']);
    }

    public function test_the_month_per_technician(): void
    {
        WorkOrder::query()->whereKey($this->first->getKey())->update(['status' => OrderStatus::Done->value, 'checked_in_at' => '2026-11-03 13:10:00', 'finished_at' => '2026-11-03 14:10:00', 'price_cents' => 15000, 'rating' => 5]);
        WorkOrder::query()->whereKey($this->second->getKey())->update(['status' => OrderStatus::Done->value, 'checked_in_at' => '2026-11-03 15:30:00', 'finished_at' => '2026-11-03 16:00:00', 'price_cents' => 5000, 'rating' => 3, 'return_of_id' => $this->first->getKey()]);

        $month = app(FieldReports::class)->month(CarbonImmutable::parse('2026-11-01'));
        $expected = ['done' => 2, 'revenue' => 20000, 'average_minutes' => 45, 'on_time' => 50, 'returns' => 1, 'rating' => 4.0, 'ratings' => 2];

        $this->assertSame($expected, array_intersect_key($month['totals'], $expected));
        $this->assertCount(1, $month['technicians']);
        $this->assertTrue($month['technicians'][0]['technician']->is($this->lucas));
        $this->assertSame([], app(FieldReports::class)->month(CarbonImmutable::parse('2026-10-01'))['technicians']);
    }

    public function test_the_dashboard_page_and_who_sees_it(): void
    {
        $this->get(FieldDashboard::getUrl())->assertOk()->assertSee(__('field.dashboard.title'));
        Livewire::test(FieldNowWidget::class)->assertSee(__('field.dashboard.now'))->assertSee('Lucas Técnico')->assertSee(__('field.dashboard.states.free'));
        Livewire::test(FieldMonthWidget::class, ['pageFilters' => ['month' => '2026-11']])->assertSee(__('field.dashboard.on_time'));
        Livewire::test(FieldTechniciansWidget::class, ['pageFilters' => ['month' => '2026-11']])->assertSee(__('field.dashboard.no_done'));

        $this->actingAs($this->lucas);
        $this->get(FieldDashboard::getUrl())->assertForbidden();
        $this->assertSame([], app(FieldModule::class)->homeWidgets($this->lucas));
        $this->assertSame([], app(FieldModule::class)->attention($this->lucas));
    }

    public function test_attention_of_the_office(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-03 13:20:00'));
        $this->send($this->second, $this->lucas, 'handoff', ['reason' => 'Sem escada']);
        $this->order('Oficina Rápida', -43.95, null, null)->forceFill(['priority' => 'urgent'])->save();

        $texts = collect(app(FieldModule::class)->attention($this->owner))->pluck('text')->all();

        $this->assertSame([
            __('field.attention.late', ['technician' => 'Lucas Técnico', 'customer' => 'Maria da Silva', 'time' => '20 min']),
            trans_choice('field.attention.handed_off', 1, ['count' => 1]),
            trans_choice('field.attention.urgent', 1, ['count' => 1]),
        ], $texts);
    }

    public function test_nearest_free_technician_first(): void
    {
        $order = $this->order('Oficina Rápida', -43.95, null, null);

        // Both at the base (about 16 km away): same travel, by name.
        $this->assertSame(['Ana Técnica', 'Lucas Técnico'], collect(app(FieldReports::class)->nearest($order))->map(fn (array $row): string => $row['technician']->name)->all());

        // Lucas arrives at Maria (1 km from the order) and is busy there: Ana, free, comes first anyway.
        $this->send($this->first, $this->lucas, 'check_in', ['latitude' => -19.92, 'longitude' => -43.94]);
        $rows = app(FieldReports::class)->nearest($order);
        $this->assertSame(['Ana Técnica', true], [$rows[0]['technician']->name, $rows[0]['free']]);
        $this->assertSame(['Lucas Técnico', false, 5], [$rows[1]['technician']->name, $rows[1]['free'], $rows[1]['travel']]);
        $this->assertGreaterThan(20, $rows[0]['travel']);
    }
}
