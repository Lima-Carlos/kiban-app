<?php

namespace App\Http\Controllers\Field;

use App\Http\Controllers\Controller;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Services\CustomerPortal;
use App\Tenancy\TenantContext;
use Illuminate\Contracts\View\View;

/**
 * The end customer's page (no login): /cliente/{token} on the company's address (CustomerPortal).
 */
class CustomerPortalController extends Controller
{
    public function show(string $token, TenantContext $context, CustomerPortal $portal): View
    {
        abort_unless($context->get()->license()->hasModule(FieldModule::KEY), 404);

        $customer = Customer::query()->where('portal_token', $token)->where('is_active', true)->firstOrFail();

        return view('field.portal', $portal->data($customer));
    }
}
