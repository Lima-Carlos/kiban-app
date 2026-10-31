<?php

namespace App\Connections;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A person ticked "Do not show again" for a connection between systems, in this company.
 */
class ConnectionDismissal extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'connection'];
}
