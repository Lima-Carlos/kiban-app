<?php

namespace App\Http\Middleware;

use App\Licensing\LicenseManager;
use App\Licensing\LicenseState;
use App\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

use function Illuminate\Support\defer;

/**
 * Keeps the license fresh and stops the company's screens when there is no valid license:
 * company deactivated in the Core, or the Core unreachable for longer than the license lasts (72 h).
 */
class EnsureLicense
{
    /** The flag the Core adds to its links into the systems ("?atualizar=1"). */
    public const REFRESH = 'atualizar';

    public function __construct(
        protected TenantContext $context,
        protected LicenseManager $licenses,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $tenant = $this->context->get();

        // The Core's "Open the systems" button (after contracting or testing a part) comes with ?atualizar=1: get the
        // new license right now, so the new part is already in the menu, then show the address without the flag.
        if ($request->isMethod('GET') && $request->query->has(self::REFRESH)) {
            $this->licenses->refreshNow($tenant);

            return redirect()->to($request->fullUrlWithoutQuery(self::REFRESH));
        }

        if ($tenant->license()->isValid()) {
            // Fresh enough? If not, ask the Core after answering, so nobody waits for it.
            defer(fn () => $this->licenses->refreshIfStale($tenant), "license-{$tenant->getKey()}");
        } else {
            // No usable license: try the Core right now (at most once a minute).
            $this->licenses->refreshIfStale($tenant);
        }

        $license = $tenant->license();

        if (! $license->isActive()) {
            $reason = match (true) {
                $license->isValid() => 'inactive',
                $license->state === LicenseState::Expired => 'expired',
                default => 'unavailable',
            };

            return response()->view('tenant.blocked', ['tenant' => $tenant, 'reason' => $reason], 503);
        }

        return $next($request);
    }
}
