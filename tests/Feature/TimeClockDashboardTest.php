<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Modules\TimeClock\Filament\Dashboard\MonthRankingWidget;
use App\Modules\TimeClock\Filament\Dashboard\MonthStatsWidget;
use App\Modules\TimeClock\Filament\Dashboard\NowWidget;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\AdjustmentService;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\Modules\TimeClock\Services\TimeClockReports;
use App\Modules\TimeClock\TimeClockModule;
use App\People\PeopleService;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Ponto, part 6: the dashboard (who is working now, late, on a break, done, excused; the month's numbers
 * and the people at the top) and the start page of managers.
 */
class TimeClockDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected const TZ = 'America/Sao_Paulo';

    protected Tenant $company;

    protected User $owner;

    protected Workplace $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('instaladora', ['ponto' => []]);
        $this->owner = $this->signIn($this->company);
        $this->office = Workplace::create(['name' => 'Escritório', 'timezone' => self::TZ]);
        FilamentTimezone::set(self::TZ);
    }

    protected function employee(string $name, string $cpf, bool $withLogin = false): Employee
    {
        $schedule = Schedule::query()->where('name', 'office')->first() ?? Schedule::create(['name' => 'office'] + ScheduleService::presets()['office'] + ['anchor_date' => '2026-11-02']);
        $person = app(PeopleService::class)->add($this->company, ['name' => $name, 'phone' => '11977776666', 'document' => $cpf], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $this->office->id, 'schedule_id' => $schedule->id,
        ]], $this->owner, $withLogin)['person'];

        return Employee::query()->where('person_id', $person->id)->sole();
    }

    protected function punch(Employee $employee, string ...$moments): void
    {
        foreach ($moments as $moment) {
            $this->travelTo(CarbonImmutable::parse($moment, self::TZ));
            app(PunchService::class)->punch($employee, ['photo_path' => 'x.jpg']);
        }
    }

    public function test_who_is_working_now(): void
    {
        $rui = $this->employee('Rui', '529.982.247-25');
        $ana = $this->employee('Ana', '111.444.777-35');
        $bia = $this->employee('Bia', '390.533.447-05');
        $caio = $this->employee('Caio', '714.602.380-01');

        $this->punch($rui, '2026-11-03 07:58');
        $this->punch($bia, '2026-11-03 08:01', '2026-11-03 12:00');
        app(AdjustmentService::class)->record($caio, ['date' => '2026-11-03', 'type' => 'excuse', 'whole_day' => true, 'reason' => 'Atestado'], $this->owner);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:30', self::TZ));

        $now = app(TimeClockReports::class)->now();
        $this->assertSame(['Rui'], collect($now['working'])->map(fn (array $row): string => $row['employee']->person->name)->all());
        $this->assertSame('07:58', $now['working'][0]['since']->format('H:i'));
        $this->assertSame(['Bia'], collect($now['break'])->map(fn (array $row): string => $row['employee']->person->name)->all());
        $this->assertSame([['Ana', 270]], collect($now['late'])->map(fn (array $row): array => [$row['employee']->person->name, $row['minutes']])->all());
        $this->assertSame(['Caio'], collect($now['excused'])->map(fn (Employee $employee): string => $employee->person->name)->all());
        $this->assertSame(4, $now['expected']);

        // Before the start (plus the tolerance), nobody is late yet.
        $this->travelTo(CarbonImmutable::parse('2026-11-04 08:04', self::TZ));
        $now = app(TimeClockReports::class)->now();
        $this->assertSame([], $now['late']);
        $this->assertCount(4, $now['later']); // Caio's excuse was only for yesterday
    }

    public function test_month_numbers_and_rankings(): void
    {
        $rui = $this->employee('Rui', '529.982.247-25');
        $ana = $this->employee('Ana', '111.444.777-35');
        $this->punch($rui, '2026-11-03 07:00', '2026-11-03 12:00', '2026-11-03 13:00', '2026-11-03 18:00');
        $this->punch($ana, '2026-11-03 09:00', '2026-11-03 12:00', '2026-11-03 13:00', '2026-11-03 18:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-04 07:00', self::TZ));

        $month = app(TimeClockReports::class)->month(CarbonImmutable::parse('2026-11-01'));
        $this->assertSame(2, $month['employees']);
        $this->assertSame(60, $month['totals']['extra']);
        $this->assertSame(60, $month['totals']['deficit']);
        $this->assertSame(0, $month['totals']['absences']);

        Livewire::test(MonthRankingWidget::class, ['pageFilters' => ['month' => '2026-11']])
            ->assertSeeInOrder([__('timeclock.dashboard.rankings.extra'), 'Rui', __('timeclock.dashboard.rankings.absences'), 'Ana']);
    }

    public function test_who_sees_the_dashboard(): void
    {
        $rui = $this->employee('Rui', '529.982.247-25', withLogin: true);
        $this->travelTo(CarbonImmutable::parse('2026-11-03 09:00', self::TZ));

        $this->get($this->company->url('/ponto/painel'))->assertOk()->assertSee(__('timeclock.dashboard.title'));
        // The widgets load right after the page.
        Livewire::test(NowWidget::class)->assertSee(__('timeclock.dashboard.now'))->assertSee('Rui')->assertSee(__('timeclock.dashboard.states.late'));
        Livewire::test(MonthStatsWidget::class, ['pageFilters' => ['home' => true]])->assertSee(__('timeclock.timesheet.totals.extra'));
        // The start page of the manager shows who is working now and the month.
        $module = app(ModuleRegistry::class)->get(TimeClockModule::KEY);
        $this->assertSame([NowWidget::class, MonthStatsWidget::class], $module->homeWidgets($this->owner));

        $viewer = User::factory()->memberOf($this->company)->create();
        app(AccessManager::class)->set($viewer, 'ponto', 'viewer');
        $this->actingAs($viewer);
        $this->get($this->company->url('/ponto/painel'))->assertOk();

        $this->actingAs($rui->person->user);
        $this->get($this->company->url('/ponto/painel'))->assertForbidden();
        $this->assertSame([], $module->homeWidgets($rui->person->user));
    }
}
