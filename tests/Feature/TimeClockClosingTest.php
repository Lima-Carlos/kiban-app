<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Filament\ClosingsPage;
use App\Modules\TimeClock\Filament\TimesheetPage;
use App\Modules\TimeClock\Models\Adjustment;
use App\Modules\TimeClock\Models\Closing;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\AdjustmentService;
use App\Modules\TimeClock\Services\AfdFile;
use App\Modules\TimeClock\Services\MonthClosing;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\Modules\TimeClock\Services\TimeClockCleanup;
use App\Modules\TimeClock\Services\TimeClockExporter;
use App\Modules\TimeClock\Services\TimeClockNotices;
use App\Modules\TimeClock\Services\TimesheetDocument;
use App\People\PeopleService;
use App\People\PersonRuleException;
use App\Rules\Cnpj;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Kiban Core Ponto, part 5: the closing of the month (frozen copy, no more corrections), the employee's signature or
 * disagreement, reopening only before the signature, the time sheet PDF, the spreadsheet for the accountant,
 * the AFD file (preview), the warnings and the removal of old punch photos.
 */
class TimeClockClosingTest extends TestCase
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
        // The company's time zone (set from the license on each request and in the hourly routine).
        FilamentTimezone::set(self::TZ);
    }

    protected function employee(bool $withLogin = false, string $cpf = '529.982.247-25', string $name = 'Rui'): Employee
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

    protected function at(string $moment): void
    {
        $this->travelTo(CarbonImmutable::parse($moment, self::TZ));
    }

    public function test_closing_freezes_the_month(): void
    {
        $rui = $this->employee(withLogin: true);
        $this->punch($rui, '2026-11-03 08:00', '2026-11-03 12:00', '2026-11-03 13:00', '2026-11-03 18:30');
        $month = CarbonImmutable::parse('2026-11-01');

        // Not before the month is over (the night shift of the 30th ends on the 1st, until noon).
        $this->at('2026-12-01 11:00');
        $this->assertSame(__('timeclock.closing.errors.not_over'), app(MonthClosing::class)->blocker($rui, $month));

        // A correction waiting for an answer holds the closing.
        $this->at('2026-12-01 13:00');
        $request = app(AdjustmentService::class)->request($rui, ['date' => '2026-11-04', 'type' => 'excuse', 'whole_day' => true, 'reason' => 'Atestado'], $rui->person->user);
        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])
            ->callTableAction('close', $rui)
            ->assertNotified(trans_choice('timeclock.closing.errors.pending', 1, ['count' => 1]));
        $this->assertSame(0, Closing::count());

        app(AdjustmentService::class)->approve($request, $this->owner);
        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])->callTableAction('close', $rui)->assertNotified(__('timeclock.closing.closed'));

        $closing = Closing::sole();
        $this->assertTrue(app(MonthClosing::class)->intact($closing));
        $this->assertSame(30, $closing->totals['extra']);
        $this->assertSame(540, $closing->totals['excused']);
        $this->assertSame(Closing::CLOSED, $closing->status());
        $this->assertSame(2, $rui->person->user->notifications()->count()); // approved + "ready to sign"

        // No more corrections in that month.
        try {
            app(AdjustmentService::class)->record($rui, ['date' => '2026-11-05', 'type' => 'excuse', 'whole_day' => true, 'reason' => 'x'], $this->owner);
            $this->fail('A closed month must not change.');
        } catch (PersonRuleException $exception) {
            $this->assertSame(__('timeclock.errors.month_closed'), $exception->getMessage());
        }

        // Changing the rules later does not change the closed month (the screen shows the frozen copy).
        TimeClockSetting::current()->update(['labor_profile' => 'custom', 'overtime_percent' => 80, 'tolerance_punch_minutes' => 0, 'tolerance_day_minutes' => 0]);
        $this->assertSame(30, app(TimesheetDocument::class)->month($rui, $month)['totals']['extra']);
        $this->assertSame('2026-11-03', array_keys(array_filter(app(TimesheetDocument::class)->month($rui, $month)['days'], fn (array $day): bool => $day['punches'] !== []))[0]);

        // A copy edited in the database would show.
        $closing->forceFill(['totals' => ['extra' => 999] + $closing->totals])->save();
        $this->assertFalse(app(MonthClosing::class)->intact($closing->fresh()));
    }

    public function test_sign_disagree_and_reopen(): void
    {
        $rui = $this->employee(withLogin: true);
        $ana = $this->employee(cpf: '111.444.777-35', name: 'Ana');
        $this->punch($rui, '2026-11-03 08:00', '2026-11-03 12:00', '2026-11-03 13:00', '2026-11-03 18:00');
        $this->at('2026-12-02 09:00');

        $result = app(MonthClosing::class)->closeAll(CarbonImmutable::parse('2026-11-01'), $this->owner);
        $this->assertSame(['closed' => 2, 'skipped' => []], $result);

        // The employee does not agree: the manager hears about it.
        $user = $rui->person->user;
        $this->actingAs($user);
        $page = Livewire::test(TimesheetPage::class, ['month' => '2026-11'])
            ->assertActionVisible('sign')
            ->assertActionHidden('adjust')
            ->assertSee(__('timeclock.closing.waiting_you'));
        $page->callAction('dispute', ['reason' => 'Faltou a hora extra do dia 10'])->assertHasNoActionErrors();
        $this->assertSame(Closing::DISPUTED, Closing::query()->where('employee_id', $rui->id)->sole()->status());
        $this->assertSame(1, $this->owner->notifications()->count());

        // Nobody else signs someone's time sheet.
        try {
            app(MonthClosing::class)->sign(Closing::query()->where('employee_id', $ana->id)->sole(), $user, null, null);
            $this->fail('Only the employee signs.');
        } catch (PersonRuleException) {
        }

        // The manager reopens (with a reason), corrects and closes again; the old closing stays in the history.
        $this->actingAs($this->owner);
        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])
            ->callTableAction('reopen', $rui, ['reason' => 'Vou incluir a hora extra'])
            ->assertNotified(__('timeclock.closing.reopened'));
        app(AdjustmentService::class)->record($rui, ['date' => '2026-11-10', 'type' => 'add_punch', 'time' => '19:00', 'reason' => 'Hora extra'], $this->owner);
        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])->callTableAction('close', $rui);
        $this->assertSame(2, Closing::query()->where('employee_id', $rui->id)->count());

        // The employee signs: who, when, where; from then on it cannot be reopened.
        $this->actingAs($user);
        Livewire::test(TimesheetPage::class, ['month' => '2026-11'])
            ->callAction('sign', ['agree' => true])
            ->assertHasNoActionErrors()
            ->assertNotified(__('timeclock.closing.signed'));
        $signed = Closing::query()->current()->where('employee_id', $rui->id)->sole();
        $this->assertSame(Closing::SIGNED, $signed->status());
        $this->assertSame($user->id, $signed->signed_by);
        $this->assertSame(64, strlen((string) $signed->signature_hash));

        $this->actingAs($this->owner);
        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])->assertTableActionHidden('reopen', $rui)->assertTableActionVisible('reopen', $ana);
        $this->expectException(PersonRuleException::class);
        app(MonthClosing::class)->reopen($signed, $this->owner, 'x');
    }

    public function test_pdf_spreadsheet_and_afd(): void
    {
        $rui = $this->employee();
        $this->employee(cpf: '111.444.777-35', name: 'Ana');
        $this->punch($rui, '2026-10-31 08:00', '2026-11-03 08:00', '2026-11-03 12:00', '2026-11-03 13:00', '2026-11-03 18:00');
        $this->at('2026-12-01 13:00');
        $month = CarbonImmutable::parse('2026-11-01');
        app(MonthClosing::class)->close($rui, $month, $this->owner);

        // Time sheet PDF (closed and preview).
        $this->assertStringStartsWith('%PDF', app(TimesheetDocument::class)->pdf($rui, $month));
        $this->assertStringStartsWith('%PDF', app(TimesheetDocument::class)->pdf($rui, CarbonImmutable::parse('2026-12-01')));

        // Spreadsheet: the month summary and every day.
        $path = app(TimeClockExporter::class)->month($month);
        $reader = new Reader;
        $reader->open($path);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $sheets[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();
        unlink($path);
        $summary = $sheets[__('timeclock.export.summary')];
        $this->assertCount(3, $summary);
        $this->assertSame(['Ana', 'Rui'], [$summary[1][0], $summary[2][0]]);
        $this->assertSame(__('timeclock.closing.statuses.closed'), $summary[2][6]);
        $this->assertSame(__('timeclock.closing.statuses.open'), $summary[1][6]);
        $this->assertGreaterThan(30, count($sheets[__('timeclock.export.days')]));

        // AFD (preview): what is missing, then header, one record per punch of the period, the trailer.
        $from = $month;
        $to = $month->endOfMonth();
        $this->assertContains(__('timeclock.afd.missing_employer'), app(AfdFile::class)->warnings($from, $to));
        TimeClockSetting::current()->forceFill(['employer_document_type' => 'cnpj', 'employer_document' => '11222333000181', 'employer_name' => 'Instaladora Ltda'])->save();
        $this->assertNotContains(__('timeclock.afd.missing_employer'), app(AfdFile::class)->warnings($from, $to));

        $lines = explode("\r\n", trim(app(AfdFile::class)->contents($from, $to)));
        $this->assertSame(298, strlen($lines[0]));
        $this->assertStringStartsWith('000000000'.'1'.'1'.'11222333000181', $lines[0]);
        $records = array_values(array_filter($lines, fn (string $line): bool => strlen($line) === 137 && $line[9] === '7'));
        $this->assertCount(4, $records); // the punch of October stays out
        $this->assertSame('000000002', substr($records[0], 0, 9));
        $this->assertSame('2026-11-03T08:00:00-0300', substr($records[0], 10, 24));
        $this->assertSame('052998224725', substr($records[0], 34, 12));
        $this->assertSame('999999999'.str_repeat('0', 45).'000000004'.'9', $lines[count($lines) - 2]);
        $this->assertSame('ASSINATURA_DIGITAL_EM_ARQUIVO_P7S', end($lines));
        // The chain: each record carries the SHA-256 of itself plus the previous hash.
        $this->assertSame(hash('sha256', substr($records[1], 0, 73).substr($records[0], 73)), substr($records[1], 73));

        $this->assertTrue(Cnpj::isValid('11.222.333/0001-81'));
        $this->assertFalse(Cnpj::isValid('11.222.333/0001-82'));
    }

    public function test_warnings_attention_and_old_photos(): void
    {
        Storage::fake('local');
        $rui = $this->employee(withLogin: true);
        $user = $rui->person->user;

        // Yesterday a punch was forgotten: the employee and the managers hear about it once, after noon.
        $this->punch($rui, '2026-11-03 08:00', '2026-11-03 12:00', '2026-11-03 13:00');
        $this->at('2026-11-04 11:00');
        app(TimeClockNotices::class)->scan();
        $this->assertSame(0, $user->notifications()->count());
        $this->at('2026-11-04 13:00');
        app(TimeClockNotices::class)->scan();
        app(TimeClockNotices::class)->scan();
        $this->assertSame(1, $user->notifications()->count());
        // The manager: yesterday's summary, and October was not closed (from the 3rd on).
        $this->assertSame(2, $this->owner->notifications()->count());

        $attention = collect(app(TimeClockNotices::class)->attention($user))->pluck('text');
        $this->assertContains(__('timeclock.attention.missing_punch', ['date' => CarbonImmutable::parse('2026-11-03')->translatedFormat(__('app.date_format'))]), $attention);

        // The month not closed (from the 3rd on) and a correction waiting: the manager's "Attention".
        app(AdjustmentService::class)->request($rui, ['date' => '2026-11-03', 'type' => 'add_punch', 'time' => '18:00', 'reason' => 'Esqueci'], $user);
        $this->at('2026-12-03 09:00');
        $items = collect(app(TimeClockNotices::class)->attention($this->owner))->pluck('text');
        $this->assertContains(trans_choice('timeclock.attention.pending', 1, ['count' => 1]), $items);
        $this->assertTrue($items->contains(fn (string $text): bool => str_contains($text, app(MonthClosing::class)->monthLabel(CarbonImmutable::parse('2026-11-01')))));

        // Closed: the employee is asked to sign.
        app(AdjustmentService::class)->approve(Adjustment::sole(), $this->owner);
        app(MonthClosing::class)->close($rui, CarbonImmutable::parse('2026-11-01'), $this->owner);
        $this->assertContains(__('timeclock.attention.sign', ['month' => app(MonthClosing::class)->monthLabel(CarbonImmutable::parse('2026-11-01'))]), collect(app(TimeClockNotices::class)->attention($user))->pluck('text'));

        // Photos older than 12 months go away; the punch stays.
        $punch = Punch::query()->orderBy('nsr')->first();
        Storage::disk('local')->put('x.jpg', 'photo');
        $this->at('2027-11-04 13:00');
        $this->assertSame(3, app(TimeClockCleanup::class)->run());
        $this->assertNull($punch->fresh()->photo_path);
        $this->assertSame(3, Punch::count());
        Storage::disk('local')->assertMissing('x.jpg');

        // The hourly routine runs the same for every company with the Ponto.
        $this->travelBack();
        $this->artisan('kiban:timeclock')->assertSuccessful();
    }

    public function test_who_sees_the_closing_screen(): void
    {
        $rui = $this->employee(withLogin: true);
        $this->at('2026-12-01 13:00');

        $this->actingAs($rui->person->user);
        $this->get($this->company->url('/ponto/fechamento'))->assertForbidden();

        $viewer = User::factory()->memberOf($this->company)->create();
        app(AccessManager::class)->set($viewer, 'ponto', 'viewer');
        $this->actingAs($viewer);
        $this->get($this->company->url('/ponto/fechamento'))->assertOk();
        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])
            ->assertTableActionHidden('close', $rui)
            ->assertActionHidden('closeAll')
            ->assertActionVisible('spreadsheet');

        $this->actingAs($this->owner);
        $this->get($this->company->url('/ponto/fechamento'))->assertOk()->assertSee('Rui');
        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])->assertActionVisible('closeAll')->assertActionVisible('afd');
        // The current month cannot be closed yet.
        Livewire::test(ClosingsPage::class, ['month' => '2026-12'])->assertActionHidden('closeAll')->assertSee(__('timeclock.closing.not_over'));
    }
}
