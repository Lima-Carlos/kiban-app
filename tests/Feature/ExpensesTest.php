<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Expenses\ExpensesModule;
use App\Modules\Expenses\Filament\Categories\Pages\ManageCategories;
use App\Modules\Expenses\Filament\Claims\Pages\ManageClaims;
use App\Modules\Expenses\Filament\PaymentsPage;
use App\Modules\Expenses\Models\Category;
use App\Modules\Expenses\Models\Claim;
use App\Modules\Expenses\Services\ExpenseException;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Fleet\Models\Expense as FleetExpense;
use App\Modules\Fleet\Models\Vehicle;
use App\Notices\Notice;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Core Despesas: the team claims what they paid with the receipt, the manager approves or refuses with the
 * reason and pays back by person; with the Frota, an approved toll of a vehicle becomes a cost of the vehicle.
 */
class ExpensesTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected ExpenseService $expenses;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $tenant = $this->tenant('provedor', ['despesas' => ['limits' => ['people' => 2]], 'frota' => []]);
        $this->owner = $this->signIn($tenant);
        $this->expenses = app(ExpenseService::class);
        $this->expenses->ensureCategories();
    }

    protected function category(string $key): Category
    {
        return Category::query()->where('key', $key)->sole();
    }

    protected function member(): User
    {
        $user = User::factory()->memberOf(app(TenantContext::class)->get(), 'member')->create();
        $this->coreMember(app(TenantContext::class)->get(), $user);

        return $user;
    }

    public function test_claim_approve_reject_and_pay_back(): void
    {
        $lucas = $this->member();
        $this->assertCount(8, Category::all());

        // The receipt is required by the category; never in the future; never zero.
        $toll = ['category_id' => $this->category('toll')->getKey(), 'spent_on' => '2026-11-02', 'amount_cents' => 1890, 'description' => 'Pedágio Dutra'];
        $this->assertThrows(fn () => $this->expenses->submit($lucas, $toll), ExpenseException::class);
        $this->assertThrows(fn () => $this->expenses->submit($lucas, ['spent_on' => '2026-11-04', 'receipt_path' => 'r.jpg'] + $toll), ExpenseException::class);
        $this->assertThrows(fn () => $this->expenses->submit($lucas, ['amount_cents' => 0, 'receipt_path' => 'r.jpg'] + $toll), ExpenseException::class);

        $first = $this->expenses->submit($lucas, ['receipt_path' => 'r.jpg'] + $toll);
        $second = $this->expenses->submit($lucas, ['category_id' => $this->category('other')->getKey(), 'spent_on' => '2026-11-03', 'amount_cents' => 2500, 'description' => 'Almoço com o cliente']);
        $third = $this->expenses->submit($lucas, ['category_id' => $this->category('other')->getKey(), 'spent_on' => '2026-11-03', 'amount_cents' => 900, 'description' => 'Café']);

        // The manager hears about it and sees it in "Attention".
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $this->owner->getKey()]);
        $this->assertNotEmpty(app(ExpensesModule::class)->attention($this->owner));
        $this->assertSame([], app(ExpensesModule::class)->attention($lucas));

        // Refusing needs the reason; a decided claim does not change.
        $this->assertThrows(fn () => $this->expenses->reject($third, $this->owner, ''), ExpenseException::class);
        $this->expenses->reject($third, $this->owner, 'Café não é reembolsado');
        $this->expenses->approve($first, $this->owner);
        $this->expenses->approve($second, $this->owner);
        $this->assertThrows(fn () => $this->expenses->approve($third->refresh(), $this->owner), ExpenseException::class);
        $this->assertDatabaseHas('notifications', ['notifiable_id' => $lucas->getKey()]);

        // To pay back: one line per person, then marked as paid at once.
        $this->assertSame(4390, $this->expenses->toPay()->first()['total']);
        $this->assertSame(2, $this->expenses->payPerson($lucas, $this->owner));
        $this->assertSame([Claim::PAID, Claim::PAID, Claim::REJECTED], [$first->refresh()->status, $second->refresh()->status, $third->refresh()->status]);
        $this->assertCount(0, $this->expenses->toPay());
    }

    public function test_over_the_limit_is_flagged_never_blocked(): void
    {
        $meal = $this->category('meal');
        $meal->update(['limit_cents' => 4000, 'receipt_required' => false]);

        $claim = $this->expenses->submit($this->owner, ['category_id' => $meal->getKey(), 'spent_on' => '2026-11-03', 'amount_cents' => 5500, 'description' => 'Jantar na viagem']);

        $this->assertTrue($claim->overLimit());
    }

    public function test_an_approved_toll_of_a_vehicle_goes_to_the_frota(): void
    {
        $vehicle = Vehicle::create(['plate' => 'RRR1R11', 'brand' => 'Fiat', 'model' => 'Strada', 'type' => 'car', 'fuel_type' => 'flex']);
        $claim = $this->expenses->submit($this->owner, ['category_id' => $this->category('toll')->getKey(), 'spent_on' => '2026-11-02', 'amount_cents' => 1890, 'description' => 'Pedágio', 'receipt_path' => 'r.jpg', 'vehicle_id' => $vehicle->getKey()]);

        $this->expenses->approve($claim, $this->owner);

        $expense = FleetExpense::query()->sole();
        $this->assertSame([1890, 'toll', $vehicle->getKey()], [$expense->amount_cents, $expense->category->value, $expense->vehicle_id]);
        $this->assertSame($expense->getKey(), $claim->refresh()->fleet_expense_id);
    }

    public function test_each_person_sees_only_their_own_and_only_the_manager_decides(): void
    {
        $lucas = $this->member();
        $ana = $this->member();
        $other = ['category_id' => $this->category('other')->getKey(), 'spent_on' => '2026-11-03', 'amount_cents' => 1000];
        $this->expenses->submit($lucas, $other + ['description' => 'Do Lucas']);
        $this->expenses->submit($ana, $other + ['description' => 'Da Ana']);

        $this->actingAs($lucas);
        Livewire::test(ManageClaims::class)->assertOk()->assertSee('Do Lucas')->assertDontSee('Da Ana')->assertDontSee(__('expenses.claims.approve'));
        $this->assertFalse($lucas->can('decide', Claim::class));
        $this->assertFalse(PaymentsPage::canAccess());
        $this->get(\App\Modules\Expenses\Filament\Categories\CategoryResource::getUrl('index'))->assertForbidden();

        // The plan allows 2 people claiming: a third one is told why.
        $this->actingAs($this->owner);
        $this->assertTrue($this->owner->can('decide', Claim::class));
        $this->assertFalse($this->owner->can('create', Claim::class));
        $this->assertTrue($lucas->can('create', Claim::class));
    }

    public function test_the_screens_open(): void
    {
        $lucas = $this->member();
        $this->expenses->submit($lucas, ['category_id' => $this->category('other')->getKey(), 'spent_on' => '2026-11-03', 'amount_cents' => 1000, 'description' => 'Estacionamento do shopping']);
        $this->expenses->approve(Claim::query()->sole(), $this->owner);

        Livewire::test(ManageClaims::class)->assertOk()->assertSee('Estacionamento do shopping')->assertSee($lucas->name);
        Livewire::test(ManageCategories::class)->assertOk()->assertSee(__('expenses.defaults.toll'));
        Livewire::test(PaymentsPage::class)->assertOk()->assertSee('data-expenses="to-pay"', false)->assertSee('10,00')
            ->call('pay', $lucas->getKey());
        $this->assertSame(Claim::PAID, Claim::query()->sole()->status);

        // Claiming from the screen: the rule refuses without the receipt and keeps the window open.
        Livewire::test(ManageClaims::class)
            ->callAction('create', ['spent_on' => '2026-11-03', 'category_id' => $this->category('toll')->getKey(), 'amount' => '12.50', 'description' => 'Pedágio'])
            ->assertNotified(__('expenses.errors.receipt'));
        Livewire::test(ManageClaims::class)
            ->callAction('create', ['spent_on' => '2026-11-03', 'category_id' => $this->category('other')->getKey(), 'amount' => '12.50', 'description' => 'Água'])
            ->assertHasNoActionErrors();
        $this->assertSame(1250, Claim::query()->where('description', 'Água')->sole()->amount_cents);
    }

    public function test_texts_in_the_three_languages(): void
    {
        foreach (['pt_BR', 'en', 'es'] as $locale) {
            app()->setLocale($locale);

            foreach (['expenses.module', 'expenses.payments.title', 'expenses.errors.receipt', 'expenses.notices.new', 'expenses.defaults.toll', 'expenses.statuses.paid', 'expenses.steps.claim_help'] as $key) {
                $this->assertNotSame($key, __($key), $locale.' '.$key);
            }

            $this->assertStringContainsString('2', trans_choice('expenses.attention.pending', 2, ['count' => 2]));
        }

    }
}
