<?php

namespace App\Tenancy;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Data of one company. Every query is limited to the current company, and new rows are saved for it.
 * Without a current company, reading or writing fails (MissingTenantException) instead of guessing.
 *
 * @mixin Model
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query): void {
            $query->where($query->qualifyColumn('tenant_id'), app(TenantContext::class)->get()->getKey());
        });

        static::creating(function (Model $model): void {
            $tenant = app(TenantContext::class)->get();

            if ($model->tenant_id !== null && (int) $model->tenant_id !== $tenant->getKey()) {
                throw new MissingTenantException('Record belongs to another company.');
            }

            $model->tenant_id = $tenant->getKey();
        });

        $sameTenant = function (Model $model): void {
            if ($model->isDirty('tenant_id') || (int) $model->tenant_id !== app(TenantContext::class)->get()->getKey()) {
                throw new MissingTenantException('Record belongs to another company.');
            }
        };

        static::updating($sameTenant);
        static::deleting($sameTenant);
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
