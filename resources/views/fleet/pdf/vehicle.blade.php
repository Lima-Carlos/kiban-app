@php
    use App\Modules\Fleet\Services\FleetFormat;
    $date = fn ($value) => $value?->translatedFormat(__('app.date_format')) ?? '—';
    $tz = \Filament\Support\Facades\FilamentTimezone::get();
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('fleet.record.title', ['plate' => $vehicle->plate]) }}</title>
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 10px; color: #39383A; margin: 0; }
        h1 { font-size: 18px; color: #044299; margin: 0; }
        h2 { font-size: 12px; color: #044299; border-bottom: 1px solid #044299; padding-bottom: 3px; margin: 16px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; background: #eef2f9; padding: 4px; font-size: 9px; }
        td { padding: 4px; border-bottom: 1px solid #e5e5e5; vertical-align: top; }
        .right { text-align: right; }
        .muted { color: #777; }
        .grid td { border: none; padding: 2px 4px; width: 25%; }
        .label { color: #777; font-size: 8px; text-transform: uppercase; }
        .big { font-size: 13px; font-weight: bold; }
        .badge { padding: 1px 5px; border-radius: 3px; font-size: 9px; }
        .danger { background: #fde2e2; color: #b42318; }
        .warning { background: #fef0c7; color: #93370d; }
        .success { background: #dcfae6; color: #067647; }
        .gray { background: #eee; color: #555; }
        .info { background: #e0eaff; color: #044299; }
        .footer { position: fixed; bottom: -10px; left: 0; right: 0; font-size: 8px; color: #999; }
    </style>
</head>
<body>
    <div class="footer">{{ __('fleet.record.footer', ['company' => $company, 'date' => $generatedAt->translatedFormat(__('app.datetime_format'))]) }}</div>

    <table>
        <tr>
            <td style="border:none;padding:0">
                <h1>{{ $vehicle->plate }} · {{ $vehicle->brand }} {{ $vehicle->model }}</h1>
                <div class="muted">{{ __('fleet.record.title', ['plate' => $vehicle->plate]) }} · {{ $company }}</div>
            </td>
            <td style="border:none;padding:0;text-align:right">
                <img src="{{ public_path('images/brand/logo.png') }}" style="height:28px" alt="">
            </td>
        </tr>
    </table>

    <h2>{{ __('fleet.record.identification') }}</h2>
    <table class="grid">
        <tr>
            <td><div class="label">{{ __('fleet.fields.status') }}</div><span class="badge {{ $vehicle->status->getColor() }}">{{ $vehicle->status->getLabel() }}</span></td>
            <td><div class="label">{{ __('fleet.fields.odometer') }}</div><span class="big">{{ FleetFormat::distance($vehicle->odometer, $settings) }}</span></td>
            <td><div class="label">{{ __('fleet.fields.type') }}</div>{{ $vehicle->type?->getLabel() ?? '—' }}</td>
            <td><div class="label">{{ __('fleet.fields.fuel_type') }}</div>{{ $vehicle->fuel_type?->getLabel() ?? '—' }}</td>
        </tr>
        <tr>
            <td><div class="label">{{ __('fleet.fields.year') }}</div>{{ $vehicle->manufacture_year ?? '—' }}{{ $vehicle->model_year ? '/'.$vehicle->model_year : '' }}</td>
            <td><div class="label">{{ __('fleet.fields.color') }}</div>{{ $vehicle->color ?? '—' }}</td>
            <td><div class="label">{{ __('fleet.fields.vin') }}</div>{{ $vehicle->vin ?? '—' }}</td>
            <td><div class="label">{{ __('fleet.fields.registration') }}</div>{{ $vehicle->registration ?? '—' }}</td>
        </tr>
        <tr>
            <td><div class="label">{{ __('fleet.fields.tank_capacity') }}</div>{{ $vehicle->tank_capacity ? FleetFormat::volume((float) $vehicle->tank_capacity, $settings) : '—' }}</td>
            <td colspan="3"><div class="label">{{ __('fleet.record.drivers') }}</div>{{ $drivers->pluck('name')->implode(', ') ?: '—' }}</td>
        </tr>
    </table>

    <h2>{{ __('fleet.record.services') }}</h2>
    <table>
        <tr><th>{{ __('fleet.record.service') }}</th><th>{{ __('fleet.record.next_at') }}</th><th>{{ __('fleet.record.next_on') }}</th><th>{{ __('fleet.fields.status') }}</th></tr>
        @foreach (['oil_change' => $vehicle->oilChangeStatus($settings), 'revision' => $vehicle->revisionStatus($settings)] as $service => $status)
            <tr>
                <td>{{ __('fleet.enums.maintenance_item.'.$service) }}</td>
                <td>{{ FleetFormat::distance($vehicle->{"next_{$service}_odometer"}, $settings) }}</td>
                <td>{{ $date($vehicle->{"next_{$service}_on"}) }}</td>
                <td><span class="badge {{ $status->getColor() }}">{{ $status->getLabel() }}</span></td>
            </tr>
        @endforeach
    </table>

    <h2>{{ __('fleet.record.documents') }}</h2>
    @if ($documents->isEmpty())
        <p class="muted">{{ __('fleet.record.none') }}</p>
    @else
        <table>
            <tr><th>{{ __('fleet.fields.document_type') }}</th><th>{{ __('fleet.record.number') }}</th><th>{{ __('fleet.record.expires_on') }}</th><th>{{ __('fleet.fields.status') }}</th></tr>
            @foreach ($documents as $document)
                @php($status = $document->dueStatus($settings))
                <tr>
                    <td>{{ $document->type->getLabel() }}</td>
                    <td>{{ $document->number ?? '—' }}</td>
                    <td>{{ $date($document->expires_on) }}</td>
                    <td><span class="badge {{ $status->getColor() }}">{{ $status->getLabel() }}</span></td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('fleet.record.costs', ['from' => $from->translatedFormat('M/Y')]) }}</h2>
    <table>
        <tr>
            <th>{{ __('fleet.reports.kind.fuel') }}</th><th>{{ __('fleet.reports.kind.maintenance') }}</th><th>{{ __('fleet.reports.kind.wash') }}</th>
            <th>{{ __('fleet.reports.kind.expense') }}</th><th>{{ __('fleet.reports.total') }}</th><th>{{ __('fleet.record.consumption') }}</th><th>{{ __('fleet.record.cost_per_distance') }}</th>
        </tr>
        <tr>
            <td>{{ FleetFormat::money($totals['spent_cents'], $settings) }}</td>
            <td>{{ FleetFormat::money($totals['maintenance_cents'], $settings) }}</td>
            <td>{{ FleetFormat::money($totals['wash_cents'], $settings) }}</td>
            <td>{{ FleetFormat::money($totals['expense_cents'], $settings) }}</td>
            <td><strong>{{ FleetFormat::money($totals['total_cents'], $settings) }}</strong></td>
            <td>{{ FleetFormat::consumption($totals['consumption'], $settings) }}</td>
            <td>{{ $totals['cost_per_distance_cents'] !== null ? FleetFormat::money($totals['cost_per_distance_cents'], $settings).' / '.$settings->distance_unit : '—' }}</td>
        </tr>
    </table>

    <h2>{{ __('fleet.record.maintenances') }}</h2>
    @if ($maintenances->isEmpty())
        <p class="muted">{{ __('fleet.record.none') }}</p>
    @else
        <table>
            <tr><th>{{ __('fleet.fields.date') }}</th><th>{{ __('fleet.fields.maintenance_type') }}</th><th>{{ __('fleet.fields.services') }}</th><th>{{ __('fleet.fields.who_did') }}</th><th class="right">{{ __('fleet.fields.odometer') }}</th><th class="right">{{ __('fleet.reports.total') }}</th></tr>
            @foreach ($maintenances as $maintenance)
                <tr>
                    <td>{{ $date($maintenance->performed_on) }}</td>
                    <td>{{ $maintenance->type->getLabel() }}</td>
                    <td>{{ collect($maintenance->items())->map->getLabel()->implode(', ') }}@if ($maintenance->description)<br><span class="muted">{{ $maintenance->description }}</span>@endif</td>
                    <td>{{ $maintenance->workshop?->name ?? $maintenance->mechanic?->name ?? '—' }}</td>
                    <td class="right">{{ FleetFormat::distance($maintenance->odometer, $settings) }}</td>
                    <td class="right">{{ FleetFormat::money($maintenance->total_cents, $settings) }}</td>
                </tr>
            @endforeach
        </table>
    @endif

    <h2>{{ __('fleet.record.inspections') }}</h2>
    @if ($inspections->isEmpty())
        <p class="muted">{{ __('fleet.record.none') }}</p>
    @else
        <table>
            <tr><th>{{ __('fleet.fields.date') }}</th><th>{{ __('fleet.fields.who_did') }}</th><th class="right">{{ __('fleet.fields.odometer') }}</th><th>{{ __('fleet.inspections.result') }}</th></tr>
            @foreach ($inspections as $inspection)
                <tr>
                    <td>{{ $inspection->performed_at->timezone($tz)->translatedFormat(__('app.datetime_format')) }}</td>
                    <td>{{ $inspection->inspector?->name ?? '—' }}</td>
                    <td class="right">{{ FleetFormat::distance($inspection->odometer, $settings) }}</td>
                    <td>
                        @if ($inspection->problems === 0)
                            <span class="badge success">{{ __('fleet.inspections.all_ok') }}</span>
                        @else
                            <span class="badge {{ $inspection->has_critical ? 'danger' : 'warning' }}">{{ trans_choice('fleet.inspections.problems_count', $inspection->problems, ['count' => $inspection->problems]) }}</span>
                            <span class="muted">{{ collect($inspection->problemAnswers())->pluck('label')->implode(', ') }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </table>
    @endif
</body>
</html>
