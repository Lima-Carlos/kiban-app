<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Filament\ClosingsPage;
use App\Modules\TimeClock\Filament\TimeClockSettingsPage;
use App\Modules\TimeClock\Models\Closing;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\TimeClockSetting;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\KioskService;
use App\Modules\TimeClock\Services\MonthClosing;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\Modules\TimeClock\Services\TimesheetDocument;
use App\People\PeopleService;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Livewire\Livewire;
use Tests\TestCase;
use ZipArchive;

/**
 * Closing of the month, round 2: all time sheets in one .zip, the link to sign without a login (PIN or the last
 * 4 digits of the document), reminding who has not signed, recalculating, the actions on the selected people and
 * the month closed by itself on a chosen day.
 */
class TimeClockClosingRoundTwoTest extends TestCase
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
        // The license lasts 72 hours: a fresh one at the new time (as the system renews it every 5 minutes).
        $this->company->forceFill(['license_token' => $this->licenseToken($this->company, ['ponto' => []]), 'license_fetched_at' => now()])->save();
        $this->company->forgetLicense();
    }

    protected function closedMonth(): array
    {
        $rui = $this->employee(withLogin: true);
        $ana = $this->employee(cpf: '111.444.777-35', name: 'Ana');
        $this->punch($rui, '2026-11-03 08:00', '2026-11-03 12:00', '2026-11-03 13:00', '2026-11-03 18:30');
        $this->at('2026-12-01 13:00');
        app(MonthClosing::class)->closeAll(CarbonImmutable::parse('2026-11-01'), $this->owner);

        return [$rui, $ana];
    }

    public function test_all_time_sheets_in_one_zip(): void
    {
        [$rui, $ana] = $this->closedMonth();

        $path = app(TimesheetDocument::class)->zip([$ana, $rui], CarbonImmutable::parse('2026-11-01'));
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true);
        $this->assertSame(2, $zip->numFiles);
        $this->assertStringStartsWith('%PDF', (string) $zip->getFromIndex(0));
        $zip->close();
        @unlink($path);

        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])->callAction('zip')->assertFileDownloaded(app(TimesheetDocument::class)->zipName(CarbonImmutable::parse('2026-11-01')));
    }

    public function test_signing_by_the_link_with_the_document(): void
    {
        [, $ana] = $this->closedMonth();
        $closing = Closing::query()->where('employee_id', $ana->id)->sole();
        $this->assertSame(48, strlen((string) $closing->sign_token));
        $this->assertSame('document', app(MonthClosing::class)->identityCheck($closing));
        $url = app(MonthClosing::class)->signUrl($closing);
        $this->assertStringContainsString('wa.me/', (string) app(MonthClosing::class)->whatsappUrl($closing));

        Auth::logout();
        $this->get($url)->assertOk()->assertSee('Ana')->assertSee(__('timeclock.sign_link.identity_document'));
        $this->get($url.'/pdf')->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // Wrong digits: refused.
        $this->post($url, ['answer' => 'sign', 'agree' => '1', 'identity' => '0000'])->assertSessionHasErrors('identity');
        $this->assertNull($closing->fresh()->signed_at);

        // The last 4 digits of the CPF 111.444.777-35.
        $this->post($url, ['answer' => 'sign', 'agree' => '1', 'identity' => '7735'])->assertRedirect($url);
        $closing->refresh();
        $this->assertSame(Closing::SIGNED, $closing->status());
        $this->assertSame('link', $closing->signature_method);
        $this->assertNull($closing->signed_by);
        $this->assertSame(64, strlen((string) $closing->signature_hash));
        $this->get($url)->assertOk()->assertDontSee('name="identity"', false);

        // A changed or unknown link does not open.
        $this->get(substr($url, 0, -1).'x')->assertNotFound();
    }

    public function test_disagreeing_by_the_link_with_the_pin_warns_the_managers(): void
    {
        [, $ana] = $this->closedMonth();
        $pin = app(KioskService::class)->newPin($ana);
        $closing = Closing::query()->where('employee_id', $ana->id)->sole();
        $this->assertSame('pin', app(MonthClosing::class)->identityCheck($closing));
        $url = app(MonthClosing::class)->signUrl($closing);
        $before = $this->owner->notifications()->count();

        Auth::logout();
        $this->post($url, ['answer' => 'dispute', 'identity' => '7735', 'reason' => 'Faltou o dia 10'])->assertSessionHasErrors('identity');
        $this->post($url, ['answer' => 'dispute', 'identity' => $pin, 'reason' => 'Faltou o dia 10'])->assertRedirect($url);

        $closing->refresh();
        $this->assertSame(Closing::DISPUTED, $closing->status());
        $this->assertSame('Faltou o dia 10', $closing->dispute_reason);
        $this->assertSame($before + 1, $this->owner->notifications()->count());
    }

    public function test_too_many_wrong_tries_lock_the_link(): void
    {
        [, $ana] = $this->closedMonth();
        $closing = Closing::query()->where('employee_id', $ana->id)->sole();
        $url = app(MonthClosing::class)->signUrl($closing);
        Auth::logout();

        foreach (range(1, 5) as $try) {
            $this->post($url, ['answer' => 'sign', 'agree' => '1', 'identity' => '0000']);
        }

        $this->post($url, ['answer' => 'sign', 'agree' => '1', 'identity' => '7735'])->assertSessionHasErrors(['identity' => __('timeclock.sign_link.too_many')]);
        $this->assertNull($closing->fresh()->signed_at);
    }

    public function test_remind_who_has_not_signed(): void
    {
        [$rui, $ana] = $this->closedMonth();
        $before = $rui->person->user->notifications()->count();

        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])
            ->callAction('remind')
            ->assertNotified(trans_choice('timeclock.closing.reminded', 1, ['count' => 1]));

        $this->assertSame($before + 1, $rui->person->user->notifications()->count());
        $this->assertNotNull(Closing::query()->where('employee_id', $ana->id)->sole()->reminded_at);
    }

    public function test_recalculate_closes_again_with_the_rules_of_now(): void
    {
        [$rui, $ana] = $this->closedMonth();
        $first = Closing::query()->where('employee_id', $rui->id)->sole();
        $this->assertSame(30, $first->totals['extra']);

        // Ana signs; the tolerance changes; recalculating only touches who has not signed.
        app(MonthClosing::class)->signByLink(Closing::query()->where('employee_id', $ana->id)->sole(), '10.0.0.1', 'test');
        TimeClockSetting::current()->forceFill(['labor_profile' => 'custom', 'tolerance_punch_minutes' => 0, 'tolerance_day_minutes' => 0])->save();

        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])
            ->callAction('recalculateAll')
            ->assertNotified(trans_choice('timeclock.closing.recalculated_count', 1, ['count' => 1]));

        $this->assertNotNull($first->fresh()->reopened_at);
        $this->assertSame(__('timeclock.closing.recalculate_reason'), $first->fresh()->reopen_reason);
        $this->assertSame(2, Closing::query()->where('employee_id', $rui->id)->count());
        $this->assertSame(1, Closing::query()->where('employee_id', $ana->id)->count());
        $this->assertNotNull(app(MonthClosing::class)->current($rui, CarbonImmutable::parse('2026-11-01')));
    }

    public function test_actions_on_the_selected_people(): void
    {
        $rui = $this->employee(withLogin: true);
        $ana = $this->employee(cpf: '111.444.777-35', name: 'Ana');
        $this->at('2026-12-01 13:00');

        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])
            ->callTableBulkAction('closeSelected', [$ana])
            ->assertNotified(trans_choice('timeclock.closing.closed_count', 1, ['count' => 1]));
        $this->assertTrue(app(MonthClosing::class)->isClosed($ana, CarbonImmutable::parse('2026-11-01')));
        $this->assertFalse(app(MonthClosing::class)->isClosed($rui, CarbonImmutable::parse('2026-11-01')));

        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])
            ->filterTable('situation', Closing::OPEN)
            ->assertCanSeeTableRecords([$rui])
            ->assertCanNotSeeTableRecords([$ana]);

        Livewire::test(ClosingsPage::class, ['month' => '2026-11'])
            ->callTableBulkAction('reopenSelected', [$ana, $rui], ['reason' => 'Conferir'])
            ->assertNotified(trans_choice('timeclock.closing.reopened_count', 1, ['count' => 1]));
        $this->assertFalse(app(MonthClosing::class)->isClosed($ana, CarbonImmutable::parse('2026-11-01')));
    }

    public function test_the_month_closes_by_itself_on_the_chosen_day(): void
    {
        $rui = $this->employee(withLogin: true);
        $this->at('2026-11-20 10:00');

        Livewire::test(TimeClockSettingsPage::class)->fillForm(['auto_close_day' => 3])->call('save')->assertHasNoFormErrors();
        $this->assertSame(3, TimeClockSetting::current()->auto_close_day);

        // Before the day: nothing.
        $this->at('2026-12-02 10:00');
        $this->assertNull(app(MonthClosing::class)->autoClose(CarbonImmutable::now(self::TZ)));
        $this->assertSame(0, Closing::count());

        // On the day: closed once, the managers hear about it; the next hours do nothing.
        $this->at('2026-12-03 06:00');
        $before = $this->owner->notifications()->count();
        $this->artisan('kiban:timeclock')->assertSuccessful();
        $closing = Closing::sole();
        $this->assertNull($closing->closed_by);
        $this->assertSame($rui->id, $closing->employee_id);
        $this->assertGreaterThan($before, $this->owner->notifications()->count());

        app(MonthClosing::class)->reopen($closing, $this->owner, 'Conferir');
        $this->at('2026-12-03 07:00');
        $this->assertNull(app(MonthClosing::class)->autoClose(CarbonImmutable::now(self::TZ)));
        $this->assertSame(0, Closing::query()->current()->count());
    }
}
