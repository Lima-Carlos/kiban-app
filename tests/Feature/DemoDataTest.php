<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\CompanyProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\Fleet\Models\Maintenance;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\TimeClock\Models\Closing;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Services\MonthClosing;
use App\Modules\TimeClock\Services\PunchService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * kiban:demo (app side): erases every company and record, then fills the demonstration company of the Core with
 * the roles of each account, vehicles, fuel-ups, a maintenance, a workplace, a schedule and punches.
 */
class DemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_fills_the_demo_company(): void
    {
        Storage::fake('local');
        $old = $this->tenant('carlos', ['frota' => []]);
        Storage::disk('local')->put('tenants/'.$old->id.'/foto.jpg', 'x');

        // The Core already has the demo company and its 5 accounts (created by kiban:demo in the Core).
        $this->coreLicenses['transportes-demo'] = $this->licenseToken('transportes-demo', ['frota' => [], 'ponto' => []]);
        foreach (['dono' => 'owner', 'rh' => 'member', 'frota' => 'member', 'motorista' => 'member', 'funcionario' => 'member'] as $index => $role) {
            $id = 700 + count($this->coreMembers['transportes-demo'] ?? []);
            $this->coreMembers['transportes-demo'][$id] = [
                'id' => $id, 'name' => ucfirst($index).' Demo', 'email' => "{$index}.demo@exemplo.test", 'phone' => null,
                'role' => $role, 'status' => 'active', 'invited_at' => null, 'last_login_at' => null,
            ];
        }

        $this->artisan('kiban:demo', ['--yes' => true])->assertSuccessful();

        // The old company and its files are gone.
        $tenant = Tenant::sole();
        $this->assertSame('transportes-demo', $tenant->subdomain);
        Storage::disk('local')->assertMissing('tenants/'.$old->id.'/foto.jpg');

        app(TenantContext::class)->run($tenant, function (): void {
            $user = fn (string $name): User => User::query()->where('email', "{$name}.demo@exemplo.test")->sole();
            $access = app(AccessManager::class);

            // One account per role.
            $this->assertSame(['manager', 'manager'], [$access->role($user('dono'), 'frota'), $access->role($user('dono'), 'ponto')]);
            $this->assertSame([null, 'manager'], [$access->role($user('rh'), 'frota'), $access->role($user('rh'), 'ponto')]);
            $this->assertSame(['manager', null], [$access->role($user('frota'), 'frota'), $access->role($user('frota'), 'ponto')]);
            $this->assertSame(FleetRole::Driver, app(FleetAccess::class)->fleetRole($user('motorista')));
            $this->assertSame('employee', $access->role($user('motorista'), 'ponto'));
            $this->assertSame([null, 'employee'], [$access->role($user('funcionario'), 'frota'), $access->role($user('funcionario'), 'ponto')]);

            // Example data.
            $this->assertSame(3, Vehicle::count());
            $this->assertSame(15, FuelUp::count());
            $this->assertSame(1, Maintenance::count());
            $this->assertSame(2, Employee::count());
            $this->assertGreaterThan(50, Punch::count());
            $this->assertNull(app(PunchService::class)->brokenLink());

            // Recent dates: the last fuel-up 2 days ago, the punches in the last working days, hired 5 weeks ago.
            $this->assertGreaterThan(now()->subDays(3), CarbonImmutable::parse(FuelUp::query()->max('fueled_at')));
            $this->assertGreaterThan(now()->subDays(12), CarbonImmutable::parse(Punch::query()->max('punched_at')));
            $this->assertGreaterThan(now()->subDays(40), CarbonImmutable::parse(FuelUp::query()->min('fueled_at')));
            $this->assertSame(now('America/Sao_Paulo')->startOfMonth()->subMonth()->toDateString(), Employee::query()->value('admitted_on')->toDateString());

            // The previous month closed (when it can already be closed): the driver signed, the employee not yet.
            if (app(MonthClosing::class)->canClose(now('America/Sao_Paulo')->startOfMonth()->subMonth(), 'America/Sao_Paulo')) {
                $this->assertSame(2, Closing::count());
                $this->assertSame(1, Closing::query()->whereNotNull('signed_at')->count());
            }
        });

        // Dates are back to normal after the command.
        $this->assertEqualsWithDelta(time(), now()->getTimestamp(), 60);
    }

    public function test_fills_only_the_video_company(): void
    {
        Storage::fake('local');
        $other = $this->tenant('carlos', ['ponto' => []]);
        Storage::disk('local')->put('tenants/'.$other->id.'/foto.jpg', 'x');

        // The Core already has the video company and its accounts (created by kiban:video-demo in the Core).
        $this->coreLicenses['rede-exemplo'] = $this->licenseToken('rede-exemplo', ['frota' => [], 'ponto' => [], 'campo' => []]);
        $accounts = ['dona' => 'owner', 'rh' => 'member', 'frota' => 'member', 'tecnico' => 'member', 'tecnica' => 'member',
            'gerente' => 'admin', 'empleado' => 'member', 'manager' => 'admin', 'employee' => 'member'];
        foreach ($accounts as $index => $role) {
            $id = 800 + count($this->coreMembers['rede-exemplo'] ?? []);
            $this->coreMembers['rede-exemplo'][$id] = [
                'id' => $id, 'name' => ucfirst($index).' Video', 'email' => "{$index}.video@exemplo.test", 'phone' => null,
                'role' => $role, 'status' => 'active', 'invited_at' => null, 'last_login_at' => null,
            ];
        }

        $this->artisan('kiban:video-demo', ['--yes' => true])->assertSuccessful();
        $first = Tenant::query()->where('subdomain', 'rede-exemplo')->sole();
        $this->artisan('kiban:video-demo', ['--yes' => true])->assertSuccessful();

        // The owner's company and its files stay; the video company was recreated (a new record each time).
        $this->assertNotNull($other->fresh());
        Storage::disk('local')->assertExists('tenants/'.$other->id.'/foto.jpg');
        $tenant = Tenant::query()->where('subdomain', 'rede-exemplo')->sole();
        $this->assertNotSame($first->id, $tenant->id);

        app(TenantContext::class)->run($tenant, function (): void {
            $this->assertSame(4, Vehicle::count());
            $this->assertSame(8, Employee::count());
            $this->assertGreaterThan(200, Punch::count());
            $this->assertNull(app(PunchService::class)->brokenLink());
            // Clean data for the videos: every punch inside the base.
            $this->assertSame(0, Punch::query()->whereJsonLength('flags', '>', 0)->count());

            // Campo: customers, today's agenda, orders done yesterday on the phone and quotes.
            $this->assertSame(8, Customer::count());
            $this->assertSame(3, WorkOrder::query()->where('status', 'done')->whereNotNull('signature_path')->count());
            $this->assertSame(5, WorkOrder::query()->where('status', 'scheduled')->count());
            $this->assertSame(3, WorkOrder::query()->where('status', 'open')->count());
            $this->assertSame(['approved', 'sent', 'draft'], Quote::query()->orderBy('number')->pluck('status')->map(fn ($status) => $status->value)->all());
            $this->assertSame('Rede Exemplo Fibra Ltda', CompanyProfile::current()->name());
        });
    }

    public function test_never_runs_outside_the_pc(): void
    {
        $this->app['env'] = 'production';

        $this->artisan('kiban:demo', ['--yes' => true])->assertFailed();
        $this->artisan('kiban:video-demo', ['--yes' => true])->assertFailed();
    }
}
