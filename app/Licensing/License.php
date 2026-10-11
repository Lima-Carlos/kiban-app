<?php

namespace App\Licensing;

use Carbon\CarbonImmutable;

/**
 * What a company may use, read from its signed license (issued by the Kiban Core).
 *
 * The license is only trusted after its signature and dates were checked (LicenseVerifier).
 * Without a valid license nothing is available: the app never "guesses" access.
 */
final class License
{
    /**
     * @param  array<string, mixed>  $claims
     */
    public function __construct(
        public readonly LicenseState $state,
        public readonly array $claims = [],
    ) {}

    public static function none(LicenseState $state = LicenseState::Missing): self
    {
        return new self($state);
    }

    public function isValid(): bool
    {
        return $this->state === LicenseState::Valid;
    }

    /** The company exists, is active in the Core and the license can be used. */
    public function isActive(): bool
    {
        return $this->isValid() && (bool) ($this->claims['tenant']['active'] ?? false);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function modules(): array
    {
        return $this->isValid() ? (array) ($this->claims['modules'] ?? []) : [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function module(string $key): ?array
    {
        return $this->modules()[$key] ?? null;
    }

    public function hasModule(string $key): bool
    {
        return $this->isActive() && (bool) ($this->module($key)['access'] ?? false);
    }

    /** Contract ended or payment overdue: the company can look at its data but not change it. */
    public function isReadOnly(string $key): bool
    {
        return (bool) ($this->module($key)['read_only'] ?? false);
    }

    public function canWrite(string $key): bool
    {
        return $this->hasModule($key) && ! $this->isReadOnly($key);
    }

    /**
     * Contracted amount of a limit (e.g. vehicles, employees). null = unlimited or not limited by the plan.
     */
    public function limit(string $module, string $limit): ?int
    {
        $limits = (array) ($this->module($module)['limits'] ?? []);

        return isset($limits[$limit]) ? (int) $limits[$limit] : null;
    }

    /**
     * The limit plus the tolerance set in the Core (e.g. 10% more), so a company is not blocked the minute it grows.
     */
    public function limitWithTolerance(string $module, string $limit): ?int
    {
        $value = $this->limit($module, $limit);

        if ($value === null) {
            return null;
        }

        $percent = (int) ($this->claims['limits_tolerance']['percent'] ?? 0);

        return $value + (int) floor($value * $percent / 100);
    }

    public function feature(string $module, string $feature): bool
    {
        return (bool) ($this->module($module)['features'][$feature] ?? false);
    }

    public function trialEndsAt(string $module): ?CarbonImmutable
    {
        $date = $this->module($module)['trial_ends_at'] ?? null;

        return $date ? CarbonImmutable::parse($date) : null;
    }

    public function expiresAt(): ?CarbonImmutable
    {
        return isset($this->claims['exp']) ? CarbonImmutable::createFromTimestamp((int) $this->claims['exp']) : null;
    }

    public function tenant(string $key, mixed $default = null): mixed
    {
        return $this->claims['tenant'][$key] ?? $default;
    }
}
