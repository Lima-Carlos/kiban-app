@php
    use App\Modules\Field\Filament\Orders\OrderResource;
    use App\Modules\Field\Services\FieldFormat;
    use App\Modules\Fleet\Services\FleetFormat;
    $data = $this->data();
    $rows = $data['rows'];
    $utilization = $data['utilization'];
    $travel = $data['travel'];
    $cell = 'padding:8px 7px;border-bottom:1px solid rgba(127,127,127,.2);white-space:nowrap';
    $head = 'padding:8px 7px;border-bottom:1px solid rgba(127,127,127,.2);vertical-align:bottom';
    $warn = 'color:#b45309;font-weight:600';
    $decimal = __('field.dashboard.decimal');
    $hours = fn (int $minutes): string => FleetFormat::number(round($minutes / 60, 1), $minutes % 60 === 0 ? 0 : 1).' h';
@endphp

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-user-group">
        <x-slot name="heading">{{ __('field.dashboard.technicians') }}</x-slot>
        <x-slot name="description">{{ __('field.dashboard.technicians_help') }}</x-slot>

        @if ($rows === [])
            <p style="opacity:.7;font-size:14px">{{ __('field.dashboard.no_done') }}</p>
        @else
            {{-- On phones each technician becomes a card (one line per number, with its name). --}}
            <style>
                @media (max-width: 640px) {
                    [data-technicians] thead { display: none; }
                    [data-technicians] tr { display: block; border: 1px solid rgba(127,127,127,.25); border-radius: 10px; padding: 4px 0; margin-bottom: 10px; }
                    [data-technicians] td { display: flex; justify-content: space-between; gap: 12px; border-bottom: 0 !important; padding: 5px 12px !important; white-space: normal !important; text-align: right; }
                    [data-technicians] td::before { content: attr(data-label); font-weight: 400; opacity: .7; text-align: left; }
                    [data-technicians] td:first-child::before { content: none; }
                    [data-technicians] td:first-child { font-size: 15px; }
                }
            </style>
            <div style="overflow-x:auto">
                <table data-technicians style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead>
                        <tr style="text-align:left;opacity:.75;font-size:12px;text-transform:uppercase;letter-spacing:0">
                            <th style="{{ $head }}">{{ __('field.fields.technician') }}</th>
                            <th style="{{ $head }}">{{ __('field.dashboard.done') }}</th>
                            <th style="{{ $head }}">{{ __('field.dashboard.value') }}</th>
                            <th style="{{ $head }}">{{ __('field.dashboard.average') }}</th>
                            <th style="{{ $head }}">{{ __('field.dashboard.on_time') }}</th>
                            <th style="{{ $head }}">{{ __('field.dashboard.returns') }}</th>
                            <th style="{{ $head }}">{{ __('field.dashboard.rating') }}</th>
                            @if ($utilization)
                                <th style="{{ $head }}">{{ __('connections.dashboard.utilization') }}</th>
                            @endif
                            @if ($travel)
                                <th style="{{ $head }}">{{ __('connections.dashboard.travel') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php($id = $row['technician']->getKey())
                            <tr>
                                <td style="{{ $cell }};font-weight:600;white-space:normal;min-width:120px">
                                    <a href="{{ OrderResource::getUrl('index', ['tab' => 'done', 'filters' => ['technician_id' => ['value' => $id]]]) }}" style="text-decoration:underline">{{ $row['technician']->name }}</a>
                                </td>
                                <td style="{{ $cell }}" data-label="{{ __('field.dashboard.done') }}">{{ $row['done'] }}</td>
                                <td style="{{ $cell }}" data-label="{{ __('field.dashboard.value') }}">{{ FieldFormat::money($row['revenue']) }}</td>
                                <td style="{{ $cell }}" data-label="{{ __('field.dashboard.average') }}">{{ $row['average_minutes'] !== null ? FieldFormat::duration($row['average_minutes']) : '—' }}</td>
                                <td style="{{ $cell }};{{ $row['on_time'] !== null && $row['on_time'] < 80 ? $warn : '' }}" data-label="{{ __('field.dashboard.on_time') }}">{{ $row['on_time'] !== null ? $row['on_time'].'%' : '—' }}</td>
                                <td style="{{ $cell }};{{ $row['returns'] > 0 ? $warn : '' }}" data-label="{{ __('field.dashboard.returns') }}">{{ $row['returns'] }}</td>
                                <td style="{{ $cell }};{{ $row['rating'] !== null && $row['rating'] <= 3 ? 'color:#dc2626;font-weight:600' : '' }}" data-label="{{ __('field.dashboard.rating') }}">
                                    {{ $row['rating'] !== null ? '★ '.number_format($row['rating'], 1, $decimal, '').' ('.$row['ratings'].')' : '—' }}
                                </td>
                                @if ($utilization)
                                    @php($use = $utilization[$id] ?? null)
                                    <td style="{{ $cell }}" data-label="{{ __('connections.dashboard.utilization') }}" data-utilization>
                                        @if ($use && $use['percent'] !== null)
                                            <span><strong>{{ $use['percent'] }}%</strong>
                                            <span style="display:block;font-size:12px;opacity:.75">{{ __('connections.dashboard.utilization_hours', ['service' => $hours($use['service']), 'worked' => $hours($use['worked'])]) }}</span></span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endif
                                @if ($travel)
                                    @php($trip = $travel[$id] ?? null)
                                    <td style="{{ $cell }}" data-label="{{ __('connections.dashboard.travel') }}" data-travel>
                                        @if ($trip)
                                            <span>≈ {{ FleetFormat::money($trip['cost_cents']) }}
                                            <span style="display:block;font-size:12px;opacity:.75">{{ FleetFormat::distance($trip['km']) }}</span></span>
                                        @else
                                            —
                                        @endif
                                    </td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            @if ($utilization || $travel)
                <div style="font-size:12px;opacity:.75;margin-top:10px;display:flex;flex-direction:column;gap:2px">
                    @if ($utilization)
                        <span>{{ __('connections.dashboard.utilization_help') }}</span>
                    @endif
                    @if ($travel)
                        <span>{{ __('connections.dashboard.travel_help') }}</span>
                    @endif
                </div>
            @endif
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
