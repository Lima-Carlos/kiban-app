<?php

namespace Tests\Feature;

use App\Licensing\TrialBanner;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * "Free trial: N days left · See plans": only while every system is in trial, only for who manages the
 * company, days counted in the company's time zone, stronger colour in the last days.
 */
class TrialBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // 30/10 at 10:00 in São Paulo.
        $this->travelTo(CarbonImmutable::parse('2026-10-30 10:00', 'America/Sao_Paulo'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function trial(string $endsAt): array
    {
        return ['status' => 'trial', 'trial_ends_at' => CarbonImmutable::parse($endsAt, 'America/Sao_Paulo')->toIso8601String()];
    }

    public function test_the_owner_sees_the_days_left_and_the_plans_link(): void
    {
        $company = $this->tenant('aurora', ['frota' => $this->trial('2026-11-13 23:59'), 'campo' => $this->trial('2026-11-13 23:59')]);
        $this->signIn($company);

        $this->get($company->url('/'))->assertOk()
            ->assertSee(trans_choice('app.trial.days', 14, ['count' => 14]))
            ->assertSee(__('app.trial.plans'))
            ->assertSee(config('kiban.core.url').'/conta/sistemas', false)
            // Not the old per-system notice too.
            ->assertDontSee(Str::before(__('app.license.trial'), ':module'));

        $banner = app(TrialBanner::class)->for($company, auth()->user());
        $this->assertSame(14, $banner['days']);
        $this->assertFalse($banner['urgent']);
    }

    public function test_the_last_days_are_highlighted_and_the_last_day_says_today(): void
    {
        $company = $this->tenant('aurora', ['frota' => $this->trial('2026-11-02 23:59')]);
        $owner = $this->signIn($company);

        $banner = app(TrialBanner::class)->for($company, $owner);
        $this->assertSame(3, $banner['days']);
        $this->assertTrue($banner['urgent']);

        // Late at night in São Paulo (already the next day in UTC): still counted in the company's time zone.
        $this->travelTo(CarbonImmutable::parse('2026-11-02 22:30', 'America/Sao_Paulo'));
        $this->assertSame(0, app(TrialBanner::class)->for($company, $owner)['days']);
        $this->get($company->url('/'))->assertOk()->assertSee(__('app.trial.today'));
    }

    public function test_no_strip_once_a_system_is_paid(): void
    {
        $company = $this->tenant('aurora', ['frota' => ['status' => 'active'], 'campo' => $this->trial('2026-11-13 23:59')]);
        $owner = $this->signIn($company);

        $this->assertNull(app(TrialBanner::class)->for($company, $owner));
        $this->get($company->url('/'))->assertOk()->assertDontSee(__('app.trial.plans'));
    }

    public function test_employees_never_see_it(): void
    {
        $company = $this->tenant('aurora', ['frota' => $this->trial('2026-11-13 23:59')]);
        $member = $this->signIn($company, 'member');

        $this->assertNull(app(TrialBanner::class)->for($company, $member));
        $this->get($company->url('/'))->assertOk()
            ->assertDontSee(__('app.trial.plans'))
            ->assertDontSee(Str::before(__('app.license.trial'), ':module'));

        // An administrator does.
        $admin = $this->signIn($company, 'admin');
        $this->assertNotNull(app(TrialBanner::class)->for($company, $admin));
    }
}
