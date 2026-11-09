<?php

namespace App\Http\Controllers;

use App\Exports\CompanyExport;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The company's "download everything" file: only the owner and the administrators, only their company's folder.
 */
class CompanyExportController extends Controller
{
    public function __invoke(string $file, TenantContext $context): BinaryFileResponse
    {
        abort_unless((bool) auth()->user()?->canManageTenant(), 403);
        abort_unless(preg_match('/^kiban-dados-[a-z0-9-]+\.zip$/', $file) === 1, 404);

        $path = CompanyExport::folder($context->get()).'/'.$file;
        abort_unless(Storage::disk('local')->exists($path), 404);

        return response()->download(Storage::disk('local')->path($path), $file);
    }
}
