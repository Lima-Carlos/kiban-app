<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Pmoc\Filament\Plans\Pages\CreatePlan;
use App\Modules\Pmoc\Filament\Plans\Pages\ListPlans;
use App\Modules\Pmoc\Models\Plan;
use App\Modules\Pmoc\Models\Visit;
use App\Modules\Pmoc\Services\PmocActivities;
use App\Modules\Pmoc\Services\PmocService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Core PMOC: maintenance plans with equipment; visits become Campo work orders by themselves (lead days
 * ahead), with the activities of the month, quarter, half-year or year; the PMOC document in PDF.
 */
class PmocTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
    }

    protected function company(array $modules = ['campo' => [], 'pmoc' => []]): User
    {
        $tenant = $this->tenant('climafrio', $modules);
        $owner = $this->signIn($tenant);
        FieldSetting::current()->update(['business' => 'hvac', 'configured_at' => now()]);

        return $owner;
    }

    protected function plan(array $data = []): Plan
    {
        $customer = Customer::create(['name' => 'Clínica Sol']);
        $customer->sites()->create(['street' => 'Rua das Flores', 'number' => '100', 'city' => 'Três Pontas', 'is_main' => true]);

        $plan = Plan::create($data + [
            'customer_id' => $customer->getKey(), 'name' => 'PMOC Clínica Sol', 'responsible_name' => 'Ana Engenheira', 'responsible_registration' => 'CREA-MG 123',
            'frequency_months' => 1, 'lead_days' => 7, 'starts_on' => '2026-11-10', 'next_due_on' => '2026-11-10',
        ]);
        $plan->equipment()->create(['tag' => 'EV-01', 'kind' => 'split', 'room' => 'Recepção', 'brand' => 'Daikin', 'serial' => 'SN123', 'capacity_btu' => 12000, 'refrigerant' => 'R410A']);
        $plan->equipment()->create(['tag' => 'EV-02', 'kind' => 'cassette', 'room' => 'Consultório 1', 'capacity_btu' => 24000]);

        return $plan;
    }

    public function test_the_level_of_each_visit(): void
    {
        $activities = app(PmocActivities::class);

        // Monthly plan: months 3, 6, 9 and 12 are quarterly, 6 and 12 half-yearly, 12 yearly.
        $this->assertSame([1, 1, 3, 1, 1, 6, 1, 1, 3, 1, 1, 12], array_map(fn (int $month): int => $activities->level($month, 1), range(0, 11)));
        // Quarterly plan: 3, 6, 9 and 12.
        $this->assertSame([3, 6, 3, 12], array_map(fn (int $month): int => $activities->level($month, 3), [0, 3, 6, 9]));

        // The yearly visit does everything; the monthly one only its part.
        $this->assertCount(count(PmocActivities::ACTIVITIES) + 3, $activities->checklist(12));
        $this->assertSame(['pmoc_filters', 'pmoc_drain_pan', 'pmoc_drain', 'pmoc_noise', 'pmoc_controls', 'pmoc_supply_temperature'], array_slice(array_column($activities->checklist(1), 'key'), 0, 6));
        $this->assertNotContains('pmoc_chemical_cleaning', array_column($activities->checklist(3), 'key'));
    }

    public function test_visits_become_work_orders_ahead_of_the_date(): void
    {
        $this->company();
        $plan = $this->plan();
        $service = app(PmocService::class);

        // 10/11 is 7 days away: opened today (03/11). The next one (10/12) is not yet.
        $this->assertSame(1, $service->openDueVisits(CarbonImmutable::parse('2026-11-03')));
        $this->assertSame(0, $service->openDueVisits(CarbonImmutable::parse('2026-11-03')));

        $visit = Visit::sole();
        $order = $visit->workOrder;
        $this->assertSame(WorkOrder::PMOC, $order->origin);
        $this->assertSame('2026-12-10', $plan->fresh()->next_due_on->toDateString());
        $this->assertSame(1, $visit->level);
        $this->assertSame('pmoc_filters', $order->checklist[0]['key']);
        $this->assertStringContainsString('EV-01', (string) $order->internal_notes);
        $this->assertStringContainsString('SN123', (string) $order->internal_notes);
        $this->assertSame(__('pmoc.service_type'), $order->serviceType->name);

        // A plan left behind catches up a few visits at a time, never floods the agenda.
        $this->assertSame(PmocService::CATCH_UP, $service->openDueVisits(CarbonImmutable::parse('2027-12-01')));

        // An inactive plan opens nothing.
        $plan->update(['is_active' => false]);
        $this->assertSame(0, $service->openDueVisits(CarbonImmutable::parse('2028-12-01')));
    }

    public function test_the_screens_the_document_and_the_limit(): void
    {
        $this->company(['campo' => [], 'pmoc' => ['limits' => ['equipment' => 2]]]);
        $customer = Customer::create(['name' => 'Hotel Bela Vista']);

        // A plan with two units is saved; its first visit is the start date.
        Livewire::test(CreatePlan::class)
            ->fillForm([
                'customer_id' => $customer->getKey(), 'name' => 'PMOC Hotel', 'frequency_months' => 3, 'starts_on' => '2026-12-01', 'lead_days' => 10,
                'equipment' => [
                    ['tag' => 'EV-01', 'kind' => 'split', 'is_active' => true],
                    ['tag' => 'EV-02', 'kind' => 'vrf', 'is_active' => true],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();
        $plan = Plan::query()->where('name', 'PMOC Hotel')->sole();
        $this->assertSame('2026-12-01', $plan->next_due_on->toDateString());
        $this->assertSame(2, $plan->equipment()->count());

        // A third unit goes over the plan limit (2): nothing is saved.
        Livewire::test(CreatePlan::class)
            ->fillForm(['customer_id' => $customer->getKey(), 'name' => 'Outro', 'frequency_months' => 1, 'starts_on' => '2026-12-01', 'lead_days' => 7, 'equipment' => [['tag' => 'X', 'kind' => 'split', 'is_active' => true]]])
            ->call('create')
            ->assertNotified(__('pmoc.denied.limit', ['limit' => 2]));
        $this->assertSame(1, Plan::count());

        // The list: open the next visit now, and the PMOC document.
        Livewire::test(ListPlans::class)
            ->assertCanSeeTableRecords([$plan])
            ->callTableAction('openVisit', $plan)
            ->assertNotified();
        $this->assertSame(3, Visit::sole()->level);

        $pdf = app(PmocService::class)->pdf($plan->fresh());
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_it_needs_the_campo_and_the_right_role(): void
    {
        // Without the Campo, the screens explain why.
        $this->company(['pmoc' => []]);
        $this->assertFalse(auth()->user()->can('viewAny', Plan::class));

        // The Campo dispatcher manages the plans; a technician does not see them.
        $tenant = $this->tenant('outra', ['campo' => [], 'pmoc' => []]);
        $this->signIn($tenant);
        $dispatcher = User::factory()->memberOf($tenant, 'member')->create();
        $this->coreMember($tenant, $dispatcher);
        app(AccessManager::class)->set($dispatcher, 'campo', FieldRole::Dispatcher->value);
        app(AccessManager::class)->forget();
        $this->assertTrue($dispatcher->can('create', Plan::class));

        $technician = User::factory()->memberOf($tenant, 'member')->create();
        $this->coreMember($tenant, $technician);
        app(AccessManager::class)->set($technician, 'campo', FieldRole::Technician->value);
        app(AccessManager::class)->forget();
        $this->assertFalse($technician->can('viewAny', Plan::class));
    }

    public function test_companies_never_see_each_others_plans(): void
    {
        $this->company();
        $this->plan();
        $other = $this->tenant('outra', ['campo' => [], 'pmoc' => []]);
        app(TenantContext::class)->run($other, fn () => $this->assertSame(0, Plan::count()));
    }

    public function test_every_text_in_the_three_languages(): void
    {
        foreach (['pt_BR', 'en', 'es'] as $locale) {
            app()->setLocale($locale);

            foreach ([...array_map(fn (string $key): string => 'activities.'.$key, array_keys(PmocActivities::ACTIVITIES)), 'module', 'plans.empty', 'document.title', 'denied.needs_field', 'levels.12', 'kinds.vrf'] as $key) {
                $this->assertNotSame('pmoc.'.$key, __('pmoc.'.$key), $locale.' '.$key);
            }
        }
    }
}
