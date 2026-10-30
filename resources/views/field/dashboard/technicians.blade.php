@php
    use App\Modules\Field\Filament\Orders\OrderResource;
    use App\Modules\Field\Services\FieldFormat;
    $rows = $this->rows();
    $cell = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.2);white-space:nowrap';
    $warn = 'color:#b45309;font-weight:600';
    $decimal = __('field.dashboard.decimal');
@endphp

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-user-group">
        <x-slot name="heading">{{ __('field.dashboard.technicians') }}</x-slot>
        <x-slot name="description">{{ __('field.dashboard.technicians_help') }}</x-slot>

        @if ($rows === [])
            <p style="opacity:.7;font-size:14px">{{ __('field.dashboard.no_done') }}</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px">
                    <thead>
                        <tr style="text-align:left;opacity:.75;font-size:12px;text-transform:uppercase;letter-spacing:.03em">
                            <th style="{{ $cell }}">{{ __('field.fields.technician') }}</th>
                            <th style="{{ $cell }}">{{ __('field.dashboard.done') }}</th>
                            <th style="{{ $cell }}">{{ __('field.dashboard.value') }}</th>
                            <th style="{{ $cell }}">{{ __('field.dashboard.average') }}</th>
                            <th style="{{ $cell }}">{{ __('field.dashboard.on_time') }}</th>
                            <th style="{{ $cell }}">{{ __('field.dashboard.returns') }}</th>
                            <th style="{{ $cell }}">{{ __('field.dashboard.rating') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td style="{{ $cell }};font-weight:600">
                                    <a href="{{ OrderResource::getUrl('index', ['tab' => 'done', 'filters' => ['technician_id' => ['value' => $row['technician']->getKey()]]]) }}" style="text-decoration:underline">{{ $row['technician']->name }}</a>
                                </td>
                                <td style="{{ $cell }}">{{ $row['done'] }}</td>
                                <td style="{{ $cell }}">{{ FieldFormat::money($row['revenue']) }}</td>
                                <td style="{{ $cell }}">{{ $row['average_minutes'] !== null ? FieldFormat::duration($row['average_minutes']) : '—' }}</td>
                                <td style="{{ $cell }};{{ $row['on_time'] !== null && $row['on_time'] < 80 ? $warn : '' }}">{{ $row['on_time'] !== null ? $row['on_time'].'%' : '—' }}</td>
                                <td style="{{ $cell }};{{ $row['returns'] > 0 ? $warn : '' }}">{{ $row['returns'] }}</td>
                                <td style="{{ $cell }};{{ $row['rating'] !== null && $row['rating'] <= 3 ? 'color:#dc2626;font-weight:600' : '' }}">
                                    {{ $row['rating'] !== null ? '★ '.number_format($row['rating'], 1, $decimal, '').' ('.$row['ratings'].')' : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-widgets::widget>
