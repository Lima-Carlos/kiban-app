<?php

namespace App\Models;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** A phone (browser) of a person that receives alerts of this company (web push). */
class PushSubscription extends Model
{
    use BelongsToTenant;

    protected $fillable = ['user_id', 'endpoint', 'endpoint_hash', 'public_key', 'auth_token'];

    protected $hidden = ['auth_token'];
}
