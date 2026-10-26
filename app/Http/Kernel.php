<?php

namespace App\Http;

use App\Tenancy\TenantPath;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

/**
 * The entrance of every visit: the company of the path (single address) is read before anything else runs.
 */
class Kernel extends HttpKernel
{
    public function handle($request)
    {
        return parent::handle($this->app->make(TenantPath::class)->apply($request));
    }
}
