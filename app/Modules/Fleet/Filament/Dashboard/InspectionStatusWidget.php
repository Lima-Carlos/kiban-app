<?php

namespace App\Modules\Fleet\Filament\Dashboard;

use App\Modules\Fleet\Enums\InspectionFrequency;
use App\Modules\Fleet\Enums\RequestStatus;
use App\Modules\Fleet\Models\ServiceRequest;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\InspectionService;
use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Collection;

/**
 * Inspections right now: which vehicles are still missing the inspection of the period and which
 * problems found in inspections are still open. It does not follow the period filter of the dashboard.
 */
class InspectionStatusWidget extends Widget
{
    protected string $view = 'fleet.dashboard.inspection-status';

    protected int|string|array $columnSpan = 'full';

    /** Received from the start page; not used here. @var array<string, mixed>|null */
    public ?array $pageFilters = null;

    public function frequency(): InspectionFrequency
    {
        return app(InspectionService::class)->frequency();
    }

    /**
     * @return Collection<int, Vehicle>
     */
    public function dueVehicles(): Collection
    {
        return app(InspectionService::class)->dueVehicles();
    }

    public function activeVehicles(): int
    {
        return Vehicle::query()->where('status', 'active')->count();
    }

    /**
     * @return Collection<int, ServiceRequest>
     */
    public function openProblems(): Collection
    {
        return ServiceRequest::query()
            ->with(['vehicle', 'inspection'])
            ->whereNotNull('inspection_id')
            ->whereIn('status', [RequestStatus::Open->value, RequestStatus::Scheduled->value])
            ->latest()
            ->limit(15)
            ->get();
    }
}
