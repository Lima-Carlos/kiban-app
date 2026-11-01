<?php

namespace App\Modules;

use Illuminate\Database\Eloquent\Model;

/**
 * A system of the company and when it first showed up in the license (ModuleArrivals).
 */
class TenantModule extends Model
{
    public $timestamps = false;

    protected $fillable = ['tenant_id', 'module', 'first_seen_at'];

    protected function casts(): array
    {
        return [
            'first_seen_at' => 'immutable_datetime',
        ];
    }
}
