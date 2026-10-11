<?php

namespace App\Licensing;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Keeps each company's license up to date.
 *
 * - Asks the Core for a new license every few minutes (scheduler and, as a backup, during use).
 * - Keeps the last valid one: if the Core is down, the company keeps working until it expires (72 h).
 * - Finds companies by address: the first visit to padaria.<domain> asks the Core if it exists.
 */
class LicenseManager
{
    public function __construct(
        protected CoreClient $core,
        protected LicenseVerifier $verifier,
    ) {}

    /**
     * The company of an address, created from the Core the first time. null when the Core does not know it.
     *
     * @throws CoreUnavailableException when the address is new and the Core cannot be reached
     */
    public function resolve(string $subdomain): ?Tenant
    {
        $subdomain = mb_strtolower($subdomain);
        $tenant = Tenant::query()->where('subdomain', $subdomain)->first();

        if ($tenant !== null) {
            return $tenant;
        }

        // Unknown addresses are asked once a minute at most (protects the Core from random addresses).
        $missingKey = "kiban:tenant-missing:{$subdomain}";

        if (Cache::has($missingKey)) {
            return null;
        }

        // Core unreachable: CoreUnavailableException goes up ("try again", not "does not exist").
        $response = $this->core->license($subdomain);

        if ($response === null) {
            Cache::put($missingKey, true, 60);

            return null;
        }

        try {
            $claims = $this->verifier->verify($response['token']);
        } catch (CoreUnavailableException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            // A license that does not check out is never trusted (wrong keys, clock, address of the Core).
            Log::error('License from the Core failed verification.', ['subdomain' => $subdomain, 'error' => $exception->getMessage()]);

            throw new CoreUnavailableException('Invalid license from the Core: '.$exception->getMessage(), previous: $exception);
        }

        // Same company under a new address (changed in the Core): keep its data.
        $tenant = Tenant::query()->firstOrNew(['uuid' => $claims['tenant']['uuid']]);
        $this->store($tenant, $response['token'], $claims);

        return $tenant;
    }

    /**
     * Downloads a new license. Returns false when the Core could not be reached (the old one stays).
     */
    public function refresh(Tenant $tenant): bool
    {
        try {
            $response = $this->core->license($tenant->subdomain);

            if ($response === null) {
                // The Core no longer knows this address (company removed or address changed): no access.
                $tenant->forceFill(['license_token' => null, 'license_expires_at' => null, 'license_fetched_at' => now()])->save();
                $tenant->forgetLicense();

                return true;
            }

            $this->store($tenant, $response['token'], $this->verifier->verify($response['token']));

            return true;
        } catch (Throwable $exception) {
            $tenant->forceFill(['license_failed_at' => now()])->save();
            Log::warning('Could not refresh the license.', ['tenant' => $tenant->subdomain, 'error' => $exception->getMessage()]);

            return false;
        }
    }

    /**
     * Refreshes in the background of a request when the license is older than the refresh interval.
     * A recent failure waits a minute before trying again, so a Core outage does not slow every page.
     */
    public function refreshIfStale(Tenant $tenant): void
    {
        $refresh = (int) config('kiban.license.refresh_seconds');
        $fresh = $tenant->license_fetched_at?->gt(now()->subSeconds($refresh)) ?? false;
        $failedRecently = $tenant->license_failed_at?->gt(now()->subMinute()) ?? false;

        if ($fresh || $failedRecently) {
            return;
        }

        // One refresh at a time per company.
        Cache::lock("kiban:license-refresh:{$tenant->getKey()}", 30)->get(fn () => $this->refresh($tenant));
    }

    /**
     * @param  array<string, mixed>  $claims  already verified
     */
    protected function store(Tenant $tenant, string $token, array $claims): void
    {
        $tenant->forceFill([
            'uuid' => $claims['tenant']['uuid'],
            'subdomain' => $claims['tenant']['subdomain'],
            'name' => $claims['tenant']['name'],
            'license_token' => $token,
            'license_expires_at' => CarbonImmutable::createFromTimestamp((int) $claims['exp']),
            'license_fetched_at' => now(),
            'license_failed_at' => null,
        ])->save();

        $tenant->forgetLicense();
    }
}
