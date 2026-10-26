<?php

namespace App\Http\Middleware;

use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pages of the app itself (no company in the address), never inside a company's address.
 */
class EnsureCentral
{
    public function __construct(protected TenantResolver $resolver) {}

    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->resolver->isCentral($request), 404);

        return $next($request);
    }
}
