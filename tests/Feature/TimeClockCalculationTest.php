<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Filament\Adjustments\Pages\ListAdjustments;
use App\Modules\TimeClock\Filament\TimesheetPage;
use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\AdjustmentService;
use App\Modules\TimeClock\Services\DayCalculator;
use App\Modules\TimeClock\Services\HourBank;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use App\People\PersonRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Ponto, part 4: the calculation of each day (tolerance of the CLT, overtime, minutes short, absences,
 * days off and holidays, night shift crossing midnight, night minutes with the reduced hour, short break and
 * short rest warnings), corrections apart from the punches (asked, approved, rejected) and the hour bank.
 */
class TimeClockCalculationTest extends TestCase
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
    }

    protected function employee(string $preset = 'office', bool $withLogin = false, string $cpf = '529.982.247-25', string $name = 'Rui'): Employee
    {
        $schedule = Schedule::query()->where('name', $preset)->first() ?? Schedule::create(['name' => $preset] + ScheduleService::presets()[$preset] + ['anchor_date' => '2026-11-02']);
        $person = app(PeopleService::class)->add($this->company, ['name' => $name, 'phone' => '11977776666', 'document' => $cpf], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $this->office->id, 'schedule_id' => $schedule->id,
        ]], $this->owner, $withLogin)['person'];

        return Employee::query()->where('person_id', $person->id)->sole();
    }

    /** Punches at local times ("Y-m-d H:i"). */
    protected function punch(Employee $employee, string ...$moments): void
    {
        foreach ($moments as $moment) {
            $this->travelTo(CarbonImmutable::parse($moment, self::TZ));
            app(PunchService::class)->punch($employee, ['photo_path' => 'x.jpg']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function day(Employee $employee, string $date): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-30 12:00', self::TZ));

        return app(DayCalculator::class)->period($employee, CarbonImmutable::parse($date), CarbonImmutable::parse($date))[$date];
    }

    public function test_tolerance_overtime_and_minutes_short(): void
    {
        $rui = $this->employee();

        // Within 5 minutes per punch and 10 per day: exactly the expected.
        $this->punch($rui, '2026-11-03 08:03', '2026-11-03 12:01', '2026-11-03 13:02', '2026-11-03 18:04');
        $day = $this->day($rui, '2026-11-03');
        $this->assertSame([540, 0, 0], [$day['expected'], $day['extra'], $day['deficit']]);
        $this->assertSame(540, $day['worked']); // 238 + 302

        // Only the start and the end of the day count for the tolerance (Súmula 366): 1 + 3 minutes, inside,
        // even if the break punches moved more (the sum of the 4 punches would be 11).
        $this->punch($rui, '2026-11-16 08:01', '2026-11-16 12:03', '2026-11-16 13:04', '2026-11-16 18:03');
        $day = $this->day($rui, '2026-11-16');
        $this->assertSame([541, 0, 0], [$day['worked'], $day['extra'], $day['deficit']]);

        // One punch 10 minutes early: every minute counts (CLT: the whole time, not only the excess).
        $this->punch($rui, '2026-11-04 07:50', '2026-11-04 12:00', '2026-11-04 13:00', '2026-11-04 18:20');
        $this->assertSame(30, $this->day($rui, '2026-11-04')['extra']);

        // 30 minutes late.
        $this->punch($rui, '2026-11-05 08:30', '2026-11-05 12:00', '2026-11-05 13:00', '2026-11-05 18:00');
        $day = $this->day($rui, '2026-11-05');
        $this->assertSame([510, 30, 0], [$day['worked'], $day['deficit'], $day['extra']]);
        $this->assertFalse($day['absence']);

        // No punch on a working day: absence.
        $day = $this->day($rui, '2026-11-09');
        $this->assertTrue($day['absence']);
        $this->assertSame(540, $day['deficit']);
    }

    public function test_days_off_holidays_and_warnings(): void
    {
        $rui = $this->employee();

        // Saturday (day off) and All Souls' Day (holiday): every minute is overtime at the rest-day rate.
        $this->punch($rui, '2026-11-07 09:00', '2026-11-07 13:00', '2026-11-02 08:00', '2026-11-02 12:00');
        $saturday = $this->day($rui, '2026-11-07');
        $this->assertSame(['off', 240, 0, false], [$saturday['type'], $saturday['extra_rest'], $saturday['deficit'], $saturday['absence']]);
        $holiday = $this->day($rui, '2026-11-02');
        $this->assertSame(['holiday', 240], [$holiday['type'], $holiday['extra_rest']]);
        $this->assertFalse($this->day($rui, '2026-11-08')['absence']); // Sunday off, no punch: nothing

        // A 30-minute lunch after 9 hours of work: short break warning.
        $this->punch($rui, '2026-11-10 08:00', '2026-11-10 12:00', '2026-11-10 12:30', '2026-11-10 17:30');
        $short = $this->day($rui, '2026-11-10');
        $this->assertContains('short_break', $short['flags']);
        // The warning says how long the break was.
        $this->assertSame(30, $short['break_minutes']);
        $this->assertSame(__('timeclock.timesheet.flags.short_break_minutes', ['minutes' => 30]), DayCalculator::flagLabel('short_break', $short));

        // Left at 23:00 and came back at 08:00: less than 11 hours of rest.
        $this->punch($rui, '2026-11-12 08:00', '2026-11-12 12:00', '2026-11-12 13:00', '2026-11-12 23:00');
        $this->punch($rui, '2026-11-13 08:00', '2026-11-13 12:00', '2026-11-13 13:00', '2026-11-13 18:00');
        $rest = $this->day($rui, '2026-11-13');
        $this->assertContains('short_rest', $rest['flags']);
        $this->assertSame(540, $rest['rest_minutes']); // 23:00 → 08:00
        $this->assertSame(300, $this->day($rui, '2026-11-12')['extra']);
    }

    public function test_night_shift_crossing_midnight(): void
    {
        $nina = $this->employee('night_shift', cpf: '111.444.777-35', name: 'Nina');

        // Tuesday 22:00 → Wednesday 06:00 (break 02:00–03:00): all of it is Tuesday's shift.
        $this->punch($nina, '2026-11-03 22:00', '2026-11-04 02:00', '2026-11-04 03:00', '2026-11-04 06:00');
        $tuesday = $this->day($nina, '2026-11-03');
        $this->assertCount(4, $tuesday['punches']);
        $this->assertSame([420, 0, 0], [$tuesday['worked'], $tuesday['extra'], $tuesday['deficit']]);
        // Night 22:00–05:00: 4h + 2h = 360 minutes, 411 with the reduced hour (52m30s).
        $this->assertSame([360, 411], [$tuesday['night'], $tuesday['night_reduced']]);
        $this->assertSame([], $this->day($nina, '2026-11-04')['punches']); // the morning punches belong to Tuesday's shift
    }

    public function test_corrections_apart_from_the_punches(): void
    {
        $rui = $this->employee(withLogin: true);
        $user = $rui->person->user;

        // Forgot the last punch: flagged; the employee asks, the manager approves.
        $this->punch($rui, '2026-11-06 08:00', '2026-11-06 12:00', '2026-11-06 13:00');
        $this->assertContains('missing_punch', $this->day($rui, '2026-11-06')['flags']);

        $this->actingAs($user);
        Livewire::test(TimesheetPage::class, ['month' => '2026-11'])
            ->callAction('adjust', ['type' => 'add_punch', 'time' => '18:00', 'reason' => 'Esqueci de bater a saída'], ['date' => '2026-11-06'])
            ->assertHasNoActionErrors()
            ->assertNotified(__('timeclock.adjustments.requested'));
        $request = Adjustment::sole();
        $this->assertTrue($request->isPending());
        $this->assertSame(1, $this->owner->fresh()->notifications()->count()); // the manager hears about it
        $this->assertSame(1, $this->day($rui, '2026-11-06')['pending']);

        $this->actingAs($this->owner);
        Livewire::test(ListAdjustments::class)->callTableAction('approve', $request)->assertHasNoTableActionErrors();
        $day = $this->day($rui, '2026-11-06');
        $this->assertSame([], $day['flags']);
        $this->assertSame(540, $day['worked']);
        $this->assertSame('added', $day['punches'][3]['kind']);
        $this->assertSame(4, Punch::count() + 1); // the punches themselves did not change
        $this->assertSame(1, $user->fresh()->notifications()->count()); // the employee hears the answer

        // A double punch left out by the manager (approved at once).
        $this->punch($rui, '2026-11-11 08:00', '2026-11-11 08:01', '2026-11-11 12:00', '2026-11-11 13:00', '2026-11-11 18:00');
        $double = Punch::query()->orderByDesc('nsr')->skip(3)->first();
        app(AdjustmentService::class)->record($rui, ['date' => '2026-11-11', 'type' => 'ignore_punch', 'punch_id' => $double->id, 'reason' => 'Toque duplo'], $this->owner);
        $day = $this->day($rui, '2026-11-11');
        $this->assertCount(4, $day['punches']);
        $this->assertSame([$double->id], $day['ignored']);
        $this->assertSame(0, $day['deficit']);

        // A medical certificate for the whole day: no absence.
        app(AdjustmentService::class)->record($rui, ['date' => '2026-11-09', 'type' => 'excuse', 'whole_day' => true, 'reason' => 'Atestado'], $this->owner);
        $day = $this->day($rui, '2026-11-09');
        $this->assertFalse($day['absence']);
        $this->assertSame([540, 0], [$day['excused'], $day['deficit']]);

        // Rules: reason required, no future day, nothing before the hiring date, an answer to reject.
        $service = app(AdjustmentService::class);
        $this->assertThrows(fn () => $service->request($rui, ['date' => '2026-11-10', 'type' => 'excuse', 'minutes' => 30, 'reason' => ' '], $user), PersonRuleException::class);
        $this->assertThrows(fn () => $service->request($rui, ['date' => '2026-12-20', 'type' => 'excuse', 'minutes' => 30, 'reason' => 'x'], $user), PersonRuleException::class);
        $this->assertThrows(fn () => $service->request($rui, ['date' => '2026-09-20', 'type' => 'excuse', 'minutes' => 30, 'reason' => 'x'], $user), PersonRuleException::class);
        $other = $service->request($rui, ['date' => '2026-11-10', 'type' => 'excuse', 'minutes' => 30, 'reason' => 'Médico'], $user);
        $this->assertThrows(fn () => $service->reject($other, $this->owner, ''), PersonRuleException::class);
        $service->reject($other, $this->owner, 'Sem atestado');
        $this->assertSame(Adjustment::REJECTED, $other->fresh()->status);
        $this->assertThrows(fn () => $service->approve($other->fresh(), $this->owner), PersonRuleException::class);
    }

    public function test_hour_bank(): void
    {
        TimeClockSetting::current()->update(['hour_bank' => true, 'hour_bank_months' => 6]);
        $rui = $this->employee();

        // +30 on Wednesday, -30 on Thursday, +60 on Friday; Monday's absence is not taken from the bank.
        $this->punch($rui, '2026-11-04 07:50', '2026-11-04 12:00', '2026-11-04 13:00', '2026-11-04 18:20');
        $this->punch($rui, '2026-11-05 08:30', '2026-11-05 12:00', '2026-11-05 13:00', '2026-11-05 18:00');
        $this->punch($rui, '2026-11-06 08:00', '2026-11-06 12:00', '2026-11-06 13:00', '2026-11-06 19:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00', self::TZ));
        $bank = app(HourBank::class)->balance($rui, CarbonImmutable::parse('2026-11-09'));
        $this->assertSame(['balance' => 60, 'credits' => 90, 'debits' => 30, 'expired' => 0], $bank);

        // Six months later the credit not used expires (to be paid).
        $this->travelTo(CarbonImmutable::parse('2027-06-01 12:00', self::TZ));
        $later = app(HourBank::class)->balance($rui, CarbonImmutable::parse('2027-05-31'));
        $this->assertSame(60, $later['expired']);
        $this->assertSame(0, $later['balance']); // the absences since then are reported apart, not taken from the bank
    }

    public function test_totals_screens_and_who_sees_what(): void
    {
        $rui = $this->employee(withLogin: true);
        $ana = $this->employee(cpf: '111.444.777-35', name: 'Ana');
        $this->punch($rui, '2026-11-03 08:00', '2026-11-03 12:00', '2026-11-03 13:00', '2026-11-03 18:00');
        $this->punch($rui, '2026-11-04 07:50', '2026-11-04 12:00', '2026-11-04 13:00', '2026-11-04 18:20');
        $this->travelTo(CarbonImmutable::parse('2026-11-05 07:00', self::TZ));

        $days = app(DayCalculator::class)->period($rui, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-30'));
        $totals = app(DayCalculator::class)->totals($days);
        // 3, 4 and today (5); the 2nd is a holiday. Today counts as expected but is never short or absent yet.
        $this->assertSame([1620, 1110, 30, 0], [$totals['expected'], $totals['worked'], $totals['extra'], $totals['deficit']]);
        $this->assertSame(0, $totals['absences']);

        // The employee: their month, no one else's.
        $this->actingAs($rui->person->user);
        $this->get($this->company->url('/ponto/apuracao?month=2026-11'))->assertOk()->assertSee(__('timeclock.timesheet.mine'));
        // Asking for someone else in the address shows their own month instead.
        $this->get($this->company->url('/ponto/apuracao?month=2026-11&employee='.$ana->id))->assertOk()->assertSee('Rui')->assertDontSee('Ana');
        $this->get($this->company->url('/ponto/correcoes'))->assertForbidden();

        // A viewer looks at anyone, without correcting.
        $viewer = User::factory()->memberOf($this->company)->create();
        app(AccessManager::class)->set($viewer, 'ponto', 'viewer');
        $this->actingAs($viewer);
        Livewire::test(TimesheetPage::class, ['employeeId' => $ana->id, 'month' => '2026-11'])->assertOk()->assertActionHidden('adjust');

        // The manager corrects at once.
        $this->actingAs($this->owner);
        $this->get($this->company->url('/ponto/apuracao?month=2026-11&employee='.$ana->id))->assertOk()->assertSee('Ana');
        $this->get($this->company->url('/ponto/correcoes'))->assertOk();
    }

    public function test_custom_rules_of_another_country(): void
    {
        $settings = TimeClockSetting::current();
        $settings->update(['labor_profile' => 'custom', 'tolerance_punch_minutes' => 0, 'tolerance_day_minutes' => 0, 'night_reduced_hour' => false]);
        $rui = $this->employee();

        // No tolerance: 3 minutes late count; no Brazilian warnings.
        $this->punch($rui, '2026-11-03 08:03', '2026-11-03 12:00', '2026-11-03 12:30', '2026-11-03 18:00');
        $day = $this->day($rui, '2026-11-03');
        $this->assertSame([567, 27, 0], [$day['worked'], $day['extra'], $day['deficit']]); // 237 + 330
        $this->assertSame([], $day['flags']);
    }
}
