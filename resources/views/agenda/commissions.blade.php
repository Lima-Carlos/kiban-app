<x-filament-panels::page>
    @php
        use App\Modules\Agenda\Services\AgendaFormat;
        $rows = $this->rows();
        $cell = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.2);text-align:left';
    @endphp

    <div style="display:flex;align-items:center;gap:8px">
        <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previous" :label="__('agenda.calendar.previous')" />
        <div style="font-size:1.15rem;font-weight:600">{{ ucfirst($this->monthDate()->locale(app()->getLocale())->translatedFormat('F Y')) }}</div>
        <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="next" :label="__('agenda.calendar.next')" />
    </div>

    <x-filament::section :description="__('agenda.commissions.help')">
        @if ($rows === [])
            <p style="margin:0;opacity:.7">{{ __('agenda.commissions.none') }}</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px" data-agenda="commissions">
                    <thead>
                        <tr>
                            <th style="{{ $cell }}">{{ __('agenda.commissions.professional') }}</th>
                            <th style="{{ $cell }};text-align:right">{{ __('agenda.commissions.appointments') }}</th>
                            <th style="{{ $cell }};text-align:right">{{ __('agenda.commissions.revenue_short') }}</th>
                            <th style="{{ $cell }};text-align:right">{{ __('agenda.commissions.commission_short') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td style="{{ $cell }};font-weight:600">{{ $row['professional']->name() }}</td>
                                <td style="{{ $cell }};text-align:right">{{ $row['appointments'] }}</td>
                                <td style="{{ $cell }};text-align:right">{{ AgendaFormat::money($row['revenue']) }}</td>
                                <td style="{{ $cell }};text-align:right;font-weight:600">{{ AgendaFormat::money($row['commission']) }}</td>
                            </tr>
                        @endforeach
                        <tr>
                            <td style="{{ $cell }};font-weight:700">{{ __('agenda.commissions.total') }}</td>
                            <td style="{{ $cell }};text-align:right;font-weight:700">{{ collect($rows)->sum('appointments') }}</td>
                            <td style="{{ $cell }};text-align:right;font-weight:700">{{ AgendaFormat::money((int) collect($rows)->sum('revenue')) }}</td>
                            <td style="{{ $cell }};text-align:right;font-weight:700">{{ AgendaFormat::money((int) collect($rows)->sum('commission')) }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
