<?php

namespace App\Http\Controllers\Pmoc;

use App\Http\Controllers\Controller;
use App\Models\CompanyProfile;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldNotices;
use App\Modules\Field\Services\WorkOrderService;
use App\Modules\Pmoc\Filament\EquipmentPage;
use App\Modules\Pmoc\Models\Equipment;
use App\Modules\Pmoc\PmocModule;
use App\Modules\Pmoc\Services\PmocService;
use App\Tenancy\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The page the PMOC QR label opens, without login (the link is long and random): the company's identity, what the
 * equipment is, whether the maintenance is up to date, the last one and the responsible technician with the ART.
 * Whoever is in front of it (the customer's staff, an inspector) can ask for service, which becomes a Campo work
 * order for the office. Nothing private is shown: no prices, no addresses, no people other than the responsible one.
 */
class EquipmentPublicController extends Controller
{
    /** Asking for service again on the same equipment while one is open only adds to it. */
    public const OPEN_PER_EQUIPMENT = 1;

    public function show(string $token): View
    {
        return view('pmoc.public', $this->data($this->equipment($token)));
    }

    public function ask(Request $request, string $token, WorkOrderService $orders): RedirectResponse
    {
        $equipment = $this->equipment($token);

        // A field people never see: robots fill it.
        if (filled($request->input('website'))) {
            return redirect()->route('pmoc.public', ['token' => $token]);
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'problem' => ['required', 'string', 'max:1000'],
        ], [], [
            'name' => __('pmoc.public.name'),
            'phone' => __('pmoc.public.phone'),
            'problem' => __('pmoc.public.problem'),
        ]);

        $plan = $equipment->plan;
        $reported = __('pmoc.public.reported', ['tag' => $equipment->tag, 'room' => $equipment->room ?: '—', 'problem' => trim($data['problem']), 'name' => trim($data['name']), 'phone' => trim($data['phone'])]);
        $open = WorkOrder::query()->where('origin', WorkOrder::QR)->where('customer_id', $plan->customer_id)
            ->whereNotIn('status', [OrderStatus::Done, OrderStatus::Canceled])
            ->where('reported', 'like', '%'.$equipment->tag.'%')->latest('id')->first();

        if ($open !== null) {
            $open->forceFill(['reported' => mb_substr($open->reported."\n\n".$reported, 0, 5000)])->save();
            $order = $open;
        } else {
            $order = $orders->open([
                'customer_id' => $plan->customer_id,
                'site_id' => $plan->site_id,
                'service_type_id' => app(PmocService::class)->serviceType($plan)->getKey(),
                'reported' => $reported,
                'priority' => 'high',
                'origin' => WorkOrder::QR,
            ], null);
        }

        app(FieldNotices::class)->office($order, 'pmoc.public.notice', ['tag' => $equipment->tag, 'customer' => $plan->customer?->displayName()], 'pmoc.public.notice_body', ['problem' => mb_substr(trim($data['problem']), 0, 120)], 'heroicon-o-qr-code', 'warning');

        return redirect()->route('pmoc.public', ['token' => $token])->with('pmoc_asked', true);
    }

    protected function equipment(string $token): Equipment
    {
        $license = app(TenantContext::class)->get()->license();
        abort_unless($license->hasModule(PmocModule::KEY) && $license->hasModule(FieldModule::KEY), 404);

        $equipment = Equipment::query()->with(['plan.customer', 'plan.visits.workOrder'])->where('public_token', $token)->first();
        abort_if($equipment === null || ! $equipment->is_active || ! $equipment->plan?->is_active, 404);

        return $equipment;
    }

    /**
     * @return array<string, mixed>
     */
    protected function data(Equipment $equipment): array
    {
        $profile = CompanyProfile::current();
        $plan = $equipment->plan;
        $last = $plan->visits->filter(fn ($visit): bool => $visit->workOrder?->status === OrderStatus::Done && $visit->workOrder->finished_at !== null)
            ->sortByDesc(fn ($visit) => $visit->workOrder->finished_at)->first();

        return [
            'equipment' => $equipment,
            'plan' => $plan,
            'lastDone' => $last?->workOrder->finished_at,
            'upToDate' => ! $plan->next_due_on->isPast() || $plan->next_due_on->isToday(),
            'company' => $profile->name(),
            'logo' => $profile->logoDataUrl(),
            'color' => $profile->color(),
            'staffUrl' => EquipmentPage::getUrl(['equipamento' => $equipment->getKey()]),
        ];
    }
}
