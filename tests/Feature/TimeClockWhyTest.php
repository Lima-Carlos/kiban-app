<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Filament\TimesheetPage;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\AdjustmentService;
use App\Modules\TimeClock\Services\DayCalculator;
use App\Modules\TimeClock\Services\DayExplainer;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * "Why did it come out like this?": each day of the time sheet explained in plain sentences.
 */
class TimeClockWhyTest extends TestCase
{
    use RefreshDatabase;

    protected const TZ = 'America/Sao_Paulo';

    protected Tenant $company;

    protected User $owner;

    protected Employee $rui;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('instaladora', ['ponto' => []]);
        $this->owner = $this->signIn($this->company);
        FilamentTimezone::set(self::TZ);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', self::TZ));
        $office = Workplace::create(['name' => 'Escritório', 'timezone' => self::TZ]);
        $schedule = Schedule::create(['name' => 'office'] + ScheduleService::presets()['office'] + ['anchor_date' => '2026-11-02']);
        $person = app(PeopleService::class)->add($this->company, ['name' => 'Rui', 'phone' => '11977776666', 'document' => '529.982.247-25'], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-11-01', 'workplace_id' => $office->id, 'schedule_id' => $schedule->id,
        ]], $this->owner, false)['person'];
        $this->rui = Employee::query()->where('person_id', $person->id)->sole();

        // Nov 3 within the tolerance; Nov 4 until 20:00 (2 hours of overtime); Nov 5 nothing (absence); Nov 6 exit added.
        foreach (['2026-11-03 08:03', '2026-11-03 12:00', '2026-11-03 13:00', '2026-11-03 18:02',
            '2026-11-04 08:00', '2026-11-04 12:00', '2026-11-04 13:00', '2026-11-04 20:00',
            '2026-11-06 08:00', '2026-11-06 12:00', '2026-11-06 13:00'] as $moment) {
            $this->travelTo(CarbonImmutable::parse($moment, self::TZ));
            app(PunchService::class)->punch($this->rui, ['photo_path' => 'x.jpg']);
        }
        $this->travelTo(CarbonImmutable::parse('2026-11-07 10:00', self::TZ));
        app(AdjustmentService::class)->record($this->rui, ['date' => '2026-11-06', 'type' => 'add_punch', 'time' => '18:00', 'reason' => 'Esqueceu a saída'], $this->owner);
    }

    /** @return array<int, string> */
    protected function why(string $date): array
    {
        $day = app(DayCalculator::class)->period($this->rui, CarbonImmutable::parse($date), CarbonImmutable::parse($date))[$date];

        return app(DayExplainer::class)->explain($day);
    }

    public function test_each_day_is_explained(): void
    {
        $tolerance = $this->why('2026-11-03');
        $this->assertSame(__('timeclock.why.expected_break', ['start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00', 'hours' => '9:00']), $tolerance[0]);
        $this->assertStringContainsString('08:03–12:00 (3:57)', $tolerance[1]);
        $this->assertContains(__('timeclock.why.tolerance', ['punch' => 5, 'day' => 10]), $tolerance);

        $this->assertContains(__('timeclock.why.extra', ['hours' => '2:00', 'percent' => 50]), $this->why('2026-11-04'));
        $this->assertContains(__('timeclock.why.absence'), $this->why('2026-11-05'));
        $this->assertContains(__('timeclock.why.added', ['time' => '18:00', 'reason' => 'Esqueceu a saída']), $this->why('2026-11-06'));
        $this->assertContains(__('timeclock.why.off'), $this->why('2026-11-07'));
        $this->assertSame([__('timeclock.why.future')], $this->why('2026-11-20'));
    }

    public function test_the_button_on_the_time_sheet(): void
    {
        $this->company->forceFill(['license_token' => $this->licenseToken($this->company, ['ponto' => []]), 'license_fetched_at' => now(), 'license_expires_at' => now()->addHours(72)])->save();
        $this->signIn($this->company->fresh());

        Livewire::test(TimesheetPage::class, ['employeeId' => $this->rui->id, 'month' => '2026-11'])
            ->mountAction('why', ['date' => '2026-11-04'])
            ->assertMountedActionModalSee(__('timeclock.why.extra', ['hours' => '2:00', 'percent' => 50]));
    }
}
