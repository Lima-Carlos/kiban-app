<?php

namespace Tests;

use App\Auth\CoreSignIn;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Firebase\JWT\JWT;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Tests never talk to a real Kiban Core: it is simulated with Http::fake() and a key pair made here,
 * so they run without Docker and without internet (GitHub Actions).
 */
abstract class TestCase extends BaseTestCase
{
    /** @var array{private: string, public: string}|null */
    protected static ?array $keys = null;

    /** Licenses the fake Core answers, by address (missing = unknown company). */
    protected array $coreLicenses = [];

    /** Answer of the fake Core for /api/v1/me (null = token refused). */
    protected ?array $coreMe = null;

    /** When true, the fake Core is "down" (connection error). */
    protected bool $coreDown = false;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->fakeCore();
    }

    /**
     * @return array{private: string, public: string}
     */
    protected static function keys(): array
    {
        if (static::$keys === null) {
            $resource = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($resource, $private);
            static::$keys = ['private' => $private, 'public' => openssl_pkey_get_details($resource)['key']];
        }

        return static::$keys;
    }

    /**
     * A license signed like the Core does.
     *
     * @param  array<string, array<string, mixed>>  $modules
     * @param  array<string, mixed>  $tenantClaims
     */
    protected function licenseToken(Tenant|string $tenant, array $modules = ['demo' => []], array $tenantClaims = [], int $hours = 72, ?string $privateKey = null): string
    {
        $subdomain = $tenant instanceof Tenant ? $tenant->subdomain : $tenant;
        $uuid = $tenant instanceof Tenant ? $tenant->uuid : static::uuidFor($subdomain);
        $now = CarbonImmutable::now();

        $claims = [
            'iss' => config('kiban.core.url'),
            'aud' => 'kiban-app',
            'sub' => $uuid,
            'iat' => $now->getTimestamp(),
            'nbf' => $now->getTimestamp() - 60,
            'exp' => $now->addHours($hours)->getTimestamp(),
            'tenant' => $tenantClaims + [
                'uuid' => $uuid,
                'subdomain' => $subdomain,
                'name' => $tenant instanceof Tenant ? $tenant->name : Str::headline($subdomain),
                'active' => true,
                'country' => 'BR',
                'locale' => 'pt_BR',
                'timezone' => 'America/Sao_Paulo',
                'require_two_factor' => false,
            ],
            'modules' => array_map(fn (array $module): array => $module + [
                'status' => 'active',
                'access' => true,
                'read_only' => false,
                'plan' => 'demo-basic',
                'trial_ends_at' => null,
                'read_only_until' => null,
                'limits' => [],
                'features' => [],
            ], $modules),
            'limits_tolerance' => ['percent' => 0, 'grace_days' => 0],
        ];

        return JWT::encode($claims, $privateKey ?? static::keys()['private'], 'RS256', 'test-key');
    }

    /**
     * A company already known by the app, with a valid license (the fake Core answers the same).
     *
     * @param  array<string, array<string, mixed>>  $modules
     * @param  array<string, mixed>  $tenantClaims
     */
    protected function tenant(string $subdomain = 'padaria', array $modules = ['demo' => []], array $tenantClaims = []): Tenant
    {
        $tenant = Tenant::factory()->create(['subdomain' => $subdomain, 'name' => Str::headline($subdomain)]);
        $tenant->forceFill([
            'license_token' => $this->licenseToken($tenant, $modules, $tenantClaims),
            'license_fetched_at' => now(),
            'license_expires_at' => now()->addHours(72),
        ])->save();
        $this->coreLicenses[$subdomain] = fn (): string => $this->licenseToken($tenant, $modules, $tenantClaims);

        return $tenant;
    }

    /** Same id every time for the same address (like the Core). */
    protected static function uuidFor(string $subdomain): string
    {
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(md5($subdomain), 4));
    }

    protected function useTenant(Tenant $tenant): Tenant
    {
        app(TenantContext::class)->set($tenant);

        return $tenant;
    }

    /**
     * Signed-in person of the company, as after the Core sign-in.
     */
    protected function signIn(Tenant $tenant, string $role = 'owner'): User
    {
        $user = User::factory()->memberOf($tenant, $role)->create();
        $this->actingAs($user);
        $this->withSession([
            CoreSignIn::SESSION_CHECKED => now()->getTimestamp(),
        ]);
        $this->coreMe = $this->coreMeFor($user, [$tenant], $role);
        $this->coreMember($tenant, $user, $role);
        $this->useTenant($tenant);

        return $user;
    }

    /**
     * People of each company in the fake Core: [subdomain => [core user id => member]].
     *
     * @var array<string, array<int, array<string, mixed>>>
     */
    protected array $coreMembers = [];

    protected int $nextCoreUserId = 900000;

    /**
     * Registers the person as a member of the company in the fake Core.
     */
    protected function coreMember(Tenant $tenant, User $user, string $role = 'member', string $status = 'active'): void
    {
        $this->coreMembers[$tenant->subdomain][$user->core_id] = [
            'id' => $user->core_id, 'name' => $user->name, 'email' => $user->email, 'phone' => $user->phone,
            'role' => $role, 'status' => $status, 'invited_at' => null, 'last_login_at' => null,
        ];
    }

    protected function coreMembersResponse(Request $request, string $subdomain, ?int $id, bool $invitation): PromiseInterface
    {
        $members = $this->coreMembers[$subdomain] ?? [];
        $link = fn (int $id): string => "http://core.test/conta/convite/{$id}?signature=test-{$id}-".count($members);

        return match (true) {
            $request->method() === 'GET' && $id === null => Http::response(['members' => array_values($members)]),
            $request->method() === 'GET' => isset($members[$id]) ? Http::response($members[$id]) : Http::response([], 404),
            $request->method() === 'DELETE' => (function () use ($subdomain, $id, $members) {
                if (! isset($members[$id])) {
                    return Http::response([], 404);
                }

                if ($members[$id]['role'] !== 'member') {
                    return Http::response(['message' => 'Not a member'], 422);
                }

                unset($this->coreMembers[$subdomain][$id]);

                return Http::response(['removed' => true]);
            })(),
            $invitation => isset($members[$id]) ? Http::response(['member' => $members[$id], 'invitation_url' => $link($id)]) : Http::response([], 404),
            default => (function () use ($request, $subdomain, $link) {
                if (blank($request['email'] ?? null) && blank($request['phone'] ?? null)) {
                    return Http::response(['message' => 'Invalid', 'errors' => ['phone' => ['Informe o celular ou o e-mail.']]], 422);
                }

                $phone = filled($request['phone'] ?? null) ? '55'.preg_replace('/\D/', '', $request['phone']) : null;
                $id = $this->nextCoreUserId++;
                $member = [
                    'id' => $id, 'name' => $request['name'], 'email' => $request['email'] ?? null, 'phone' => $phone,
                    'role' => 'member', 'status' => 'invited', 'invited_at' => now()->toIso8601String(), 'last_login_at' => null,
                ];
                $this->coreMembers[$subdomain][$id] = $member;
                $this->lastInvitationNext = $request['next'] ?? null;

                return Http::response(['member' => $member, 'invitation_url' => $link($id)], 201);
            })(),
        };
    }

    /** "next" sent with the last invitation (where the person goes after creating the password). */
    protected ?string $lastInvitationNext = null;

    /**
     * What the Core answers in /api/v1/me for this person.
     *
     * @param  array<int, Tenant>  $tenants
     * @return array<string, mixed>
     */
    protected function coreMeFor(User $user, array $tenants, string $role = 'owner'): array
    {
        return [
            'id' => $user->core_id,
            'name' => $user->name,
            'email' => $user->email,
            'locale' => $user->locale,
            'two_factor' => false,
            'customers' => array_map(fn (Tenant $tenant): array => ['uuid' => $tenant->uuid, 'name' => $tenant->name, 'subdomain' => $tenant->subdomain, 'role' => $role], $tenants),
        ];
    }

    protected function fakeCore(): void
    {
        Http::preventStrayRequests();

        Http::fake(function (Request $request) {
            if ($this->coreDown) {
                throw new ConnectionException('Core is down (test).');
            }

            $path = (string) parse_url($request->url(), PHP_URL_PATH);

            if ($path === '/api/v1/license-key') {
                return Http::response(['kid' => 'test-key', 'alg' => 'RS256', 'public_key' => static::keys()['public']]);
            }

            if ($path === '/oauth/token' && $request['grant_type'] === 'client_credentials') {
                return Http::response(['access_token' => 'system-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
            }

            if ($path === '/oauth/token') {
                return $this->coreTokenResponse($request);
            }

            if ($path === '/api/v1/me') {
                return $this->coreMe === null
                    ? Http::response(['message' => 'Unauthenticated.'], 401)
                    : Http::response($this->coreMe);
            }

            if (preg_match('#^/api/v1/tenants/([^/]+)/license$#', $path, $match)) {
                $license = $this->coreLicenses[$match[1]] ?? null;

                return $license === null
                    ? Http::response(['message' => 'Not found'], 404)
                    : Http::response(['token' => is_callable($license) ? $license() : $license, 'refresh_in' => 300]);
            }

            if (preg_match('#^/api/v1/tenants/([^/]+)/onboarding$#', $path)) {
                return Http::response(['completed' => false]);
            }

            if (preg_match('#^/api/v1/tenants/([^/]+)/members(?:/(\d+))?(/invitation)?$#', $path, $match)) {
                return $this->coreMembersResponse($request, $match[1], isset($match[2]) && $match[2] !== '' ? (int) $match[2] : null, isset($match[3]));
            }

            return Http::response(['message' => 'Unexpected request in test: '.$path], 500);
        });
    }

    protected function coreTokenResponse(Request $request): PromiseInterface
    {
        return Http::response(['access_token' => 'person-token', 'refresh_token' => 'refresh-token', 'expires_in' => 3600, 'token_type' => 'Bearer']);
    }
}
