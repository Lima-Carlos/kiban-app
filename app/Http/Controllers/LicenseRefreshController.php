<?php

namespace App\Http\Controllers;

use App\Licensing\LicenseManager;
use App\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;

/**
 * "A new part appears right away": when the person comes back to the tab (e.g. after contracting or testing another
 * part in the Customer area of the Core), the page asks for a fresh license here; if the systems changed, the page
 * reloads by itself. At most one real refresh every 15 seconds per company.
 */
class LicenseRefreshController extends Controller
{
    public function __invoke(TenantContext $context, LicenseManager $licenses): JsonResponse
    {
        $tenant = $context->get();
        $before = array_keys($tenant->license()->modules());

        $licenses->refreshNow($tenant);
        $after = array_keys($tenant->license()->modules());

        sort($before);
        sort($after);

        return response()->json(['changed' => $before !== $after]);
    }
}
