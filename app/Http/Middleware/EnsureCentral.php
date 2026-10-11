<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages that only exist on the central address (APP_URL), never on a company address.
 */
class EnsureCentral
{
    public function __construct(protected TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->resolver->isCentral($request->getHost()), 404);

        return $next($request);
    }
}
