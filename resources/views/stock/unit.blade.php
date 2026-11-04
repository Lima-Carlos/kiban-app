{{-- Card of one unit tracked by serial number (opened by its QR label). --}}
@php($unit = $this->record())
@php($cell = 'padding:7px 10px;border-bottom:1px solid rgba(127,127,127,.2);text-align:left;font-size:14px')
<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-qr-code">
        <x-slot name="heading">{{ $unit->serial }} · {{ $unit->item->name }}</x-slot>
        <div style="display:flex;flex-wrap:wrap;gap:8px 14px;align-items:center;font-size:15px" data-stock="unit">
            <x-filament::badge :color="match ($unit->status) { 'stock' => 'success', 'customer' => 'info', default => 'danger' }">{{ __('stock.units.statuses.'.$unit->status) }}</x-filament::badge>
            <strong>{{ $unit->whereabouts() }}</strong>
            @if ($unit->since_at)<span style="opacity:.7">{{ __('stock.fields.since') }} {{ $unit->since_at->timezone(\Filament\Support\Facades\FilamentTimezone::get())->translatedFormat(__('app.date_format')) }}</span>@endif
            @if ($unit->mac)<span style="opacity:.7">MAC {{ $unit->mac }}</span>@endif
        </div>
    </x-filament::section>

    <x-filament::section :heading="__('stock.movements.plural')" icon="heroicon-o-queue-list">
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse">
                <tbody>
                    @foreach ($this->history() as $movement)
                        <tr>
                            <td style="{{ $cell }}">{{ $movement->moved_at->timezone(\Filament\Support\Facades\FilamentTimezone::get())->translatedFormat(__('app.datetime_format')) }}</td>
                            <td style="{{ $cell }};font-weight:600">{{ __('stock.movements.kinds.'.$movement->kind) }}</td>
                            <td style="{{ $cell }}">{{ collect([$movement->from?->name, $movement->to?->name ?? $movement->customer_name])->filter()->implode(' → ') ?: '—' }}</td>
                            <td style="{{ $cell }}">{{ $movement->workOrder?->label() ?? $movement->note ?? '' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-filament::section>
</x-filament-panels::page>
