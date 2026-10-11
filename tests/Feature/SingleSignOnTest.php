<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Sign-in through the Kiban Core (authorization code + PKCE), without passwords in the app.
 */
class SingleSignOnTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array<string, mixed>> */
    protected array $tokenRequests = [];

    protected Tenant $padaria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->padaria = $this->tenant('padaria');
    }

    protected function coreTokenResponse(Request $request): PromiseInterface
    {
        $this->tokenRequests[] = $request->data();

        if (($request['code'] ?? null) === 'used-code') {
            return Http::response(['error' => 'invalid_grant'], 400);
        }

        return parent::coreTokenResponse($request);
    }

    /**
     * @return array{state: string, challenge: string, query: array<string, string>}
     */
    protected function startSignIn(): array
    {
        $this->get($this->padaria->url('/'))->assertRedirect($this->padaria->url('/auth/login'));
        $location = $this->get($this->padaria->url('/auth/login'))->assertRedirect()->headers->get('Location');

        $this->assertStringStartsWith('http://core.test/oauth/authorize?', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return ['state' => $query['state'], 'challenge' => $query['code_challenge'], 'query' => $query];
    }

    protected function coreKnowsPerson(array $customers): void
    {
        $this->coreMe = [
            'id' => 4321,
            'name' => 'Maria Souza',
            'email' => 'maria@padaria.test',
            'locale' => 'pt_BR',
            'two_factor' => true,
            'customers' => $customers,
        ];
    }

    public function test_full_sign_in_through_the_core(): void
    {
        $this->coreKnowsPerson([['uuid' => $this->padaria->uuid, 'name' => 'Padaria', 'subdomain' => 'padaria', 'role' => 'admin']]);

        $flow = $this->startSignIn();
        $this->assertSame('test-client', $flow['query']['client_id']);
        $this->assertSame('http://app.kiban.test/auth/callback', $flow['query']['redirect_uri']);
        $this->assertSame('S256', $flow['query']['code_challenge_method']);
        $this->assertSame('code', $flow['query']['response_type']);

        // The Core sends the person back to the central address with a code.
        $handoff = $this->get('http://app.kiban.test/auth/callback?code=good-code&state='.$flow['state'])
            ->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith($this->padaria->url('/auth/handoff?ticket='), $handoff);

        // PKCE: the app proved it started this sign-in (verifier matches the challenge sent before).
        $verifier = $this->tokenRequests[0]['code_verifier'];
        $this->assertSame($flow['challenge'], strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_'));
        $this->assertSame('test-secret', $this->tokenRequests[0]['client_secret']);

        $this->get($handoff)->assertRedirect(rtrim($this->padaria->url('/'), '/'));

        $user = User::sole();
        $this->assertSame(4321, $user->core_id);
        $this->assertSame('maria@padaria.test', $user->email);
        $this->assertSame('admin', $user->roleIn($this->padaria));
        $this->assertTrue(Auth::user()->is($user));
        $this->get($this->padaria->url('/'))->assertOk()->assertSee('Padaria');

        // A ticket works only once.
        Auth::logout();
        $this->get($handoff)->assertStatus(400)->assertSee(__('app.failed.expired'));
    }

    public function test_sign_in_links_cannot_be_reused_or_finished_in_another_browser(): void
    {
        $this->coreKnowsPerson([['uuid' => $this->padaria->uuid, 'name' => 'Padaria', 'subdomain' => 'padaria', 'role' => 'member']]);

        $flow = $this->startSignIn();
        $handoff = $this->get('http://app.kiban.test/auth/callback?code=good-code&state='.$flow['state'])->headers->get('Location');

        // Same state again: refused.
        $this->get('http://app.kiban.test/auth/callback?code=good-code&state='.$flow['state'])->assertStatus(400);

        // Another browser (no sign-in started there): refused, nobody signed in.
        session()->forget('kiban.sso_nonce');
        $this->get($handoff)->assertStatus(400);
        $this->assertGuest();

        // A code already used in the Core.
        $flow = $this->startSignIn();
        $this->get('http://app.kiban.test/auth/callback?code=used-code&state='.$flow['state'])->assertStatus(400);

        // The callback only exists on the central address.
        $this->get($this->padaria->url('/auth/callback?code=x&state=y'))->assertNotFound();
    }

    public function test_cancel_and_people_from_other_companies(): void
    {
        $flow = $this->startSignIn();
        $this->get('http://app.kiban.test/auth/callback?error=access_denied&state='.$flow['state'])
            ->assertRedirect($this->padaria->url('/auth/signed-out?reason=denied'));
        $this->get($this->padaria->url('/auth/signed-out?reason=denied'))->assertOk()->assertSee(__('app.signed_out.denied'));

        // Signed in at the Core, but not part of this company.
        $this->coreKnowsPerson([['uuid' => '11111111-2222-3333-4444-555555555555', 'name' => 'Outra', 'subdomain' => 'outra', 'role' => 'owner']]);
        $flow = $this->startSignIn();
        $handoff = $this->get('http://app.kiban.test/auth/callback?code=good-code&state='.$flow['state'])->headers->get('Location');

        $this->get($handoff)->assertForbidden()->assertSee(__('app.no_access.title'))->assertSee('maria@padaria.test');
        $this->assertGuest();
    }

    public function test_removed_from_the_team_in_the_core_loses_access(): void
    {
        $user = $this->signIn($this->padaria, 'member');
        $this->get($this->padaria->url('/'))->assertOk();

        // The Core is down: the person keeps working.
        $this->travel(11)->minutes();
        $this->coreDown = true;
        $this->get($this->padaria->url('/'))->assertOk();

        // Role changed in the Core: updated here.
        $this->coreDown = false;
        $this->coreMe = $this->coreMeFor($user, [$this->padaria], 'admin');
        $this->get($this->padaria->url('/'))->assertOk();
        $user->forgetRoles();
        $this->assertSame('admin', $user->roleIn($this->padaria));

        // Removed from the company in the Core: signed out at the next check.
        $this->travel(11)->minutes();
        $this->coreMe = $this->coreMeFor($user, []);
        $this->get($this->padaria->url('/'))->assertForbidden()->assertSee(__('app.no_access.title'));
        $this->assertGuest();
    }

    public function test_sign_out_page(): void
    {
        $this->signIn($this->padaria);

        $this->post($this->padaria->url('/logout'))->assertRedirect($this->padaria->url('/auth/signed-out'));
        $this->assertGuest();
        $this->get($this->padaria->url('/auth/signed-out'))->assertOk()->assertSee(__('app.signed_out.again'));
    }
}
