<?php

namespace App\Modules\Fleet\Services;

use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\Vehicle;
use App\Tenancy\TenantContext;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Support\Str;

/**
 * The vehicle record ("ficha") as a PDF: identification, oil change / revision, documents, drivers,
 * the costs of the last 12 months, maintenance of the period and the last inspections.
 */
class VehicleRecord
{
    public const MONTHS = 12;

    public function __construct(protected FleetReports $reports) {}

    /**
     * @return array<string, mixed>
     */
    public function data(Vehicle $vehicle): array
    {
        $timezone = FilamentTimezone::get();
        $from = CarbonImmutable::now($timezone)->startOfMonth()->subMonths(self::MONTHS - 1);

        return [
            'vehicle' => $vehicle,
            'settings' => FleetSetting::current(),
            'company' => app(TenantContext::class)->get()->name,
            'generatedAt' => CarbonImmutable::now($timezone),
            'from' => $from,
            'totals' => $this->reports->totalsBetween($from->utc(), CarbonImmutable::now($timezone)->endOfDay()->utc(), $vehicle->getKey()),
            'documents' => $vehicle->documents()->orderBy('expires_on')->get(),
            'drivers' => $vehicle->drivers()->where('is_active', true)->orderBy('name')->get(),
            'maintenances' => $vehicle->maintenances()->with(['workshop', 'mechanic'])->where('performed_on', '>=', $from->toDateString())->orderByDesc('performed_on')->get(),
            'inspections' => $vehicle->inspections()->with('inspector')->latest('performed_at')->limit(10)->get(),
        ];
    }

    /** The PDF file contents. */
    public function pdf(Vehicle $vehicle): string
    {
        // Only the letters used go inside the file (a few KB instead of the whole font).
        return Pdf::setOption('isFontSubsettingEnabled', true)->loadView('fleet.pdf.vehicle', $this->data($vehicle))->setPaper('a4')->output();
    }

    public function fileName(Vehicle $vehicle): string
    {
        return Str::slug(__('fleet.record.file', ['plate' => $vehicle->plate])).'.pdf';
    }
}
