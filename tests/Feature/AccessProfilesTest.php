<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Filament\Pages\AccessProfilesPage;
use App\Filament\Pages\PeoplePage;
use App\Models\AccessProfile;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * One system, many modules: each person sees only the modules they were given, with ready access profiles
 * ("Logistics", "Driver"...) and a start page that joins the summaries of their modules.
 */
class AccessProfilesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('transportes', ['frota' => [], 'demo' => []]);
        $this->owner = $this->signIn($this->company);
    }

    public function test_suggested_profiles_are_ready_and_editable(): void
    {
        Livewire::test(AccessProfilesPage::class)->assertOk();

        $names = AccessProfile::query()->orderBy('name')->pluck('name')->all();
        $this->assertContains(__('app.profiles.suggested.logistics'), $names);
        $this->assertContains(__('app.profiles.suggested.driver'), $names);
        $this->assertContains(__('app.profiles.suggested.mechanic'), $names);
        // HR only appears when the time clock module exists.
        $this->assertNotContains(__('app.profiles.suggested.hr'), $names);
        $this->assertSame(['frota' => 'driver'], AccessProfile::query()->where('suggestion', 'driver')->sole()->roles);

        // A new profile made by the company.
        Livewire::test(AccessProfilesPage::class)
            ->callAction('create', ['name' => 'Coordenador', 'roles' => ['frota' => 'manager']])
            ->assertHasNoActionErrors();
        $coordinator = AccessProfile::query()->where('name', 'Coordenador')->sole();
        $this->assertSame('manager', $coordinator->roleFor('frota'));

        Livewire::test(AccessProfilesPage::class)
            ->callTableAction('edit', $coordinator, ['name' => 'Coordenação', 'roles' => ['frota' => 'viewer']])
            ->assertHasNoTableActionErrors();
        $this->assertSame('viewer', $coordinator->fresh()->roleFor('frota'));
    }

    public function test_adding_a_person_with_a_profile(): void
    {
        app(AccessManager::class)->ensureSuggestedProfiles();
        $driverProfile = AccessProfile::query()->where('suggestion', 'driver')->sole();

        // Choosing the profile fills the role of each module in the form.
        Livewire::test(PeoplePage::class)
            ->mountAction('add')
            ->fillForm(['name' => 'Bia', 'phone' => '11944443333', 'profile_id' => $driverProfile->id])
            ->assertSchemaStateSet(['modules.frota.role' => 'driver'], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $bia = User::query()->where('name', 'Bia')->sole();
        $this->assertSame('driver', app(AccessManager::class)->role($bia, 'frota'));
        $this->assertTrue(app(AccessManager::class)->profileOf($bia)->is($driverProfile));

        // Deleting the profile keeps the person's access.
        Livewire::test(AccessProfilesPage::class)->callTableAction('delete', $driverProfile);
        $this->assertNull(app(AccessManager::class)->profileOf($bia));
        $this->assertSame('driver', app(AccessManager::class)->role($bia, 'frota'));
    }

    public function test_owner_sees_every_module_and_the_summaries(): void
    {
        // Every system as an icon at the top of the menu; the start page shows the one chosen.
        $this->get($this->company->url('/'))->assertOk()
            ->assertSee(__('app.menu.systems'))
            ->assertSee(__('demo.module'))
            ->assertSee(__('fleet.module'));
        $this->get($this->company->url('/?sistema=frota'))->assertOk()->assertSee(__('fleet.dashboard.summary'));
    }

    /**
     * (In tests the menu is built once per process, so the owner is not mixed here with the member;
     * on the server every request starts clean.)
     */
    public function test_each_person_sees_only_their_modules(): void
    {
        // A member without any role: no fleet at all (the demo module is open to everyone).
        $member = User::factory()->memberOf($this->company, 'member')->create();
        $this->actingAs($member);
        $this->coreMember($this->company, $member);
        $this->get($this->company->url('/'))->assertOk()->assertDontSee(__('fleet.dashboard.summary'))->assertDontSee(__('fleet.module'))->assertDontSee(__('app.menu.systems'));
        $this->get($this->company->url('/frota/veiculos'))->assertForbidden();
        $this->get($this->company->url('/frota/painel'))->assertForbidden();

        // Given "viewer" in the Frota: sees it, with the summary, but cannot change.
        app(AccessManager::class)->set($member, 'frota', 'viewer');
        $this->get($this->company->url('/?sistema=frota'))->assertSee(__('fleet.dashboard.summary'));
        $this->get($this->company->url('/frota/veiculos'))->assertOk();

        // Profiles page: only owners and administrators.
        $this->get($this->company->url('/perfis-de-acesso'))->assertForbidden();
    }

    public function test_message_for_someone_without_any_module(): void
    {
        $company = $this->tenant('so-frota', ['frota' => []]);
        $member = User::factory()->memberOf($company, 'member')->create();
        $this->actingAs($member);
        $this->coreMember($company, $member);
        $this->useTenant($company);

        $this->get($company->url('/'))->assertOk()->assertSee(__('app.home.no_access'));
    }
}
