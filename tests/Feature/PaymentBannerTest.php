<?php

namespace Tests\Feature;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Your invoice is due in N days / today / overdue": owner and administrators only, from 3 days before the
 * due date (the Core sends the date in the license).
 */
class PaymentBannerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-10 10:00', 'America/Sao_Paulo'));
    }

    /** A new license with the claim just set (what the Core does at each renewal). */
    protected function reissue($company): void
    {
        $company->forceFill(['license_token' => $this->licenseToken($company, ['frota' => []]), 'license_fetched_at' => now()])->save();
        $company->forgetLicense();
        $this->useTenant($company);
    }

    public function test_it_shows_only_close_to_the_due_date_and_only_to_the_owner(): void
    {
        // 5 days away: nothing yet.
        $this->billingClaim = ['due_on' => '2026-11-15', 'overdue' => false];
        $company = $this->tenant('aurora', ['frota' => []]);
        $this->signIn($company);
        $this->get($company->url('/'))->assertOk()->assertDontSee('data-payment-banner', false);

        // 3 days away.
        $this->billingClaim = ['due_on' => '2026-11-13', 'overdue' => false];
        $this->reissue($company);
        $this->get($company->url('/'))->assertOk()->assertSee('data-payment-banner', false)->assertSee('Sua fatura vence em 3 dias');

        // Due date and overdue.
        $this->billingClaim = ['due_on' => '2026-11-10', 'overdue' => false];
        $this->reissue($company);
        $this->get($company->url('/'))->assertSee('Sua fatura vence hoje.');
        $this->billingClaim = ['due_on' => '2026-11-08', 'overdue' => true];
        $this->reissue($company);
        $this->get($company->url('/'))->assertSee('Sua fatura está vencida');

        // An employee never sees it.
        $member = User::factory()->memberOf($company, 'member')->create();
        $this->coreMember($company, $member);
        $this->actingAs($member);
        $this->get($company->url('/'))->assertDontSee('data-payment-banner', false);
    }
}
