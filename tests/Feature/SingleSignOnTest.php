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

        // The Core sends the person back to the app's fixed address with a code: signed in, back to the company.
        $this->get('http://app.kiban.test/auth/callback?code=good-code&state='.$flow['state'])
            ->assertRedirect($this->padaria->url('/'));

        // PKCE: the app proved it started this sign-in (verifier matches the challenge sent before).
        $verifier = $this->tokenRequests[0]['code_verifier'];
        $this->assertSame($flow['challenge'], strtr(rtrim(base64_encode(hash('sha256', $verifier, true)), '='), '+/', '-_'));
        $this->assertSame('test-secret', $this->tokenRequests[0]['client_secret']);

        $user = User::sole();
        $this->assertSame(4321, $user->core_id);
        $this->assertSame('maria@padaria.test', $user->email);
        $this->assertSame('admin', $user->roleIn($this->padaria));
        $this->assertTrue(Auth::user()->is($user));
        $this->get($this->padaria->url('/'))->assertOk()->assertSee('Padaria');
    }

    public function test_sign_in_links_cannot_be_reused_or_finished_in_another_browser(): void
    {
        $this->coreKnowsPerson([['uuid' => $this->padaria->uuid, 'name' => 'Padaria', 'subdomain' => 'padaria', 'role' => 'member']]);

        // Another browser (no sign-in started there): refused, nobody signed in.
        $flow = $this->startSignIn();
        session()->forget('kiban.sso_nonce');
        $this->get('http://app.kiban.test/auth/callback?code=good-code&state='.$flow['state'])->assertStatus(400)->assertSee(__('app.failed.expired'));
        $this->assertGuest();

        // Same state again (already used): refused.
        $flow = $this->startSignIn();
        $this->get('http://app.kiban.test/auth/callback?code=good-code&state='.$flow['state'])->assertRedirect($this->padaria->url('/'));
        $this->get('http://app.kiban.test/auth/callback?code=good-code&state='.$flow['state'])->assertStatus(400);
        Auth::logout();

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
        $this->get('http://app.kiban.test/auth/callback?code=good-code&state='.$flow['state'])
            ->assertForbidden()->assertSee(__('app.no_access.title'))->assertSee('maria@padaria.test');
        $this->assertGuest();
    }

    public function test_sign_in_from_the_central_page(): void
    {
        $oficina = $this->tenant('oficina');
        $this->coreKnowsPerson([
            ['uuid' => $this->padaria->uuid, 'name' => 'Padaria', 'subdomain' => 'padaria', 'role' => 'member'],
            ['uuid' => $oficina->uuid, 'name' => 'Oficina', 'subdomain' => 'oficina', 'role' => 'admin'],
        ]);

        // One address for everybody: the person signs in without knowing the company's name.
        $this->get('http://app.kiban.test/')->assertRedirect('http://app.kiban.test/entrar');
        $this->get('http://app.kiban.test/entrar')->assertOk()->assertSee(__('app.central.login'));
        $location = $this->get('http://app.kiban.test/entrar/conta')->assertRedirect()->headers->get('Location');
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        // Two companies: both added, then the choice.
        $this->get('http://app.kiban.test/auth/callback?code=good-code&state='.$query['state'])->assertRedirect('http://app.kiban.test/entrar');
        $user = User::sole();
        $this->assertSame(['member', 'admin'], [$user->roleIn($this->padaria), $user->roleIn($oficina)]);
        $this->get('http://app.kiban.test/entrar')->assertOk()->assertSee(__('app.central.choose'))->assertSee('Padaria')->assertSee('Oficina');

        // The same sign-in opens both companies; the last one used opens next time.
        $this->get($oficina->url('/'))->assertOk();
        $this->get($this->padaria->url('/'))->assertOk()->assertCookie('kiban_empresa', 'padaria');
        $this->withCookie('kiban_empresa', 'padaria')->get('http://app.kiban.test/entrar')->assertRedirect($this->padaria->url('/'));

        // Typing the name (or pasting the address) also works.
        Auth::logout();
        $this->get('http://app.kiban.test/entrar/ir?company=app.kiban.test/oficina/frota')->assertRedirect($oficina->url('/'));
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
        $this->coreMember($this->padaria, $user, 'admin');
        $this->get($this->padaria->url('/'))->assertOk();
        $user->forgetRoles();
        $this->assertSame('admin', $user->roleIn($this->padaria));

        // Removed from the company in the Core: out of it at the next check (asked with the app's own access).
        // The person stays signed in for the other companies they belong to.
        $this->travel(11)->minutes();
        unset($this->coreMembers['padaria'][$user->core_id]);
        $this->get($this->padaria->url('/'))->assertForbidden()->assertSee(__('app.no_access.title'));
        $this->assertAuthenticated();
        $this->assertNull($user->fresh()->roleIn($this->padaria));
    }

    public function test_stays_signed_in_on_the_phone(): void
    {
        $this->coreKnowsPerson([['uuid' => $this->padaria->uuid, 'name' => 'Padaria', 'subdomain' => 'padaria', 'role' => 'member']]);
        $flow = $this->startSignIn();
        $response = $this->get('http://app.kiban.test/auth/callback?code=good-code&state='.$flow['state']);

        // "Remember me" cookie, valid for 30 days.
        $cookie = collect($response->headers->getCookies())->first(fn ($cookie): bool => str_starts_with($cookie->getName(), 'remember_web_'));
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $cookie->getExpiresTime(), 120);
    }

    public function test_sign_out_page(): void
    {
        $this->signIn($this->padaria);

        $this->post($this->padaria->url('/logout'))->assertRedirect($this->padaria->url('/auth/signed-out'));
        $this->assertGuest();
        $this->get($this->padaria->url('/auth/signed-out'))->assertOk()->assertSee(__('app.signed_out.again'));
    }
}
