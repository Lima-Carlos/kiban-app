<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('pmoc.document.title') }} · {{ $plan->name }}</title>
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 9.5px; color: #39383A; margin: 0; }
        .top { background: {{ $color }}; color: #fff; padding: 12px 16px; }
        .top td { color: #fff; vertical-align: middle; }
        h1 { font-size: 15px; margin: 2px 0 0; }
        h2 { font-size: 11px; color: {{ $color }}; margin: 14px 0 5px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
        .body { padding: 6px 16px; }
        .label { color: #777; font-size: 8px; }
        table.info { width: 100%; border-collapse: collapse; }
        table.info td { padding: 3px 6px 3px 0; vertical-align: top; width: 33%; }
        table.list { width: 100%; border-collapse: collapse; }
        table.list th { text-align: left; font-size: 8px; color: #777; border-bottom: 1px solid #ccc; padding: 3px 4px; }
        table.list td { border-bottom: 1px solid #eee; padding: 3px 4px; vertical-align: top; }
        .mark { text-align: center; }
        .sign { margin-top: 28px; width: 100%; }
        .sign td { width: 50%; padding-top: 26px; text-align: center; }
        .sign div { border-top: 1px solid #555; margin: 0 20px; padding-top: 3px; }
        .footer { margin-top: 16px; font-size: 7.5px; color: #666; border-top: 1px solid #ddd; padding-top: 6px; }
    </style>
</head>
<body>
    <div class="top">
        <table style="width:100%"><tr>
            <td>
                <div style="font-size:9px">{{ __('pmoc.document.title') }}</div>
                <h1>{{ $plan->name }}</h1>
            </td>
            <td style="text-align:right">
                @if ($logo)<img src="{{ $logo }}" style="max-height:44px;max-width:150px;background:#fff;padding:4px">@else<strong>{{ $company }}</strong>@endif
            </td>
        </tr></table>
    </div>
    <div class="body">
        <h2>{{ __('pmoc.document.identification') }}</h2>
        <table class="info">
            <tr>
                <td><div class="label">{{ __('pmoc.fields.customer') }}</div>{{ $plan->customer?->displayName() }}</td>
                <td colspan="2"><div class="label">{{ __('pmoc.fields.site') }}</div>{{ $plan->site?->address() ?: '—' }}</td>
            </tr>
            <tr>
                <td><div class="label">{{ __('pmoc.fields.area_m2') }}</div>{{ $plan->area_m2 ? number_format($plan->area_m2, 0, ',', '.').' m²' : '—' }}</td>
                <td><div class="label">{{ __('pmoc.fields.occupants') }}</div>{{ $plan->occupants ?: '—' }}</td>
                <td><div class="label">{{ __('pmoc.document.thermal_load') }}</div>{{ $thermalLoad ? number_format($thermalLoad, 0, ',', '.').' BTU/h' : '—' }}</td>
            </tr>
            <tr>
                <td><div class="label">{{ __('pmoc.document.company') }}</div>{{ $company }}@if ($companyDocument) · {{ $companyDocument }}@endif</td>
                <td><div class="label">{{ __('pmoc.fields.responsible_name') }}</div>{{ $plan->responsible_name ?: '—' }}</td>
                <td><div class="label">{{ __('pmoc.fields.responsible_registration') }}</div>{{ $plan->responsible_registration ?: '—' }}@if ($plan->art_number)<br>{{ __('pmoc.fields.art_number') }}: {{ $plan->art_number }}@endif</td>
            </tr>
            <tr>
                <td><div class="label">{{ __('pmoc.fields.frequency') }}</div>{{ trans_choice('pmoc.frequency', $plan->frequency_months, ['count' => $plan->frequency_months]) }}</td>
                <td><div class="label">{{ __('pmoc.fields.starts_on') }}</div>{{ $plan->starts_on->translatedFormat(__('app.date_format')) }}</td>
                <td><div class="label">{{ __('pmoc.fields.next_due_on') }}</div>{{ $plan->next_due_on->translatedFormat(__('app.date_format')) }}</td>
            </tr>
        </table>

        <h2>{{ __('pmoc.document.equipment') }} ({{ $equipment->count() }})</h2>
        <table class="list">
            <thead><tr>
                <th>{{ __('pmoc.fields.tag') }}</th><th>{{ __('pmoc.fields.kind') }}</th><th>{{ __('pmoc.fields.room') }}</th>
                <th>{{ __('pmoc.fields.brand') }} / {{ __('pmoc.fields.model') }}</th><th>{{ __('pmoc.fields.serial') }}</th>
                <th>BTU/h</th><th>{{ __('pmoc.fields.refrigerant') }}</th>
            </tr></thead>
            <tbody>
                @forelse ($equipment as $item)
                    <tr>
                        <td>{{ $item->tag }}</td>
                        <td>{{ __('pmoc.kinds.'.$item->kind) }}</td>
                        <td>{{ $item->room ?: '—' }}</td>
                        <td>{{ collect([$item->brand, $item->model])->filter()->implode(' / ') ?: '—' }}</td>
                        <td>{{ $item->serial ?: '—' }}</td>
                        <td>{{ $item->capacity_btu ? number_format($item->capacity_btu, 0, ',', '.') : '—' }}</td>
                        <td>{{ $item->refrigerant === 'other' ? __('pmoc.other') : ($item->refrigerant ?: '—') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7">{{ __('pmoc.document.no_equipment') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        <h2>{{ __('pmoc.document.activities') }}</h2>
        <table class="list">
            <thead><tr>
                <th>{{ __('pmoc.document.activity') }}</th>
                @foreach ([1, 3, 6, 12] as $level)<th class="mark">{{ __('pmoc.levels.'.$level) }}</th>@endforeach
            </tr></thead>
            <tbody>
                @foreach ($activities as $activity)
                    <tr>
                        <td>{{ $activity['label'] }}</td>
                        @foreach ([1, 3, 6, 12] as $level)<td class="mark">{{ $activity['level'] === $level ? '●' : '' }}</td>@endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>{{ __('pmoc.document.history') }}</h2>
        <table class="list">
            <thead><tr>
                <th>{{ __('pmoc.visit.date') }}</th><th>{{ __('pmoc.visit.level') }}</th><th>{{ __('pmoc.visit.order') }}</th>
                <th>{{ __('pmoc.document.done_by') }}</th><th>{{ __('pmoc.visit.status') }}</th>
            </tr></thead>
            <tbody>
                @forelse ($visits as $visit)
                    <tr>
                        <td>{{ $visit->due_on->translatedFormat(__('app.date_format')) }}</td>
                        <td>{{ __('pmoc.levels.'.$visit->level) }}</td>
                        <td>{{ $visit->workOrder?->label() ?? '—' }}</td>
                        <td>{{ $visit->workOrder?->technician?->name ?? '—' }}</td>
                        <td>{{ $visit->workOrder?->status?->getLabel() ?? '—' }}@if ($visit->workOrder?->finished_at) · {{ $visit->workOrder->finished_at->timezone($issuedAt->timezone)->translatedFormat(__('app.date_format')) }}@endif</td>
                    </tr>
                @empty
                    <tr><td colspan="5">{{ __('pmoc.plans.no_visits') }}</td></tr>
                @endforelse
            </tbody>
        </table>

        @if ($plan->notes)
            <h2>{{ __('pmoc.fields.notes') }}</h2>
            <p>{{ $plan->notes }}</p>
        @endif

        <table class="sign"><tr>
            <td><div>{{ $plan->responsible_name ?: __('pmoc.fields.responsible_name') }}@if ($plan->responsible_registration)<br>{{ $plan->responsible_registration }}@endif</div></td>
            <td><div>{{ $plan->customer?->displayName() }}</div></td>
        </tr></table>

        <div class="footer">{{ __('pmoc.document.footer', ['date' => $issuedAt->translatedFormat(__('app.datetime_format'))]) }}</div>
    </div>
</body>
</html>
