<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Closure;
use Illuminate\Support\Facades\URL;

/**
 * The company (tenant) of the current request or task. Every model with BelongsToTenant reads it:
 * queries only see this company's rows and new rows are saved for it.
 *
 * The addresses the app generates follow the company too (app.kibancore.com.br/<company>/...), also in
 * scheduled tasks and jobs, where there is no visit to take them from.
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
        $this->useAddressOf($tenant);
    }

    public function forget(): void
    {
        $this->set(null);
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
        $this->set($tenant);

        try {
            return $callback($tenant);
        } finally {
            $this->set($previous);
        }
    }

    protected function useAddressOf(?Tenant $tenant): void
    {
        URL::forceRootUrl($tenant ? rtrim($tenant->url(), '/') : null);
    }
}
