<?php

namespace App\Licensing;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Talks to the Kiban Core API.
 *
 * - As the app itself (OAuth2 client credentials): company licenses and first-steps progress.
 * - On behalf of a person (their access token): who signed in and which companies they belong to.
 *
 * Any network failure or Core error becomes CoreUnavailableException, so callers can carry on offline.
 */
class CoreClient
{
    protected const TOKEN_CACHE_KEY = 'kiban:core-system-token';

    /**
     * Signed license of a company, or null when the Core does not know this address.
     *
     * @return array{token: string, refresh_in?: int}|null
     */
    public function license(string $subdomain): ?array
    {
        $response = $this->asSystem(fn (PendingRequest $http): Response => $http->get('/api/v1/tenants/'.rawurlencode($subdomain).'/license'));

        if ($response->notFound()) {
            return null;
        }

        $this->ensureSuccessful($response);

        return $response->json();
    }

    /**
     * @return array{kid: string, alg: string, public_key: string}
     */
    public function licenseKey(): array
    {
        $response = $this->send(fn (): Response => $this->http()->get('/api/v1/license-key'));
        $this->ensureSuccessful($response);

        return $response->json();
    }

    /**
     * @param  array<int, array{key: string, done: bool}>  $steps
     */
    public function reportOnboarding(string $subdomain, string $module, array $steps): void
    {
        $response = $this->asSystem(fn (PendingRequest $http): Response => $http->post(
            '/api/v1/tenants/'.rawurlencode($subdomain).'/onboarding',
            ['module' => $module, 'steps' => $steps],
        ));

        $this->ensureSuccessful($response);
    }

    /**
     * Exchanges the sign-in code for the person's tokens (authorization code + PKCE).
     *
     * @return array{access_token: string, refresh_token?: string, expires_in: int}
     */
    public function exchangeCode(string $code, string $verifier, string $redirectUri): array
    {
        return $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'code_verifier' => $verifier,
            'redirect_uri' => $redirectUri,
        ]);
    }

    /**
     * @return array{access_token: string, refresh_token?: string, expires_in: int}
     */
    public function refreshToken(string $refreshToken): array
    {
        return $this->tokenRequest(['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken]);
    }

    /**
     * The signed-in person and their companies, or null when the Core no longer accepts the token.
     *
     * @return array{id: int, name: string, email: string, locale: ?string, customers: array<int, array{uuid: string, subdomain: ?string, role: string}>}|null
     */
    public function me(string $accessToken): ?array
    {
        $response = $this->send(fn (): Response => $this->http()->withToken($accessToken)->get('/api/v1/me'));

        if ($response->unauthorized()) {
            return null;
        }

        $this->ensureSuccessful($response);

        return $response->json();
    }

    /**
     * @param  array<string, string>  $data
     * @return array{access_token: string, refresh_token?: string, expires_in: int}
     */
    protected function tokenRequest(array $data): array
    {
        $response = $this->send(fn (): Response => $this->http()->asForm()->post('/oauth/token', $data + $this->credentials()));

        if ($response->clientError()) {
            throw new InvalidGrantException((string) $response->json('error', 'invalid_grant'));
        }

        $this->ensureSuccessful($response);

        return $response->json();
    }

    /**
     * Calls the Core with the app's own token; a rejected token is renewed once.
     *
     * @param  callable(PendingRequest): Response  $request
     */
    protected function asSystem(callable $request): Response
    {
        $response = $this->send(fn (): Response => $request($this->http()->withToken($this->systemToken())));

        if ($response->unauthorized()) {
            Cache::forget(self::TOKEN_CACHE_KEY);
            $response = $this->send(fn (): Response => $request($this->http()->withToken($this->systemToken())));
        }

        return $response;
    }

    protected function systemToken(): string
    {
        $cached = Cache::get(self::TOKEN_CACHE_KEY);

        if (is_string($cached)) {
            return $cached;
        }

        $response = $this->send(fn (): Response => $this->http()->asForm()->post('/oauth/token', [
            'grant_type' => 'client_credentials',
            'scope' => 'licenses onboarding',
        ] + $this->credentials()));

        $this->ensureSuccessful($response);

        $token = (string) $response->json('access_token');
        Cache::put(self::TOKEN_CACHE_KEY, $token, max(60, (int) $response->json('expires_in', 3600) - 60));

        return $token;
    }

    /**
     * @return array{client_id: string, client_secret: string}
     */
    protected function credentials(): array
    {
        $id = (string) config('kiban.core.client_id');
        $secret = (string) config('kiban.core.client_secret');

        if ($id === '' || $secret === '') {
            throw new CoreUnavailableException('KIBAN_CORE_CLIENT_ID / KIBAN_CORE_CLIENT_SECRET are not configured.');
        }

        return ['client_id' => $id, 'client_secret' => $secret];
    }

    protected function http(): PendingRequest
    {
        return Http::baseUrl((string) config('kiban.core.internal_url'))
            ->acceptJson()
            ->timeout((int) config('kiban.core.timeout'))
            ->connectTimeout((int) config('kiban.core.timeout'));
    }

    /**
     * @param  callable(): Response  $request
     */
    protected function send(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException $exception) {
            throw new CoreUnavailableException('Kiban Core unreachable: '.$exception->getMessage(), previous: $exception);
        }
    }

    protected function ensureSuccessful(Response $response): void
    {
        if (! $response->successful()) {
            throw new CoreUnavailableException("Kiban Core answered HTTP {$response->status()}.");
        }
    }
}
