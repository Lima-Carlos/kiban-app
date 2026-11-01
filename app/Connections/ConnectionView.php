<?php

namespace App\Connections;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * A person opened the "Integrations" page and saw a connection between systems, in this company.
 */
class ConnectionView extends Model
{
    use BelongsToTenant;

    public const UPDATED_AT = null;

    protected $fillable = ['user_id', 'connection'];
}
