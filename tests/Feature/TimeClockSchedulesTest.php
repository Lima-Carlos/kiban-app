<?php

namespace Tests\Feature;

use App\Filament\Pages\PeoplePage;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Filament\Employees\Pages\ListEmployees;
use App\Modules\TimeClock\Filament\Holidays\NationalHolidaysWidget;
use App\Modules\TimeClock\Filament\Holidays\Pages\ManageHolidays;
use App\Modules\TimeClock\Filament\Schedules\Pages\ManageSchedules;
use App\Modules\TimeClock\Filament\Schedules\ScheduleResource;
use App\Modules\TimeClock\Filament\TimeClockSettingsPage;
use App\Modules\TimeClock\Models\City;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Holiday;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\CityHolidays;
use App\Modules\TimeClock\Services\CountryHolidays;
use App\Modules\TimeClock\Services\HolidayCalendar;
use App\Modules\TimeClock\Services\ScheduleCalendar;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use App\People\PersonRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Core Ponto, part 2: work schedules (weekly, night shift, 12x36, 6x1), the schedule history of each
 * employee, holidays (national calculated, optional, the company's own) and the calculation rules with the
 * Brazilian legal minimums.
 */
class TimeClockSchedulesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected Workplace $office;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('instaladora', ['ponto' => []]);
        $this->owner = $this->signIn($this->company);
        $this->office = Workplace::create(['name' => 'Escritório', 'timezone' => 'America/Sao_Paulo']);
    }

    protected function preset(string $key, array $extra = []): Schedule
    {
        return Schedule::create($extra + ['name' => $key] + ScheduleService::presets()[$key] + ['anchor_date' => '2026-11-02']);
    }

    protected function calendar(): ScheduleCalendar
    {
        return app(ScheduleCalendar::class);
    }

    protected function employee(Schedule $schedule, string $admitted = '2026-10-01'): Employee
    {
        $person = app(PeopleService::class)->add($this->company, ['name' => 'Rui', 'document' => '529.982.247-25'], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => $admitted, 'workplace_id' => $this->office->id, 'schedule_id' => $schedule->id,
        ]], $this->owner, false)['person'];

        return Employee::query()->where('person_id', $person->id)->sole();
    }

    public function test_weekly_and_night_schedules(): void
    {
        $office = $this->preset('office');
        $monday = CarbonImmutable::parse('2026-11-02');

        $plan = $this->calendar()->dayPlan($office, $monday);
        $this->assertSame(['start' => '08:00', 'end' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00', 'break_minutes' => 60, 'minutes' => 540], $plan);
        $this->assertNull($this->calendar()->dayPlan($office, $monday->addDays(5))); // Saturday
        $this->assertSame(45 * 60, $this->calendar()->weeklyMinutes($office));
        $this->assertSame(44 * 60, $this->calendar()->weeklyMinutes($this->preset('office_saturday')));

        // Night shift: 22:00 → 06:00 (next day), 1 hour break = 7 hours.
        $night = $this->preset('night_shift');
        $this->assertSame(420, $this->calendar()->dayPlan($night, $monday)['minutes']);
        $this->assertStringContainsString('Seg 08:00–18:00', $this->calendar()->summary($office));
    }

    public function test_rotations_like_12x36_and_6x1(): void
    {
        // 12x36 from Monday 2 November 2026: works Mon, Wed, Fri... and also the days before the reference.
        $rotation = $this->preset('12x36_day');
        $works = fn (string $date): bool => $this->calendar()->dayPlan($rotation, CarbonImmutable::parse($date)) !== null;
        $this->assertTrue($works('2026-11-02'));
        $this->assertFalse($works('2026-11-03'));
        $this->assertTrue($works('2026-11-04'));
        $this->assertTrue($works('2026-10-31'));
        $this->assertFalse($works('2026-11-01'));
        $this->assertSame(660, $this->calendar()->dayPlan($rotation, CarbonImmutable::parse('2026-11-02'))['minutes']); // 12h - 1h
        $this->assertSame(2310, $this->calendar()->weeklyMinutes($rotation)); // 38h30 on average

        // Night 12x36 crosses midnight: 19:00 → 07:00.
        $this->assertSame(660, $this->calendar()->dayPlan($this->preset('12x36_night'), CarbonImmutable::parse('2026-11-02'))['minutes']);

        // 6x1: six days of work and one off, regardless of the weekday.
        $sixOne = $this->preset('6x1');
        $days = collect(range(0, 13))->map(fn (int $day): bool => $this->calendar()->dayPlan($sixOne, CarbonImmutable::parse('2026-11-02')->addDays($day)) !== null)->all();
        $this->assertSame([true, true, true, true, true, true, false, true, true, true, true, true, true, false], $days);
    }

    public function test_schedule_rules_on_the_screen(): void
    {
        // A model fills the form; checks keep wrong hours out.
        Livewire::test(ManageSchedules::class)
            ->mountAction('create')
            ->fillForm(['preset' => 'office'])
            ->assertSchemaStateSet(['type' => 'weekly', 'week.1.start' => '08:00', 'week.6.works' => false], 'mountedActionSchema0')
            ->callMountedAction()
            ->assertHasNoActionErrors();
        $this->assertSame(1, Schedule::count());

        $check = fn (array $data) => app(ScheduleService::class)->check($data);
        $this->assertThrows(fn () => $check(['type' => 'weekly', 'week' => []]), PersonRuleException::class, __('timeclock.errors.no_workday'));
        $this->assertThrows(fn () => $check(['type' => 'weekly', 'week' => ['1' => ['works' => true, 'start' => '08:00', 'end' => '12:00', 'break_start' => '13:00', 'break_end' => '14:00']]]), PersonRuleException::class);
        $this->assertThrows(fn () => $check(['type' => 'cycle', 'work_days' => 1, 'off_days' => 1, 'start_time' => '07:00', 'end_time' => '08:00', 'break_minutes' => 90, 'anchor_date' => '2026-11-02']), PersonRuleException::class);

        // A schedule given to someone is not changed or deleted: it is duplicated.
        $schedule = Schedule::sole();
        $this->employee($schedule);
        $this->assertFalse($this->owner->can('update', $schedule));
        $this->assertFalse($this->owner->can('delete', $schedule));
        Livewire::test(ManageSchedules::class)
            ->assertTableActionHidden('edit', $schedule)
            ->callTableAction('duplicate', $schedule)
            ->assertNotified();
        $this->assertSame(2, Schedule::count());
    }

    public function test_days_with_the_same_hours_are_typed_once(): void
    {
        // Monday to Friday share one row of hours; Saturday has its own; Sunday stays off (a day off).
        Livewire::test(ManageSchedules::class)
            ->mountAction('create')
            ->fillForm([
                'name' => 'Comercial com sábado',
                'type' => 'weekly',
                'same_days' => ['1', '2', '3', '4', '5'],
                'group_start' => '08:00', 'group_break_start' => '12:00', 'group_break_end' => '13:00', 'group_end' => '18:00',
                'week.6.works' => true, 'week.6.start' => '08:00', 'week.6.end' => '12:00',
            ])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $week = Schedule::sole()->week;
        $this->assertTrue((bool) $week[5]['works']);
        $this->assertSame('18:00', substr((string) $week[5]['end'], 0, 5));
        $this->assertSame('12:00', substr((string) $week[6]['end'], 0, 5));
        $this->assertFalse((bool) ($week[7]['works'] ?? false));

        // Opening it again shows Monday to Friday grouped.
        $this->assertSame([1, 2, 3, 4, 5], ScheduleResource::groupOf($week)['days']);
        $this->assertSame('Seg a Sex', ScheduleResource::daysLabel([1, 2, 3, 4, 5]));
        $this->assertSame('Seg, Qua e Sex', ScheduleResource::daysLabel([1, 3, 5]));
    }

    public function test_a_rotation_is_picked_by_its_name(): void
    {
        // "24x48" fills the days on and off by itself (the numbers only show for "Other").
        Livewire::test(ManageSchedules::class)
            ->mountAction('create')
            ->fillForm(['name' => 'Plantão 24x48', 'type' => 'cycle', 'start_time' => '07:00', 'end_time' => '07:00', 'break_minutes' => 60, 'anchor_date' => '2026-11-03'])
            ->fillForm(['rotation' => '24x48'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $schedule = Schedule::sole();
        $this->assertSame([1, 2], [(int) $schedule->work_days, (int) $schedule->off_days]);
        $this->assertSame('24x48', ScheduleResource::rotationOf(1, 2));
        $this->assertSame('other', ScheduleResource::rotationOf(3, 3));
    }

    public function test_schedule_history_of_an_employee(): void
    {
        $office = $this->preset('office');
        $rotation = $this->preset('12x36_day');
        $employee = $this->employee($office, '2026-10-01');

        // From the hiring date.
        $this->assertTrue($this->calendar()->scheduleOn($employee, CarbonImmutable::parse('2026-10-01'))->is($office));
        $this->assertNull($this->calendar()->scheduleOn($employee, CarbonImmutable::parse('2026-09-30')));

        // Changes to 12x36 from 2 November: October keeps office hours.
        app(ScheduleService::class)->assign($employee, $rotation, CarbonImmutable::parse('2026-11-02'));
        $this->assertTrue($this->calendar()->scheduleOn($employee, CarbonImmutable::parse('2026-10-30'))->is($office));
        $this->assertTrue($this->calendar()->scheduleOn($employee, CarbonImmutable::parse('2026-11-02'))->is($rotation));
        $this->assertThrows(fn () => app(ScheduleService::class)->assign($employee, $rotation, CarbonImmutable::parse('2026-09-01')), PersonRuleException::class);

        // The month view: holiday on 2 November (All Souls) instead of work.
        $days = $this->calendar()->period($employee, CarbonImmutable::parse('2026-11-01'), CarbonImmutable::parse('2026-11-04'));
        $this->assertSame(__('timeclock.holidays.national.all_souls'), $days['2026-11-02']['holiday']);
        $this->assertNull($days['2026-11-02']['plan']);
        $this->assertNull($days['2026-11-03']['plan']); // 12x36 rest day
        $this->assertNotNull($days['2026-11-04']['plan']);

        // The employee needs a schedule; on the list, "see the month".
        // The list shows today's schedule (still office hours today, 24 October); the month view opens.
        $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00'));
        Livewire::test(ListEmployees::class)
            ->assertSee($office->name)
            ->callTableAction('month', $employee)
            ->assertHasNoTableActionErrors();
        $this->assertStringContainsString(__('timeclock.holidays.national.all_souls'), view('timeclock.employee-month', ['days' => $days])->render());
    }

    public function test_brazilian_holidays(): void
    {
        $this->assertSame('2026-04-05', HolidayCalendar::easter(2026)->toDateString());
        $this->assertSame('2025-04-20', HolidayCalendar::easter(2025)->toDateString());
        $this->assertSame('2027-03-28', HolidayCalendar::easter(2027)->toDateString());

        $holidays = app(HolidayCalendar::class)->national(2026);
        $this->assertCount(10, $holidays); // 9 fixed + Good Friday (on by default)
        $this->assertSame(__('timeclock.holidays.optional.good_friday'), $holidays['2026-04-03']);
        $this->assertArrayHasKey('2026-11-20', $holidays);
        $this->assertArrayNotHasKey('2023-11-20', app(HolidayCalendar::class)->national(2023)); // national since 2024
        $this->assertArrayNotHasKey('2026-02-17', $holidays); // Carnival off by default

        // The company turns Carnival and Corpus Christi on.
        TimeClockSetting::current()->update(['optional_holidays' => ['carnival' => true, 'corpus_christi' => true]]);
        $holidays = app(HolidayCalendar::class)->national(2026);
        $this->assertArrayHasKey('2026-02-16', $holidays);
        $this->assertArrayHasKey('2026-02-17', $holidays);
        $this->assertArrayHasKey('2026-06-04', $holidays);
        $this->assertArrayHasKey('2026-04-03', $holidays); // Good Friday stays on

        // City holiday only for one workplace, every year; and a one-off company day.
        $branch = Workplace::create(['name' => 'Filial', 'timezone' => 'America/Sao_Paulo']);
        Holiday::create(['date' => '2020-01-25', 'name' => 'Aniversário de São Paulo', 'recurring' => true, 'workplace_id' => $branch->id]);
        Holiday::create(['date' => '2026-12-24', 'name' => 'Véspera de Natal', 'recurring' => false]);
        $calendar = app(HolidayCalendar::class);
        $this->assertSame('Aniversário de São Paulo', $calendar->holidayOn(CarbonImmutable::parse('2027-01-25'), $branch->id));
        $this->assertNull($calendar->holidayOn(CarbonImmutable::parse('2027-01-25'), $this->office->id));
        $this->assertSame('Véspera de Natal', $calendar->holidayOn(CarbonImmutable::parse('2026-12-24'), $this->office->id));
        $this->assertNull($calendar->holidayOn(CarbonImmutable::parse('2027-12-24')));

        Livewire::test(ManageHolidays::class)->assertCanSeeTableRecords(Holiday::all());
        Livewire::test(NationalHolidaysWidget::class)->assertSee(__('timeclock.holidays.national.tiradentes'))->call('nextYear')->assertSee('2027');
    }

    public function test_city_holidays_follow_the_workplace_city_or_the_employee_city(): void
    {
        // A clean HTTP client: the Core fake of the tests would answer everything first.
        Http::swap(new HttpFactory);
        Http::fake([
            'servicodados.ibge.gov.br/*' => Http::response([['id' => 3550308, 'nome' => 'São Paulo', 'microrregiao' => ['mesorregiao' => ['UF' => ['sigla' => 'SP']]]]]),
            'raw.githubusercontent.com/*' => Http::response([
                ['data' => '25/01/2026', 'nome' => 'Aniversário da Cidade', 'tipo' => 'MUNICIPAL', 'uf' => 'SP', 'codigo_ibge' => 3550308],
                ['data' => '10/05/2026', 'nome' => 'Outra cidade', 'tipo' => 'MUNICIPAL', 'uf' => 'SP', 'codigo_ibge' => 3509502],
            ]),
        ]);
        Cache::flush();

        $service = app(CityHolidays::class);
        $this->assertSame([3550308 => 'São Paulo - SP'], $service->search('sao pau'));

        $sp = City::create(['name' => 'São Paulo', 'state' => 'SP', 'ibge_code' => 3550308]);
        $campinas = City::create(['name' => 'Campinas', 'state' => 'SP', 'ibge_code' => 3509502]);
        // A city registered with its IBGE code already brings its holidays; the same day in the year before too
        // (the fake answers the same list for every year) makes it "every year". Asking again does not duplicate.
        $this->assertSame(['Aniversário da Cidade'], $sp->holidays()->pluck('name')->all());
        $this->assertTrue($sp->holidays()->first()->recurring);
        $this->assertSame(0, $service->import($sp, 2026));

        $branch = Workplace::create(['name' => 'Filial SP', 'timezone' => 'America/Sao_Paulo', 'city_id' => $sp->id]);
        $calendar = app(HolidayCalendar::class);
        $day = CarbonImmutable::parse('2026-01-25');

        // The workplace city counts; another workplace and a company-wide view do not have it.
        $this->assertSame('Aniversário da Cidade', $calendar->holidayOn($day, $branch->id));
        $this->assertNull($calendar->holidayOn($day, $this->office->id));
        $this->assertNull($calendar->holidayOn($day));
        // An employee that follows another city (home office) has that city's days, not the workplace's.
        $this->assertNull($calendar->holidayOn($day, $branch->id, $campinas->id));
        $this->assertSame('Aniversário da Cidade', $calendar->holidayOn($day, $this->office->id, $sp->id));

        // Without internet nothing is added and nothing breaks.
        Cache::flush();
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response('', 500)]);
        $this->assertSame([], $service->suggestions($campinas, 2026));
    }

    public function test_holidays_of_a_country_outside_brazil_come_as_a_suggestion(): void
    {
        TimeClockSetting::current()->update(['country' => 'PT']);
        Http::swap(new HttpFactory);
        Http::fake(['date.nager.at/*' => Http::response([
            ['date' => '2026-12-25', 'localName' => 'Natal', 'name' => 'Christmas Day', 'global' => true],
            ['date' => '2026-06-24', 'localName' => 'São João', 'name' => 'Saint John', 'global' => false],
        ])]);

        $service = app(CountryHolidays::class);
        $this->assertSame(['2026-12-25' => 'Natal'], $service->suggestions('PT', 2026));
        $this->assertSame(1, $service->import('PT', 2026));
        $this->assertSame(0, $service->import('PT', 2026)); // does not duplicate
        $this->assertSame('Natal', app(HolidayCalendar::class)->holidayOn(CarbonImmutable::parse('2026-12-25')));

        Livewire::test(ManageHolidays::class)->assertActionVisible('countryHolidays');

        // Without internet nothing breaks.
        Http::swap(new HttpFactory);
        Http::fake(['*' => Http::response('', 500)]);
        $this->assertSame([], $service->suggestions('PT', 2027));
    }

    public function test_the_holiday_city_comes_from_the_typed_city_or_the_ibge_list(): void
    {
        Http::swap(new HttpFactory);
        Http::fake(['servicodados.ibge.gov.br/*' => Http::response([
            ['id' => 3169406, 'nome' => 'Três Pontas', 'microrregiao' => ['mesorregiao' => ['UF' => ['sigla' => 'MG']]]],
            ['id' => 3550308, 'nome' => 'São Paulo', 'microrregiao' => ['mesorregiao' => ['UF' => ['sigla' => 'SP']]]],
        ])]);
        Cache::flush();

        // Nothing chosen: the city typed on the workplace (any case, no accents) becomes its holiday city.
        $matriz = Workplace::create(['name' => 'Matriz', 'timezone' => 'America/Sao_Paulo', 'city' => 'tres pontas', 'state' => 'mg']);
        $this->assertSame(['Três Pontas', 'MG', 3169406], [$matriz->holidayCity->name, $matriz->holidayCity->state, $matriz->holidayCity->ibge_code]);

        // The same city is not registered twice; a city picked from the IBGE list is registered on save.
        $loja = Workplace::create(['name' => 'Loja', 'timezone' => 'America/Sao_Paulo', 'city' => 'Três Pontas', 'state' => 'MG']);
        $this->assertSame($matriz->city_id, $loja->city_id);
        $filial = Workplace::create(['name' => 'Filial', 'timezone' => 'America/Sao_Paulo', 'city_id' => 'ibge:3550308']);
        $this->assertSame('São Paulo', $filial->holidayCity->name);
        $this->assertSame(2, City::query()->count());

        // A city outside the list (or without internet) is kept with the typed name, to register holidays by hand.
        $fora = Workplace::create(['name' => 'Lisboa', 'timezone' => 'Europe/Lisbon', 'city' => 'Lisboa']);
        $this->assertSame(['Lisboa', null], [$fora->holidayCity->name, $fora->holidayCity->ibge_code]);
    }

    public function test_calculation_rules_follow_the_profile(): void
    {
        // Brazil (CLT): never below the law.
        Livewire::test(TimeClockSettingsPage::class)
            ->assertSet('data.overtime_percent', 50)
            ->fillForm(['labor_profile' => 'br_clt', 'overtime_percent' => 40, 'tolerance_punch_minutes' => 8])
            ->call('save')
            ->assertHasFormErrors(['overtime_percent', 'tolerance_punch_minutes']);

        Livewire::test(TimeClockSettingsPage::class)
            ->fillForm(['labor_profile' => 'br_clt', 'overtime_percent' => 60, 'hour_bank' => true, 'hour_bank_months' => 12, 'optional_holidays' => ['carnival_tuesday' => true]])
            ->call('save')
            ->assertHasNoFormErrors();
        $settings = TimeClockSetting::current()->fresh();
        $this->assertSame(60, $settings->overtime_percent);
        $this->assertSame('22:00', $settings->night_start);
        $this->assertTrue($settings->night_reduced_hour);
        $this->assertTrue($settings->hour_bank);
        // Each Carnival day is chosen apart (some places stop only on Tuesday).
        $this->assertTrue(app(HolidayCalendar::class)->optionalIsOn('carnival_tuesday', $settings));
        $this->assertFalse(app(HolidayCalendar::class)->optionalIsOn('carnival_monday', $settings));

        // Another country: the company states its rules.
        $other = $this->tenant('madrid', ['ponto' => []], ['country' => 'ES']);
        $this->signIn($other);
        $this->assertFalse(TimeClockSetting::current()->night_reduced_hour);
        $this->assertSame([], app(HolidayCalendar::class)->national(2026));
        Livewire::test(TimeClockSettingsPage::class)
            ->fillForm(['labor_profile' => 'custom', 'overtime_percent' => 25, 'night_start' => '22:00', 'night_end' => '06:00', 'night_percent' => 25, 'tolerance_punch_minutes' => 10])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame(25, TimeClockSetting::current()->fresh()->overtime_percent);
        $this->assertSame('06:00', TimeClockSetting::current()->fresh()->night_end);
    }

    public function test_an_employee_needs_a_schedule(): void
    {
        $this->preset('office');

        Livewire::test(PeoplePage::class)
            ->callAction('add', [
                'name' => 'Sem horário', 'with_login' => false, 'document' => '529.982.247-25',
                'modules' => ['ponto' => ['clocks_in' => true, 'admitted_on' => '2026-10-01', 'workplace_id' => $this->office->id]],
            ])
            ->assertHasActionErrors(['modules.ponto.schedule_id']);
        $this->assertSame(0, Employee::count());
    }
}
