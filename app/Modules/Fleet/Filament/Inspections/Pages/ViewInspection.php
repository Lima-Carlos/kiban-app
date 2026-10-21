<?php

namespace App\Modules\Fleet\Filament\Inspections\Pages;

use App\Modules\Fleet\Filament\Inspections\InspectionResource;
use App\Modules\Fleet\Models\Inspection;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

class ViewInspection extends ViewRecord
{
    protected static string $resource = InspectionResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Inspection $inspection */
        $inspection = $this->getRecord();

        return __('fleet.inspections.title_for', ['plate' => $inspection->vehicle->plate]);
    }
}
