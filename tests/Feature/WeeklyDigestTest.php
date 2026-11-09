<?php

namespace Tests\Feature;

use App\Digest\WeeklyDigest;
use App\Filament\Pages\WeeklyDigestPage;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Schedule;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ScheduleService;
use App\People\PeopleService;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The weekly summary: every Monday at 7 h the owner gets all the systems' week together in the bell and, through
 * the Core, by e-mail (unless the company turned it off), once per week; the page shows any past week.
 */
class WeeklyDigestTest extends TestCase
{
    use RefreshDatabase;

    protected const TZ = 'America/Sao_Paulo';

    protected Tenant $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('instaladora', ['ponto' => [], 'frota' => []]);
        $this->owner = $this->signIn($this->company);
        FilamentTimezone::set(self::TZ);
        $this->travelTo(CarbonImmutable::parse('2026-11-02 07:00', self::TZ));
        $office = Workplace::create(['name' => 'Escritório', 'timezone' => self::TZ]);
        $schedule = Schedule::create(['name' => 'office'] + ScheduleService::presets()['office'] + ['anchor_date' => '2026-11-02']);
        $person = app(PeopleService::class)->add($this->company, ['name' => 'Rui', 'phone' => '11977776666', 'document' => '529.982.247-25'], ['ponto' => [
            'clocks_in' => true, 'admitted_on' => '2026-11-01', 'workplace_id' => $office->id, 'schedule_id' => $schedule->id,
        ]], $this->owner, false)['person'];
        $rui = Employee::query()->where('person_id', $person->id)->sole();

        // Week of Nov 2: Monday is a holiday; Tuesday until 20:00 (2 h of overtime); the rest without punches.
        foreach (['2026-11-03 08:00', '2026-11-03 12:00', '2026-11-03 13:00', '2026-11-03 20:00'] as $moment) {
            $this->travelTo(CarbonImmutable::parse($moment, self::TZ));
            app(PunchService::class)->punch($rui, ['photo_path' => 'x.jpg']);
        }
    }

    /** A license valid at the traveled time (it lasts 72 hours). */
    protected function freshLicense(): void
    {
        $this->company->forceFill(['license_token' => $this->licenseToken($this->company, ['ponto' => [], 'frota' => []]), 'license_fetched_at' => now(), 'license_expires_at' => now()->addHours(72)])->save();
        $this->company = $this->company->fresh();
    }

    public function test_monday_morning_once(): void
    {
        $digest = app(WeeklyDigest::class);
        $this->travelTo(CarbonImmutable::parse('2026-11-08 20:00', self::TZ));
        $this->freshLicense();

        // Sunday and Monday before 7 h: not yet.
        $this->assertFalse($digest->sendDue($this->company, CarbonImmutable::parse('2026-11-08 20:00', self::TZ)));
        $this->assertFalse($digest->sendDue($this->company, CarbonImmutable::parse('2026-11-09 06:00', self::TZ)));

        $this->travelTo(CarbonImmutable::parse('2026-11-09 07:10', self::TZ));
        $this->assertTrue($digest->sendDue($this->company, CarbonImmutable::now()));
        $this->assertFalse($digest->sendDue($this->company, CarbonImmutable::now()->addHour()));

        $this->assertSame(1, $this->owner->notifications()->count());
        Http::assertSent(function (Request $request): bool {
            if (! str_ends_with($request->url(), '/api/v1/tenants/instaladora/digest')) {
                return false;
            }

            $ponto = collect($request['sections'])->firstWhere('name', 'Ponto');

            return $request['week_start'] === '2026-11-02'
                && $ponto !== null
                && in_array(trans_choice('digest.timeclock.extra', 1, ['hours' => '2:00', 'count' => 1]), $ponto['lines'], true)
                && in_array(trans_choice('digest.timeclock.absences', 3, ['count' => 3]), $ponto['lines'], true);
        });
    }

    public function test_the_e_mail_can_be_turned_off(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 08:00', self::TZ));
        $this->freshLicense();
        $this->company->forceFill(['weekly_digest_email' => false])->save();

        $this->assertTrue(app(WeeklyDigest::class)->sendDue($this->company->fresh(), CarbonImmutable::now()));
        $this->assertSame(1, $this->owner->notifications()->count());
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/digest'));
    }

    public function test_the_page(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-09 10:00', self::TZ));
        $this->freshLicense();
        $this->signIn($this->company);

        Livewire::test(WeeklyDigestPage::class)
            ->assertSee(trans_choice('digest.timeclock.extra', 1, ['hours' => '2:00', 'count' => 1]))
            ->assertSee(__('digest.fleet.no_fuel'))
            ->callAction('email');
        $this->assertFalse($this->company->fresh()->weekly_digest_email);
    }
}
