<?php

namespace App\Modules\Fleet\Services;

use App\Modules\Fleet\Enums\FuelUpFlag;
use App\Modules\Fleet\Enums\MaintenanceItem;
use App\Modules\Fleet\Models\Expense;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\Fleet\Models\Inspection;
use App\Modules\Fleet\Models\Maintenance;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\Wash;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Excel spreadsheets of the Frota (period in the company time zone, all vehicles or one):
 * fuel-ups, costs (every cost + a summary per vehicle), maintenance and inspections.
 * Money goes as numbers (not text), so the spreadsheet can add them up.
 */
class FleetExporter
{
    public const REPORTS = ['fuel_ups', 'costs', 'maintenances', 'inspections'];

    public function __construct(protected FleetReports $reports) {}

    /** Path of the .xlsx file (deleted after the download). */
    public function export(string $report, string $from, string $to, ?int $vehicleId = null): string
    {
        [$start, $end] = $this->limits($from, $to);

        $sheets = match ($report) {
            'fuel_ups' => $this->fuelUps($start, $end, $vehicleId),
            'costs' => $this->costs($start, $end, $vehicleId),
            'maintenances' => $this->maintenances($from, $to, $vehicleId),
            'inspections' => $this->inspections($start, $end, $vehicleId),
        };

        return $this->write(__("fleet.reports.file.{$report}", ['from' => $from, 'to' => $to]), $sheets);
    }

    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    protected function fuelUps(CarbonImmutable $start, CarbonImmutable $end, ?int $vehicleId): array
    {
        $settings = FleetSetting::current();
        $fuelUps = FuelUp::query()->completed()->with(['vehicle', 'driver', 'station', 'creator'])
            ->whereBetween('fueled_at', [$start, $end])
            ->when($vehicleId, fn (Builder $query) => $query->where('vehicle_id', $vehicleId))
            ->orderBy('fueled_at')
            ->get();

        $rows = [[
            __('fleet.fields.date'), __('fleet.fields.vehicle'), __('fleet.fields.driver'), __('fleet.stations.singular_title'),
            __('fleet.fields.fuel'), __('fleet.reports.volume', ['unit' => $settings->volume_unit]), __('fleet.reports.unit_price'),
            __('fleet.reports.amount', ['currency' => $settings->currency]), __('fleet.fields.odometer'),
            __('fleet.reports.distance', ['unit' => $settings->distance_unit]), __('fleet.reports.consumption'),
            __('fleet.reports.full_tank'), __('fleet.reports.warnings'),
        ]];

        foreach ($fuelUps as $fuelUp) {
            $rows[] = [
                $this->dateTime($fuelUp->fueled_at),
                $fuelUp->vehicle->plate,
                $fuelUp->driver?->name ?? $fuelUp->creator?->name,
                $fuelUp->station?->name,
                $fuelUp->fuel?->getLabel(),
                (float) $fuelUp->volume,
                (float) $fuelUp->unit_price,
                $this->money($fuelUp->total_cents),
                $fuelUp->odometer,
                $fuelUp->distance,
                $fuelUp->consumption !== null ? (float) $fuelUp->consumption : null,
                $fuelUp->full_tank ? __('fleet.reports.yes') : __('fleet.reports.no'),
                collect($fuelUp->flagList())->map(fn (FuelUpFlag $flag): string => $flag->getLabel())->implode(', '),
            ];
        }

        $rows[] = [];
        $rows[] = ['__bold__', __('fleet.reports.total'), '', '', '', '', (float) $fuelUps->sum('volume'), null, $this->money((int) $fuelUps->sum('total_cents'))];

        return [__('fleet.fuel.plural_title') => $rows];
    }

