<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Filament\Pages\PeoplePage;
use App\Filament\Pages\TeamsPage;
use App\Models\AccessProfile;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Driver;
use App\Modules\TimeClock\Enums\LaborProfile;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Filament\Employees\Pages\ListEmployees;
use App\Modules\TimeClock\Filament\TimeClockSettingsPage;
use App\Modules\TimeClock\Filament\Workplaces\Pages\ManageWorkplaces;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\TimeClockModule;
use App\People\PeopleService;
use App\People\Person;
use App\Rules\Cpf;
use App\Support\Country;
use App\Support\Geo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Ponto, part 1: the person's record with an optional login, CPF (Brazil) or a generic document
 * (other countries), teams, workplaces with a point on the map, employees, rule profiles per country,
 * the plan limit and who sees the documents.
 */
class TimeClockEmployeesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected Workplace $office;

    protected const CPF = '529.982.247-25';

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('instaladora', ['frota' => [], 'ponto' => ['limits' => ['employees' => 2]]]);
        $this->owner = $this->signIn($this->company);
        $this->office = Workplace::create(['name' => 'Escritório', 'timezone' => 'America/Sao_Paulo', 'latitude' => -23.5505, 'longitude' => -46.6333, 'radius_meters' => 200]);
    }

    /**
     * @param  array<string, mixed>  $ponto
     * @return array<string, mixed>
     */
    protected function employee(string $name, array $ponto = [], array $extra = []): array
    {
        return $extra + [
            'name' => $name,
            'with_login' => false,
            'document' => self::CPF,
            'modules' => ['ponto' => $ponto + ['clocks_in' => true, 'admitted_on' => '2026-10-01', 'job_title' => 'Instalador', 'workplace_id' => $this->office->id]],
        ];
    }

    public function test_an_employee_without_a_login_who_gets_one_later(): void
    {
        Livewire::test(PeoplePage::class)
            ->callAction('add', $this->employee('Paulo Instalador', [], ['phone' => '(11) 97777-6666']))
            ->assertHasNoActionErrors()
            ->assertNotified(__('app.people.added_without_login', ['name' => 'Paulo Instalador']));

        // Nothing went to the Core: no login.
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/members') && $request->method() === 'POST');

        $person = Person::query()->where('name', 'Paulo Instalador')->sole();
        $this->assertNull($person->user_id);
        $this->assertSame('cpf', $person->document_type);
        $this->assertSame('52998224725', $person->document);
        $this->assertSame('5511977776666', $person->phone);
        $employee = Employee::query()->where('person_id', $person->id)->sole();
        $this->assertSame($this->office->id, $employee->workplace_id);
        $this->assertSame('Instalador', $employee->job_title);

        Livewire::test(PeoplePage::class)->assertCanSeeTableRecords([$person])->assertSee(__('app.people.statuses.no_login'));
        Livewire::test(ListEmployees::class)->assertCanSeeTableRecords([$employee]);

        // Later he gets a login: the same record (and employee) is linked to it; he is an employee in the Ponto.
        Livewire::test(PeoplePage::class)
            ->callTableAction('grantLogin', $person, ['phone' => '11977776666'])
            ->assertHasNoTableActionErrors()
            ->assertNotified(__('app.people.access_ready', ['name' => 'Paulo Instalador']));

        $person->refresh();
        $this->assertNotNull($person->user_id);
        $this->assertSame(1, Person::query()->where('name', 'Paulo Instalador')->count());
        $this->assertSame(TimeClockRole::Employee, app(TimeClockAccess::class)->role($person->user));
    }

    public function test_cpf_rules_in_brazil(): void
    {
        // Invalid CPF.
        Livewire::test(PeoplePage::class)
            ->callAction('add', $this->employee('Ana', [], ['document' => '111.111.111-11']))
            ->assertHasActionErrors(['document']);

        // The CPF is required for someone who clocks in (official files): nothing is saved, no login is created.
        Livewire::test(PeoplePage::class)
            ->callAction('add', $this->employee('Bia', [], ['document' => null, 'with_login' => true, 'phone' => '11966665555']))
            ->assertNotified(__('timeclock.errors.cpf_required', ['name' => __('timeclock.employees.singular')]));
        $this->assertSame(0, Person::query()->where('name', 'Bia')->count());
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/members') && $request->method() === 'POST');

        // The same CPF cannot belong to two people.
        Livewire::test(PeoplePage::class)->callAction('add', $this->employee('Caio'))->assertHasNoActionErrors();
        Livewire::test(PeoplePage::class)
            ->callAction('add', $this->employee('Duda'))
            ->assertHasActionErrors(['document']);

        // A workplace (or "works in the street") and the hiring date are required.
        Livewire::test(PeoplePage::class)
            ->callAction('add', $this->employee('Edu', ['workplace_id' => null], ['document' => '111.444.777-35']))
            ->assertHasActionErrors();
        Livewire::test(PeoplePage::class)
            ->callAction('add', $this->employee('Edu', ['workplace_id' => null, 'is_external' => true], ['document' => '111.444.777-35']))
            ->assertHasNoActionErrors();
        $this->assertTrue(Employee::query()->whereHas('person', fn ($query) => $query->where('name', 'Edu'))->sole()->is_external);
    }

    public function test_plan_limit_counts_active_employees(): void
    {
        $service = app(PeopleService::class);
        $service->add($this->company, ['name' => 'Um', 'document' => '529.982.247-25'], ['ponto' => ['clocks_in' => true, 'admitted_on' => '2026-10-01', 'is_external' => true]], $this->owner, false);
        $two = $service->add($this->company, ['name' => 'Dois', 'document' => '111.444.777-35'], ['ponto' => ['clocks_in' => true, 'admitted_on' => '2026-10-01', 'is_external' => true]], $this->owner, false);

        Livewire::test(PeoplePage::class)
            ->callAction('add', $this->employee('Três', [], ['document' => '935.411.347-80']))
            ->assertNotified(__('app.denied.limit', ['limit' => 2]));
        $this->assertSame(0, Person::query()->where('name', 'Três')->count());

        // Deactivating someone frees the place; the record and the history stay.
        Livewire::test(PeoplePage::class)->callTableAction('deactivate', $two['person'])->assertNotified(__('app.people.deactivated'));
        $employee = Employee::query()->where('person_id', $two['person']->id)->sole();
        $this->assertFalse($employee->is_active);
        $this->assertNotNull($employee->terminated_on);
        Livewire::test(PeoplePage::class)->callAction('add', $this->employee('Três', [], ['document' => '935.411.347-80']))->assertHasNoActionErrors();
    }

    public function test_no_login_no_fleet_role_and_who_sees_the_cpf(): void
    {
        // Owner's decision: without a login there is no Frota role.
        app(PeopleService::class)->add($this->company, ['name' => 'Sem Login'], ['frota' => ['role' => 'driver']], $this->owner, false);
        $person = Person::query()->where('name', 'Sem Login')->sole();
        $this->assertSame(0, Driver::query()->count());
        $this->assertNull(app(FleetModule::class)->personRoleLabel($person));

        // The CPF: owners, administrators and the time clock manager; not a fleet manager.
        $hr = User::factory()->memberOf($this->company)->create();
        app(AccessManager::class)->set($hr, 'ponto', 'manager');
        $fleet = User::factory()->memberOf($this->company)->create();
        app(AccessManager::class)->set($fleet, 'frota', 'manager');

        $people = app(PeopleService::class);
        $this->assertTrue($people->canSeeDocuments($this->owner, $this->company));
        $this->assertTrue($people->canSeeDocuments($hr, $this->company));
        $this->assertFalse($people->canSeeDocuments($fleet, $this->company));

        // The fleet manager opens People without the CPF and without the Ponto section.
        $this->actingAs($fleet);
        $this->get($this->company->url('/pessoas'))->assertOk();
        Livewire::test(PeoplePage::class)->mountAction('add')->assertFormFieldDoesNotExist('document')->assertFormFieldDoesNotExist('modules.ponto.clocks_in')->assertFormFieldExists('modules.frota.role');
        $this->get($this->company->url('/ponto/funcionarios'))->assertForbidden();

        // HR: employees, workplaces, settings; the CPF in the form.
        $this->actingAs($hr);
        $this->get($this->company->url('/ponto/funcionarios'))->assertOk();
        $this->get($this->company->url('/ponto/locais'))->assertOk();
        $this->get($this->company->url('/ponto/configuracoes'))->assertOk();
        Livewire::test(PeoplePage::class)->mountAction('add')->assertFormFieldExists('document')->assertFormFieldExists('modules.ponto.clocks_in')->assertFormFieldDoesNotExist('modules.frota.role');
    }

    public function test_settings_steps_and_suggested_hr_profile(): void
    {
        // Brazilian company: "Brazil (CLT)" comes ready; the first step is to confirm it.
        $this->assertSame(LaborProfile::BrazilClt, TimeClockSetting::current()->labor_profile);
        $this->assertFalse(app(TimeClockModule::class)->isStepDone($this->company, 'rules'));

        Livewire::test(TimeClockSettingsPage::class)
            ->assertSet('data.country', 'BR')
            ->fillForm(['country' => 'BR', 'labor_profile' => 'br_clt'])
            ->call('save')
            ->assertNotified(__('timeclock.settings.saved'));
        $this->assertTrue(app(TimeClockModule::class)->isStepDone($this->company, 'rules'));
        $this->assertTrue(app(TimeClockModule::class)->isStepDone($this->company, 'workplace'));

        // With the time clock, the "HR" suggested profile appears; a deleted suggestion does not come back.
        app(AccessManager::class)->ensureSuggestedProfiles();
        $hr = AccessProfile::query()->where('suggestion', 'hr')->sole();
        $this->assertSame(['ponto' => 'manager'], $hr->roles);
        $hr->delete();
        app(AccessManager::class)->ensureSuggestedProfiles();
        $this->assertSame(0, AccessProfile::query()->where('suggestion', 'hr')->count());
    }

    public function test_another_country_uses_a_generic_document_and_its_phone_code(): void
    {
        $portugal = $this->tenant('lisboa', ['ponto' => []], ['country' => 'PT']);
        $owner = $this->signIn($portugal);
        $this->assertSame(LaborProfile::Custom, TimeClockSetting::current()->labor_profile);

        Livewire::test(PeoplePage::class)
            ->callAction('add', [
                'name' => 'João Silva',
                'with_login' => false,
                'phone' => '912 345 678',
                'document_type' => 'NIF',
                'document' => '123456789',
                'modules' => ['ponto' => ['clocks_in' => true, 'admitted_on' => '2026-10-01', 'is_external' => true]],
            ])
            ->assertHasNoActionErrors();

        $person = Person::query()->where('name', 'João Silva')->sole();
        $this->assertSame('NIF', $person->document_type);
        $this->assertSame('123456789', $person->document);
        $this->assertSame('351912345678', $person->phone);
        $this->assertSame('NIF 123456789', $person->documentLabel());
        $this->assertTrue(Employee::query()->where('person_id', $person->id)->exists()); // no CPF needed
    }

    public function test_workplaces_teams_and_tenancy(): void
    {
        Livewire::test(ManageWorkplaces::class)
            ->callAction('create', ['name' => 'Loja Centro', 'timezone' => 'America/Sao_Paulo', 'latitude' => -22.9068, 'longitude' => -43.1729, 'radius_meters' => 500])
            ->assertHasNoActionErrors();
        $store = Workplace::query()->where('name', 'Loja Centro')->sole();
        $this->assertSame(500, $store->radius_meters);
        $this->assertTrue($store->hasPoint());

        // A workplace with employees is not deleted.
        app(PeopleService::class)->add($this->company, ['name' => 'Funcionária', 'document' => self::CPF], ['ponto' => ['clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $store->id]], $this->owner, false);
        $this->assertFalse($this->owner->can('delete', $store->fresh()));
        $this->assertTrue($this->owner->can('delete', Workplace::create(['name' => 'Vazio', 'timezone' => 'UTC'])));

        // Teams: created here or right in the person's form.
        Livewire::test(TeamsPage::class)->callAction('create', ['name' => 'Equipe Instalação'])->assertHasNoActionErrors();
        $team = Team::query()->where('name', 'Equipe Instalação')->sole();
        $person = Person::query()->where('name', 'Funcionária')->sole();
        Livewire::test(PeoplePage::class)
            ->callTableAction('edit', $person, ['team_id' => $team->id, 'document' => self::CPF, 'modules' => ['ponto' => ['clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $store->id]]])
            ->assertHasNoTableActionErrors();
        $this->assertSame($team->id, $person->fresh()->team_id);

        // Another company sees nothing of it.
        $other = $this->tenant('outra', ['ponto' => []]);
        $this->signIn($other);
        $this->assertSame(0, Workplace::query()->count());
        $this->assertSame(0, Employee::query()->count());
        $this->assertSame(0, Team::query()->count());
        $this->assertSame(0, Person::query()->count()); // records appear when its People screen opens or someone signs in
    }

    public function test_shared_pieces(): void
    {
        $this->assertTrue(Cpf::isValid('529.982.247-25'));
        $this->assertFalse(Cpf::isValid('529.982.247-24'));
        $this->assertFalse(Cpf::isValid('000.000.000-00'));
        $this->assertSame('529.982.247-25', Cpf::format('52998224725'));

        $this->assertSame('5511988887777', Country::phone('(11) 98888-7777', 'BR'));
        $this->assertSame('5511988887777', Country::phone('5511988887777', 'BR'));
        $this->assertSame('12025550123', Country::phone('(202) 555-0123', 'US'));
        $this->assertSame('351912345678', Country::phone('+351 912 345 678', 'BR'));
        $this->assertNull(Country::phone('', 'BR'));

        // São Paulo → Rio: about 360 km.
        $this->assertEqualsWithDelta(360000, Geo::distance(-23.5505, -46.6333, -22.9068, -43.1729), 10000);
    }
}
