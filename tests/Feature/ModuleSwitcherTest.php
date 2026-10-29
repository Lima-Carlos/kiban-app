<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Access\CurrentModule;
use App\Access\ModuleSwitcher;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
use App\Modules\TimeClock\Filament\TimesheetPage;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\AdjustmentService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The system switcher: the person's systems as icons at the top of the menu; the menu and the start page show only
 * the chosen system; remembered per person and company; a screen of another system switches by itself; numbers of
 * "Attention" on the other systems; "Company" fixed at the end of the menu; no switcher with only one system.
 */
class ModuleSwitcherTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('instaladora', ['frota' => [], 'ponto' => []]);
        $this->owner = $this->signIn($this->company);
    }

    protected function stored(User $user): ?string
    {
        return DB::table('tenant_user')->where('tenant_id', $this->company->id)->where('user_id', $user->id)->value('current_module');
    }

    public function test_the_menu_and_the_start_page_follow_the_chosen_system(): void
    {
        // First time: the first system (Frota), its menu only, the icons and "Company" at the end.
        $this->get($this->company->url('/'))->assertOk()
            ->assertSee(__('app.menu.systems'))
            ->assertSee(VehicleResource::getNavigationLabel())
            ->assertDontSee(TimesheetPage::getNavigationLabel())
            ->assertSee(__('app.menu.company'))
            ->assertSee(__('app.people.title'))
            ->assertSee(__('app.teams.title'))
            ->assertDontSee('Teams Page')
            // The greeting uses the person's first name.
            ->assertSee(__('app.home.welcome', ['name' => strtok($this->owner->name, ' ')]))
            // Files stay at the root, never under the company's path.
            ->assertSee('src="http://app.kiban.test/images/brand/logo.png"', false)
            ->assertDontSee('/instaladora/images/', false);

        // The Ponto icon: the start page and the menu change, and it is remembered.
        // (In tests the menu HTML is built once per process; the rule of each screen is checked instead.)
        $this->get($this->company->url('/?sistema=ponto'))->assertOk();
        $this->assertSame('ponto', $this->stored($this->owner));
        $this->get($this->company->url('/'))->assertOk()->assertSee(__('timeclock.steps.rules'))->assertDontSee(__('fleet.steps.vehicle'));
        $this->assertTrue(TimesheetPage::shouldRegisterNavigation());
        $this->assertFalse(VehicleResource::shouldRegisterNavigation());

        // A screen of another system (e.g. from a warning of the bell) switches by itself.
        $this->get($this->company->url('/frota/veiculos'))->assertOk();
        $this->assertSame('frota', $this->stored($this->owner));

        // A system the person cannot open, or that does not exist, is ignored.
        $this->get($this->company->url('/?sistema=campo'))->assertOk();
        $this->assertSame('frota', $this->stored($this->owner));
    }

    public function test_numbers_of_the_other_systems(): void
    {
        $office = Workplace::create(['name' => 'Escritório', 'timezone' => 'America/Sao_Paulo']);
        $schedule = Schedule::create(['name' => 'office'] + ScheduleService::presets()['office']);
        $person = app(PeopleService::class)->add($this->company, ['name' => 'Rui', 'phone' => '11977776666', 'document' => '529.982.247-25'], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $office->id, 'schedule_id' => $schedule->id,
        ]], $this->owner, true)['person'];
        $employee = Employee::query()->where('person_id', $person->id)->sole();
        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'America/Sao_Paulo'));
        app(AdjustmentService::class)->request($employee, ['date' => '2026-11-03', 'type' => 'excuse', 'whole_day' => true, 'reason' => 'Atestado'], $person->user);

        // Using the Frota: the Ponto icon shows what waits there.
        app(CurrentModule::class)->key();
        $items = collect(app(ModuleSwitcher::class)->items())->keyBy('key');
        $this->assertTrue($items['frota']['current']);
        $this->assertSame(0, $items['frota']['attention']);
        $this->assertGreaterThan(0, $items['ponto']['attention']);
    }

    public function test_one_system_only_has_no_switcher(): void
    {
        // A member who only sees the Ponto (an employee who clocks in): no icons, no "Company".
        $member = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($member, 'ponto', 'viewer');
        $this->actingAs($member);
        $this->coreMember($this->company, $member);

        $this->get($this->company->url('/'))->assertOk()
            ->assertDontSee(__('app.menu.systems'))
            ->assertDontSee(__('app.menu.company'))
            ->assertSee(TimesheetPage::getNavigationLabel());
        $this->assertSame([], app(ModuleSwitcher::class)->items());
    }
}
