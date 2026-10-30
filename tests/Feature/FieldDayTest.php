<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\CompanyProfile;
use App\Models\PushSubscription;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Filament\ExecuteOrderPage;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\DayPlanner;
use App\Modules\Field\Services\DayWatch;
use App\Modules\Field\Services\FieldException;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\Field\Services\OrderExecutionService;
use App\Modules\Field\Services\OrderReport;
use App\Modules\Field\Services\WorkOrderService;
use App\Support\PhoneAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * Kiban Campo, the technician's day: travel time and "finish by", the alert to leave, "I will be late", "pass to
 * another technician", "on the way", the warnings to the office (each only once), the suggested order of the
 * route, the customer's rating, returns and the phone kept for alerts.
 */
class FieldDayTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected User $technician;

    protected WorkOrder $first;

    protected WorkOrder $second;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $this->company = $this->tenant('provedor', ['campo' => []]);
        $this->owner = $this->signIn($this->company);

        FieldSetting::current()->update(['business' => 'isp', 'configured_at' => now()]);
        app(FieldTemplates::class)->apply('isp');

        $this->technician = User::factory()->memberOf($this->company, 'member')->create(['name' => 'Lucas Técnico']);
        app(AccessManager::class)->set($this->technician, 'campo', FieldRole::Technician->value);
        $this->coreMember($this->company, $this->technician);

        // About 3.1 km apart in a straight line: × 1.4 ÷ 25 km/h = 10.5 min → 15 min.
        $this->first = $this->order('Maria da Silva', -43.94, '2026-11-03 13:00:00');
        $this->second = $this->order('Padaria Central', -43.91, '2026-11-03 15:00:00');
    }

    protected function order(string $customer, float $longitude, string $at, ?User $technician = null): WorkOrder
    {
        $record = Customer::create(['name' => $customer, 'phone' => '5531988887777']);
        $record->sites()->create(['street' => 'Rua A', 'number' => '1', 'city' => 'Belo Horizonte', 'latitude' => -19.92, 'longitude' => $longitude, 'is_main' => true]);

        return app(WorkOrderService::class)->open([
            'customer_id' => $record->getKey(),
            'service_type_id' => ServiceType::query()->where('name', 'Visita técnica')->sole()->getKey(),
            'technician_id' => ($technician ?? $this->technician)->getKey(),
            'scheduled_at' => $at,
        ], $this->owner);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function send(WorkOrder $order, string $type, array $data = []): void
    {
        app(OrderExecutionService::class)->apply($order->fresh(), $this->technician, ['id' => (string) Str::uuid(), 'type' => $type, 'taken_at' => now()->getTimestampMs(), 'data' => $data]);
    }

    /** @return array<int, string> */
    protected function titles(User $user): array
    {
        return $user->notifications()->get()->map(fn ($notice): string => $notice->data['title'])->all();
    }

    public function test_travel_time_and_until_when_to_finish(): void
    {
        $planner = app(DayPlanner::class);
        $this->assertNull($planner->travelMinutes(null, -43.94, -19.92, -43.91));
        $this->assertSame(15, $planner->travelMinutes(-19.92, -43.94, -19.92, -43.91));
        $this->assertSame(5, $planner->travelMinutes(-19.92, -43.94, -19.92, -43.9401)); // never less than 5 min

        $plan = $planner->plan($this->first);
        $this->assertTrue($plan['next']->is($this->second));
        $this->assertSame([15, '14:45', '14:30'], [$plan['travel'], $plan['finish_by']->format('H:i'), $plan['alert_at']->format('H:i')]);
        $this->assertNull($planner->plan($this->second)); // the last one of the day

        // The technician's screen shows the next service and until when to finish.
        $this->send($this->first, 'check_in', ['latitude' => -19.92, 'longitude' => -43.94]);
        $this->actingAs($this->technician);
        $this->get(ExecuteOrderPage::getUrl(['order' => $this->first->getKey()]))
            ->assertOk()
            ->assertSee('Padaria Central')
            ->assertSee('11:45') // shown in the company's time (UTC-3)
            ->assertSee(__('field.execute.late'));
    }

    public function test_on_the_way_late_and_pass_to_another_technician(): void
    {
        $this->send($this->first, 'on_the_way');
        $this->assertNotNull($this->first->fresh()->on_the_way_at);

        try {
            $this->send($this->first, 'delay', ['minutes' => 2]);
            $this->fail('A delay under 5 minutes should be refused.');
        } catch (FieldException $exception) {
            $this->assertSame(__('field.errors.delay_invalid'), $exception->getMessage());
        }

        $this->send($this->first, 'delay', ['minutes' => 30, 'reason' => 'Trânsito na avenida']);
        $this->assertSame(30, $this->first->fresh()->delay_minutes);
        $this->assertSame([__('field.notices.delayed', ['technician' => 'Lucas Técnico', 'minutes' => 30, 'customer' => 'Maria da Silva'])], $this->titles($this->owner));
        $this->assertStringContainsString('10:30', $this->owner->notifications()->sole()->data['body']);
        $this->assertSame([], $this->titles($this->technician));

        // Passing on needs a reason; the order goes back to "open", without technician or time.
        $this->expectExceptionMessage(__('field.errors.reason_required'));
        try {
            $this->send($this->second, 'handoff', ['reason' => ' ']);
        } finally {
            $this->send($this->second, 'handoff', ['reason' => 'Falta a escada grande']);
            $second = $this->second->fresh();
            $this->assertSame([OrderStatus::Open, null, null, 'Falta a escada grande'], [$second->status, $second->technician_id, $second->scheduled_at, $second->handoff_reason]);
            $this->assertContains(__('field.notices.handoff', ['technician' => 'Lucas Técnico', 'customer' => 'Padaria Central']), $this->titles($this->owner));
            $this->assertSame(['handed_off', 'scheduled', 'opened'], $second->events()->pluck('type')->all()); // newest first
            $this->assertStringContainsString('Falta a escada grande', OrderResource::eventText($second->events()->first()));
        }
    }

    public function test_the_day_watch_warns_each_thing_once(): void
    {
        $phones = Mockery::mock(PhoneAlerts::class);
        $phones->shouldReceive('send')->once()->withArgs(fn (User $user, string $title, string $body): bool => $user->is($this->technician)
            && $title === __('field.alerts.leave_title') && str_contains($body, '11:45') && str_contains($body, 'Padaria Central'))->andReturn(1);
        $this->app->instance(PhoneAlerts::class, $phones);

        $this->travelTo(CarbonImmutable::parse('2026-11-03 13:00:00'));
        $this->send($this->first, 'check_in', ['latitude' => -19.92, 'longitude' => -43.94]);
        $watch = app(DayWatch::class);

        // Before the alert time: nothing. At 14:31: the alert to leave, only once.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:20:00'));
        $this->assertSame(0, $watch->scan());
        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:31:00'));
        $this->assertSame(1, $watch->scan());
        $this->assertSame(0, $watch->scan());

        // Still there after the time to leave: the office hears of the risk, once.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 14:46:00'));
        $this->assertSame(1, $watch->scan());
        $this->assertSame(0, $watch->scan());
        $this->assertSame([__('field.notices.risk', ['technician' => 'Lucas Técnico', 'customer' => 'Padaria Central'])], $this->titles($this->owner));

        // A delay announced for the next one holds the "not arrived" warning until it runs out.
        $this->send($this->second, 'delay', ['minutes' => 30]);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 15:20:00'));
        $this->assertSame(0, $watch->scan());
        $this->travelTo(CarbonImmutable::parse('2026-11-03 15:46:00'));
        $this->assertSame(1, $watch->scan());
        $this->assertSame(0, $watch->scan());
        $this->assertContains(__('field.notices.not_arrived', ['technician' => 'Lucas Técnico', 'customer' => 'Padaria Central']), $this->titles($this->owner));

        $this->artisan('kiban:field')->assertSuccessful();
    }

    public function test_suggested_order_of_the_day(): void
    {
        FieldSetting::current()->update(['base_latitude' => -19.92, 'base_longitude' => -43.95]);
        $this->second->forceFill(['scheduled_at' => '2026-11-03 09:00:00'])->save(); // far (-43.91)
        $near = $this->order('Oficina do Zé', -43.945, '2026-11-03 11:00:00');
        $this->first->forceFill(['scheduled_at' => '2026-11-03 10:00:00'])->save(); // -43.94

        $planner = app(DayPlanner::class);
        $plan = $planner->suggest($this->technician, CarbonImmutable::parse('2026-11-03 12:00:00'));
        $this->assertSame([$near->getKey(), $this->first->getKey(), $this->second->getKey()], array_map(fn (array $step): int => $step['order']->getKey(), $plan));
        $this->assertSame('09:00', $plan[0]['at']->utc()->format('H:i')); // the day still starts at the first time
        $this->assertTrue($plan[1]['at']->gt($plan[0]['at']->addMinutes($near->duration_minutes)) || $plan[1]['at']->eq($plan[0]['at']->addMinutes($near->duration_minutes + 5)));

        $planner->apply($plan, $this->owner);
        $this->assertSame('09:00', $near->fresh()->scheduled_at->format('H:i'));
        $this->assertTrue($this->second->fresh()->scheduled_at->gt($this->first->fresh()->scheduled_at));
        $this->assertSame('rescheduled', $this->first->events()->value('type'));

        // The agenda offers it for the technician with two or more services.
        $this->get(OrderResource::getUrl('agenda'))->assertOk()->assertSee(__('field.agenda.suggest'));
    }

    public function test_the_customer_rates_the_service_once(): void
    {
        $this->first->forceFill(['status' => OrderStatus::Done, 'finished_at' => now()])->save();
        $reports = app(OrderReport::class);
        $this->get($reports->url($this->first))->assertOk()->assertSee(__('field.report.rating_question', ['company' => CompanyProfile::current()->name()]), false);

        $rate = $reports->data($this->first)['rateUrl'];
        $this->post($this->company->url('/os/'.$this->first->getKey().'/relatorio/nota'), ['rating' => 2])->assertForbidden(); // not signed
        $this->post($rate, ['rating' => 9])->assertSessionHasErrors('rating');
        $this->post($rate, ['rating' => 2, 'comment' => 'Demorou muito'])->assertRedirect();
        $this->post($rate, ['rating' => 5])->assertRedirect();

        $order = $this->first->fresh();
        $this->assertSame([2, 'Demorou muito'], [$order->rating, $order->rating_comment]);
        $this->assertSame([__('field.notices.low_rating', ['rating' => 2, 'customer' => 'Maria da Silva'])], $this->titles($this->owner));
        $this->get($reports->url($this->first))->assertSee(__('field.report.rating_thanks'));
    }

    public function test_a_new_order_soon_after_a_done_one_is_a_return(): void
    {
        $this->first->forceFill(['status' => OrderStatus::Done, 'finished_at' => now()->subDays(5)])->save();
        $customer = $this->first->customer;
        $type = ServiceType::query()->where('name', 'Visita técnica')->sole()->getKey();

        $again = app(WorkOrderService::class)->open(['customer_id' => $customer->getKey(), 'service_type_id' => $type], $this->owner);
        $this->assertSame($this->first->getKey(), $again->return_of_id);
        $this->get(OrderResource::getUrl('view', ['record' => $again]))->assertOk()->assertSee($this->first->label());

        // Older than the company's days (or turned off): not a return.
        $this->first->forceFill(['finished_at' => now()->subDays(31)])->save();
        $this->assertNull(app(WorkOrderService::class)->open(['customer_id' => $customer->getKey(), 'service_type_id' => $type], $this->owner)->return_of_id);
        $this->first->forceFill(['finished_at' => now()->subDay()])->save();
        FieldSetting::current()->update(['return_days' => 0]);
        $this->assertNull(app(WorkOrderService::class)->open(['customer_id' => $customer->getKey(), 'service_type_id' => $type], $this->owner)->return_of_id);
    }

    public function test_the_phone_is_kept_for_alerts(): void
    {
        $this->actingAs($this->technician);
        $url = $this->company->url('/avisos/celular');
        $phone = ['endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123', 'keys' => ['p256dh' => 'BKey', 'auth' => 'secret']];

        $this->postJson($url, $phone)->assertOk();
        $this->postJson($url, $phone)->assertOk();
        $this->postJson($url, ['endpoint' => 'http://insecure.test/x', 'keys' => ['p256dh' => 'a', 'auth' => 'b']])->assertUnprocessable();

        $kept = PushSubscription::query()->sole();
        $this->assertSame([$this->technician->getKey(), $this->company->getKey()], [$kept->user_id, $kept->tenant_id]);

        // Without the server keys, nothing is sent (and nothing breaks).
        config(['kiban.push.public_key' => null]);
        $this->assertSame(0, app(PhoneAlerts::class)->send($this->technician, 'Teste', 'Corpo'));
    }
}
