<?php

namespace App\Http\Controllers\Fleet;

use App\Http\Controllers\Controller;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Services\FuelService;
use App\Tenancy\TenantContext;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Page for the fuel station (no login): the attendant types the driver's code and sees if it is valid,
 * the plate, the driver, the fuel allowed and the tank capacity. Limited attempts per minute.
 */
class StationCheckController extends Controller
{
    public function show(TenantContext $context): View
    {
        abort_unless($context->get()->license()->hasModule(FleetModule::KEY), 404);

        return view('fleet.station-check', ['tenant' => $context->get(), 'fuelUp' => null, 'checked' => false, 'code' => null]);
    }

    public function check(Request $request, TenantContext $context, FuelService $fuel): View
    {
        abort_unless($context->get()->license()->hasModule(FleetModule::KEY), 404);

        $code = (string) $request->validate(['code' => ['required', 'string', 'max:20']])['code'];

        return view('fleet.station-check', [
            'tenant' => $context->get(),
            'fuelUp' => $fuel->findForStation($code),
            'checked' => true,
            'code' => FuelService::normalizeCode($code),
        ]);
    }
}