    /**
     * Every cost, one per line, and a summary per vehicle (same numbers as the dashboard).
     *
     * @return array<string, array<int, array<int, mixed>>>
     */
    protected function costs(CarbonImmutable $start, CarbonImmutable $end, ?int $vehicleId): array
    {
        $settings = FleetSetting::current();
        [$fromDate, $toDate] = [$start->timezone(FilamentTimezone::get())->toDateString(), $end->timezone(FilamentTimezone::get())->toDateString()];
        $vehicle = fn (Builder $query) => $vehicleId ? $query->where('vehicle_id', $vehicleId) : $query;
        $lines = collect();

        foreach ($vehicle(FuelUp::query()->completed()->with(['vehicle', 'station'])->whereBetween('fueled_at', [$start, $end]))->get() as $fuelUp) {
            $lines->push([$fuelUp->fueled_at->timezone(FilamentTimezone::get()), $fuelUp->vehicle->plate, __('fleet.reports.kind.fuel'), $fuelUp->station?->name, $fuelUp->total_cents]);
        }

        foreach ($vehicle(Maintenance::query()->with('vehicle')->whereBetween('performed_on', [$fromDate, $toDate]))->get() as $maintenance) {
            $lines->push([$maintenance->performed_on, $maintenance->vehicle->plate, __('fleet.reports.kind.maintenance'), $this->services($maintenance), $maintenance->total_cents]);
        }

        foreach ($vehicle(Wash::query()->with('vehicle')->whereBetween('washed_on', [$fromDate, $toDate]))->get() as $wash) {
            $lines->push([$wash->washed_on, $wash->vehicle->plate, __('fleet.reports.kind.wash'), $wash->kind->getLabel(), $wash->cost_cents]);
        }

        foreach ($vehicle(Expense::query()->with('vehicle')->whereBetween('spent_on', [$fromDate, $toDate]))->get() as $expense) {
            $lines->push([$expense->spent_on, $expense->vehicle?->plate ?? __('fleet.helpers.whole_fleet'), __('fleet.reports.kind.expense'), trim($expense->category->getLabel().' '.$expense->description), $expense->amount_cents]);
        }

        $rows = [[__('fleet.fields.date'), __('fleet.fields.vehicle'), __('fleet.reports.kind_title'), __('fleet.fields.description'), __('fleet.reports.amount', ['currency' => $settings->currency])]];

        foreach ($lines->sortBy(fn (array $line): string => $line[0]->format('Y-m-d H:i')) as $line) {
            $rows[] = [$this->date($line[0]), $line[1], $line[2], $line[3], $this->money($line[4])];
        }

        $rows[] = [];
        $rows[] = ['__bold__', __('fleet.reports.total'), '', '', '', $this->money((int) $lines->sum(fn (array $line): int => $line[4]))];

        $summary = [[
            __('fleet.fields.vehicle'), __('fleet.reports.kind.fuel'), __('fleet.reports.kind.maintenance'), __('fleet.reports.kind.wash'),
            __('fleet.reports.kind.expense'), __('fleet.reports.total'), __('fleet.reports.distance', ['unit' => $settings->distance_unit]),
            __('fleet.reports.cost_per_distance', ['unit' => $settings->distance_unit]),
        ]];
        $vehicles = Vehicle::query()->when($vehicleId, fn (Builder $query) => $query->whereKey($vehicleId))->orderBy('plate')->get();

        foreach ($vehicles as $item) {
            $totals = $this->reports->totalsBetween($start, $end, $item->getKey());

            if ($totals['total_cents'] === 0 && $totals['distance'] === 0) {
                continue;
            }

            $summary[] = [
                $item->plate,
                $this->money($totals['spent_cents']),
                $this->money($totals['maintenance_cents']),
                $this->money($totals['wash_cents']),
                $this->money($totals['expense_cents']),
                $this->money($totals['total_cents']),
                $totals['distance'],
                $totals['cost_per_distance_cents'] !== null ? $this->money($totals['cost_per_distance_cents']) : null,
            ];
        }

        return [__('fleet.reports.sheet.costs') => $rows, __('fleet.reports.sheet.summary') => $summary];
    }

    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    protected function maintenances(string $from, string $to, ?int $vehicleId): array
    {
        $settings = FleetSetting::current();
        $maintenances = Maintenance::query()->with(['vehicle', 'workshop', 'mechanic'])
            ->whereBetween('performed_on', [$from, $to])
            ->when($vehicleId, fn (Builder $query) => $query->where('vehicle_id', $vehicleId))
            ->orderBy('performed_on')
            ->get();

        $currency = $settings->currency;
        $rows = [[
            __('fleet.fields.date'), __('fleet.fields.vehicle'), __('fleet.fields.maintenance_type'), __('fleet.fields.services'),
            __('fleet.fields.what_was_done'), __('fleet.fields.who_did'), __('fleet.fields.odometer'),
            __('fleet.reports.money_of', ['field' => __('fleet.fields.parts'), 'currency' => $currency]),
            __('fleet.reports.money_of', ['field' => __('fleet.fields.labor'), 'currency' => $currency]),
            __('fleet.reports.amount', ['currency' => $currency]),
        ]];

        foreach ($maintenances as $maintenance) {
            $rows[] = [
                $this->date($maintenance->performed_on),
                $maintenance->vehicle->plate,
                $maintenance->type->getLabel(),
                $this->services($maintenance),
                $maintenance->description,
                $maintenance->workshop?->name ?? $maintenance->mechanic?->name,
                $maintenance->odometer,
                $this->money($maintenance->parts_cents),
                $this->money($maintenance->labor_cents),
                $this->money($maintenance->total_cents),
            ];
        }

        $rows[] = [];
        $rows[] = ['__bold__', __('fleet.reports.total'), '', '', '', '', '', '', $this->money((int) $maintenances->sum('parts_cents')), $this->money((int) $maintenances->sum('labor_cents')), $this->money((int) $maintenances->sum('total_cents'))];

        return [__('fleet.maintenances.plural_title') => $rows];
    }

