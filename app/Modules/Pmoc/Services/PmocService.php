<?php

namespace App\Modules\Pmoc\Services;

use App\Models\CompanyProfile;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\WorkOrderService;
use App\Modules\Pmoc\Models\Plan;
use App\Modules\Pmoc\Models\Visit;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The rules of the maintenance plans: when the next visit is due, the work order of each visit (opened in the
 * Campo some days before, with the activities of its level as the checklist) and the PMOC document in PDF.
 */
class PmocService
{
    /** At most this many visits are opened per plan in one run (a plan left behind does not flood the agenda). */
    public const CATCH_UP = 3;

    public function __construct(protected PmocActivities $activities, protected WorkOrderService $orders) {}

    /** Opens the work orders of the visits that are close enough (lead days). Returns how many were opened. */
    public function openDueVisits(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::now(FilamentTimezone::get())->startOfDay();
        $opened = 0;

        Plan::query()->active()->with(['customer', 'equipment'])->orderBy('id')->each(function (Plan $plan) use ($today, &$opened): void {
            for ($i = 0; $i < self::CATCH_UP; $i++) {
                if ($plan->next_due_on->copy()->subDays($plan->lead_days)->gt($today) || ! $plan->customer?->is_active) {
                    return;
                }

                $this->openVisit($plan);
                $opened++;
            }
        });

        return $opened;
    }

    /** Opens the next visit of the plan now (also used by "Open the next visit now") and moves the plan forward. */
    public function openVisit(Plan $plan): Visit
    {
        return DB::transaction(function () use ($plan): Visit {
            $due = CarbonImmutable::parse($plan->next_due_on->toDateString());
            $months = (int) CarbonImmutable::parse($plan->starts_on->toDateString())->diffInMonths($due);
            $level = $this->activities->level(max(0, $months), (int) $plan->frequency_months);
            $equipment = $plan->equipment()->where('is_active', true)->orderBy('tag')->get();

            $order = $this->orders->open([
                'customer_id' => $plan->customer_id,
                'site_id' => $plan->site_id,
                'service_type_id' => $this->serviceType($plan)->getKey(),
                'reported' => __('pmoc.visit.reported', ['level' => __('pmoc.levels.'.$level), 'plan' => $plan->name, 'date' => $due->translatedFormat(__('app.date_format')), 'count' => $equipment->count()]),
                'internal_notes' => $equipment->map(fn ($item): string => '• '.collect([$item->tag, $item->room, $item->brand, $item->model, $item->serial ? __('pmoc.visit.serial', ['serial' => $item->serial]) : null])->filter()->implode(' · '))->implode("\n"),
                'origin' => WorkOrder::PMOC,
            ], null);

            // The visit's own activities (the type's checklist is only the default of manual orders).
            $order->forceFill(['checklist' => $this->activities->checklist($level)])->save();

            $visit = Visit::query()->create(['plan_id' => $plan->getKey(), 'due_on' => $due->toDateString(), 'level' => $level, 'work_order_id' => $order->getKey()]);
            $plan->forceFill(['next_due_on' => $due->addMonthsNoOverflow((int) $plan->frequency_months)->toDateString()])->save();

            return $visit;
        });
    }

    /** The service type of the visits: the plan's, or "Preventive maintenance (PMOC)" created once. */
    public function serviceType(Plan $plan): ServiceType
    {
        if ($plan->serviceType?->is_active) {
            return $plan->serviceType;
        }

        return ServiceType::query()->firstOrCreate(
            ['name' => __('pmoc.service_type')],
            ['description' => __('pmoc.service_type_help'), 'duration_minutes' => 90, 'checklist' => [], 'signature_required' => true, 'photo_required' => false, 'color' => '#0E7490'],
        );
    }

    /**
     * Everything the PMOC document shows.
     *
     * @return array<string, mixed>
     */
    public function document(Plan $plan): array
    {
        $profile = CompanyProfile::current();
        $plan->loadMissing(['customer', 'site', 'equipment', 'visits.workOrder.technician']);

        return [
            'plan' => $plan,
            'company' => $profile->name(),
            'companyDocument' => $profile->document,
            'logo' => $profile->logoDataUrl(),
            'color' => $profile->color(),
            'equipment' => $plan->equipment->where('is_active', true)->sortBy('tag')->values(),
            'activities' => $this->activities->schedule(),
            'visits' => $plan->visits->take(24),
            'thermalLoad' => $plan->thermalLoad(),
            'issuedAt' => CarbonImmutable::now(FilamentTimezone::get()),
        ];
    }

    public function pdf(Plan $plan): string
    {
        return Pdf::setOption('isFontSubsettingEnabled', true)->loadView('pmoc.document-pdf', $this->document($plan))->setPaper('a4')->output();
    }

    public function fileName(Plan $plan): string
    {
        return Str::slug(__('pmoc.document.file', ['name' => $plan->name])).'.pdf';
    }
}
