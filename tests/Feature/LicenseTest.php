<?php

namespace Tests\Feature;

use App\Licensing\LicenseManager;
use App\Licensing\LicenseState;
use App\Models\Tenant;
use App\Modules\Demo\Filament\Pages\ManageTaskLists;
use App\Modules\Demo\Filament\Pages\ManageTasks;
use App\Modules\Demo\Filament\TaskResource;
use App\Modules\Demo\Models\Task;
use App\Modules\Demo\Models\TaskList;
use Filament\Actions\CreateAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The signed license from the Core decides what each company sees, and keeps working when the Core is down.
 */
class LicenseTest extends TestCase
{
    use RefreshDatabase;

    public function test_first_visit_creates_the_company_from_the_core(): void
    {
        $this->coreLicenses['padaria'] = $this->licenseToken('padaria');

        $this->get('http://app.kiban.test/padaria/')->assertRedirect('http://app.kiban.test/padaria/auth/login');

        $tenant = Tenant::sole();
        $this->assertSame('padaria', $tenant->subdomain);
        $this->assertSame(static::uuidFor('padaria'), $tenant->uuid);
        $this->assertTrue($tenant->license()->hasModule('demo'));

        // Unknown address: friendly 404, and the Core is asked at most once a minute.
        $this->get('http://app.kiban.test/nao-existe/')->assertNotFound()->assertSee(__('app.not_found.title'));
        $this->get('http://app.kiban.test/nao-existe/')->assertNotFound();
        Http::assertSentCount(4); // token + key + padaria + nao-existe (once)

        // The central address is not a company.
        $this->get('http://app.kiban.test/')->assertRedirect(route('central.home'));
        $this->get('http://app.kiban.test/entrar/ir?company=padaria')->assertRedirect('http://app.kiban.test/padaria');

        // Core down: a new address says "try again", never "does not exist".
        $this->coreDown = true;
        $this->get('http://app.kiban.test/oficina/')->assertStatus(503)->assertSee(__('app.failed.core'));
        $this->get('http://app.kiban.test/entrar/ir?company=oficina')->assertStatus(503);
    }

    public function test_a_license_cannot_be_forged(): void
    {
        $tenant = $this->tenant();
        $other = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($other, $otherKey);

        // Signed by someone else.
        $tenant->forceFill(['license_token' => $this->licenseToken($tenant, privateKey: $otherKey)])->save();
        $this->assertSame(LicenseState::Invalid, $tenant->fresh()->license()->state);

        // Changed after signing (a module added by hand).
        [$header, $payload, $signature] = explode('.', $this->licenseToken($tenant));
        $claims = json_decode(base64_decode(strtr($payload, '-_', '+/')), true);
        $claims['modules']['ponto'] = ['access' => true];
        $tampered = $header.'.'.rtrim(strtr(base64_encode(json_encode($claims)), '+/', '-_'), '=').'.'.$signature;
        $tenant->forceFill(['license_token' => $tampered])->save();

        $license = $tenant->fresh()->license();
        $this->assertSame(LicenseState::Invalid, $license->state);
        $this->assertFalse($license->hasModule('ponto'));
        $this->assertFalse($license->hasModule('demo'));
    }

    public function test_keeps_working_while_the_core_is_down_until_the_license_expires(): void
    {
        $tenant = $this->tenant();
        $this->signIn($tenant);
        // First visit with the Core up (the app keeps its public key from then on).
        $this->get($tenant->url('/'))->assertOk();

        $this->coreDown = true;
        $this->travel(10)->minutes();

        $this->get($tenant->url('/'))->assertOk();
        $tenant->refresh();
        $this->assertNotNull($tenant->license_failed_at);
        $this->assertTrue($tenant->license()->isActive());

        // Close to expiring: everybody is warned.
        $this->travel(50)->hours();
        $this->get($tenant->url('/'))->assertOk()->assertSee(Str::before(__('app.license.offline', ['date' => '|']), '|'));

        // After 72 h without the Core: blocked (nothing is guessed).
        $this->travel(23)->hours();
        $this->get($tenant->url('/'))->assertStatus(503)->assertSee(__('app.blocked.expired'));

        // The Core is back: the next visit gets a new license and the company works again.
        $this->coreDown = false;
        $this->travel(2)->minutes();
        $this->get($tenant->url('/'))->assertOk();
        $this->assertNull($tenant->fresh()->license_failed_at);
    }

    public function test_deactivated_company_is_blocked(): void
    {
        $tenant = $this->tenant('padaria', ['demo' => []], ['active' => false]);
        $this->signIn($tenant);

        $this->get($tenant->url('/'))->assertStatus(503)->assertSee(__('app.blocked.inactive'));
    }

    public function test_the_menu_follows_the_license(): void
    {
        $tenant = $this->tenant('padaria', []);
        $this->signIn($tenant);

        $this->get($tenant->url('/'))->assertOk()->assertSee(__('app.home.no_modules'))->assertDontSee(__('demo.module'));
        $this->get($tenant->url('/demo/tasks'))->assertForbidden();

        $tenant = $this->tenant('oficina', ['demo' => []]);
        $this->signIn($tenant);
        $this->get($tenant->url('/'))->assertOk()->assertSee(__('demo.module'));
        $this->get($tenant->url('/demo/lists'))->assertOk();
    }

    public function test_read_only_lets_people_see_but_not_change(): void
    {
        $tenant = $this->tenant('padaria', ['demo' => ['read_only' => true, 'status' => 'read_only']]);
        $this->signIn($tenant);
        $list = TaskList::create(['name' => 'Pães']);

        $this->get($tenant->url('/demo/lists'))->assertOk()->assertSee('Pães')->assertSee(__('app.denied.read_only'));
        $this->get($tenant->url('/'))->assertSee(__('app.license.read_only', ['module' => __('demo.module')]));

        Livewire::test(ManageTaskLists::class)
            ->assertActionHidden(CreateAction::class)
            ->assertTableActionHidden('edit', $list)
            ->assertTableActionHidden('delete', $list);
    }

    public function test_plan_limit_with_tolerance(): void
    {
        $tenant = $this->tenant('padaria', ['demo' => ['limits' => ['tasks' => 2]]]);
        $this->signIn($tenant);
        $list = TaskList::create(['name' => 'Pães']);

        Task::create(['task_list_id' => $list->id, 'title' => 'Um']);
        $this->assertTrue(TaskResource::canCreate());

        Task::create(['task_list_id' => $list->id, 'title' => 'Dois']);
        $this->assertFalse(TaskResource::canCreate());
        Livewire::test(ManageTasks::class)->assertActionHidden(CreateAction::class)
            ->assertSee(__('app.denied.limit', ['limit' => 2]));
    }

    public function test_scheduled_refresh_updates_licenses_and_sends_first_steps(): void
    {
        $tenant = $this->tenant();
        $this->travel(10)->minutes();

        $this->artisan('kiban:licenses')->assertSuccessful();

        $this->assertTrue($tenant->fresh()->license_fetched_at->gt(now()->subMinute()));
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v1/tenants/padaria/onboarding')
            && $request['module'] === 'demo'
            && $request['steps'] === [['key' => 'list', 'done' => false], ['key' => 'task', 'done' => false], ['key' => 'complete', 'done' => false]]);

        // Company removed in the Core: no access anymore.
        unset($this->coreLicenses['padaria']);
        app(LicenseManager::class)->refresh($tenant->fresh());
        $this->assertSame(LicenseState::Missing, $tenant->fresh()->license()->state);
    }
}