    /**
     * @return array<string, array<int, array<int, mixed>>>
     */
    protected function inspections(CarbonImmutable $start, CarbonImmutable $end, ?int $vehicleId): array
    {
        $inspections = Inspection::query()->with(['vehicle', 'inspector'])
            ->whereBetween('performed_at', [$start, $end])
            ->when($vehicleId, fn (Builder $query) => $query->where('vehicle_id', $vehicleId))
            ->orderBy('performed_at')
            ->get();

        $rows = [[__('fleet.fields.date'), __('fleet.fields.vehicle'), __('fleet.fields.who_did'), __('fleet.fields.odometer'), __('fleet.inspections.result'), __('fleet.reports.problems')]];

        foreach ($inspections as $inspection) {
            $rows[] = [
                $this->dateTime($inspection->performed_at),
                $inspection->vehicle->plate,
                $inspection->inspector?->name,
                $inspection->odometer,
                $inspection->problems === 0 ? __('fleet.inspections.all_ok') : trans_choice('fleet.inspections.problems_count', $inspection->problems, ['count' => $inspection->problems]),
                collect($inspection->problemAnswers())->map(fn (array $answer): string => "{$answer['label']}: {$answer['note']}")->implode(' · '),
            ];
        }

        return [__('fleet.inspections.plural_title') => $rows];
    }

    /**
     * Start and end of the chosen days in the company time zone, in UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    protected function limits(string $from, string $to): array
    {
        $timezone = FilamentTimezone::get();

        return [
            CarbonImmutable::parse($from, $timezone)->startOfDay()->utc(),
            CarbonImmutable::parse($to, $timezone)->endOfDay()->utc(),
        ];
    }

    protected function services(Maintenance $maintenance): string
    {
        return collect($maintenance->items())->map(fn (MaintenanceItem $item): string => $item->getLabel())->implode(', ');
    }

    protected function date(?CarbonInterface $date): ?string
    {
        return $date?->translatedFormat(__('app.date_format'));
    }

    protected function dateTime(?CarbonInterface $date): ?string
    {
        return $date?->timezone(FilamentTimezone::get())->translatedFormat(__('app.datetime_format'));
    }

    protected function money(?int $cents): ?float
    {
        return $cents === null ? null : round($cents / 100, 2);
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets  first row of each sheet = header; '__bold__' marks a bold row
     */
    protected function write(string $fileName, array $sheets): string
    {
        $directory = storage_path('app/private/exports');

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }

        $path = $directory.'/'.Str::slug($fileName).'-'.Str::random(6).'.xlsx';
        $bold = (new Style)->setFontBold();
        $number = (new Style)->setFormat('#,##0.00');
        $numberBold = (new Style)->setFormat('#,##0.00')->setFontBold();

        $writer = new Writer;
        $writer->openToFile($path);
        $first = true;

        foreach ($sheets as $name => $rows) {
            $sheet = $first ? $writer->getCurrentSheet() : $writer->addNewSheetAndMakeItCurrent();
            $sheet->setName(mb_substr(str_replace(['/', '\\', '?', '*', '[', ']', ':'], '-', $name), 0, 31));
            $first = false;

            foreach ($rows as $index => $values) {
                $isBold = $index === 0 || (($values[0] ?? null) === '__bold__');

                if (($values[0] ?? null) === '__bold__') {
                    array_shift($values);
                }

                $cells = array_map(
                    fn (mixed $value): Cell => Cell::fromValue($value, is_float($value) ? ($isBold ? $numberBold : $number) : ($isBold ? $bold : null)),
                    array_values($values),
                );

                $writer->addRow(new Row($cells));
            }
        }

        $writer->close();

        return $path;
    }
}
