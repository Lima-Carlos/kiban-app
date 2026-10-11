<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;

/**
 * The company (tenant) of the current request or task. Every model with BelongsToTenant reads it:
 * queries only see this company's rows and new rows are saved for it.
 */
class TenantContext
{
    protected ?Tenant $tenant = null;

    public function current(): ?Tenant
    {
        return $this->tenant;
    }

    public function get(): Tenant
    {
        return $this->tenant ?? throw new MissingTenantException;
    }

    public function set(?Tenant $tenant): void
    {
        $this->tenant = $tenant;
    }

    public function forget(): void
    {
        $this->tenant = null;
    }

    /**
     * Runs the callback as the given company, then restores the previous one (scheduled tasks, jobs).
     *
     * @template T
     *
     * @param  Closure(Tenant): T  $callback
     * @return T
     */
    public function run(Tenant $tenant, Closure $callback): mixed
    {
        $previous = $this->tenant;
        $this->tenant = $tenant;

        try {
            return $callback($tenant);
        } finally {
            $this->tenant = $previous;
        }
    }
}
