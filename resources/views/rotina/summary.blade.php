@php
    use App\Modules\Rotina\Models\Entry;
    use App\Modules\Rotina\Services\RotinaReports as Reports;
    $data = $this->data();
    $cell = 'padding:8px 10px;border-bottom:1px solid rgba(127,127,127,.2);text-align:left';
@endphp
<x-filament-panels::page>

    <div style="display:flex;align-items:center;gap:8px">
        <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previous" :label="__('rotina.reports.previous')" />
        <div style="font-size:1.15rem;font-weight:600">{{ ucfirst($this->monthDate()->locale(app()->getLocale())->translatedFormat('F Y')) }}</div>
        <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="next" :label="__('rotina.reports.next')" />
    </div>

    <x-filament::section :heading="__('rotina.reports.by_project')" :description="__('rotina.reports.help')">
        @if ($data['projects'] === [])
            <p style="margin:0;opacity:.7">{{ __('rotina.reports.none') }}</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px" data-rotina="projects">
                    @if ($data['with_costs'] || $data['with_revenue'])
                        <thead>
                            <tr>
                                <th style="{{ $cell }}">{{ __('rotina.fields.project') }}</th>
                                <th style="{{ $cell }};text-align:right">{{ __('rotina.reports.hours') }}</th>
                                @if ($data['with_costs'])<th style="{{ $cell }};text-align:right">{{ __('rotina.reports.cost') }}</th>@endif
                                @if ($data['with_revenue'])<th style="{{ $cell }};text-align:right">{{ __('rotina.reports.revenue') }}</th><th style="{{ $cell }};text-align:right">{{ __('rotina.reports.margin') }}</th>@endif
                            </tr>
                        </thead>
                    @endif
                    <tbody>
                        @foreach ($data['projects'] as $row)
                            <tr>
                                <td style="{{ $cell }};font-weight:600">{{ $row['name'] }}@if ($row['client']) <span style="opacity:.65;font-weight:400">· {{ $row['client'] }}</span>@endif</td>
                                <td style="{{ $cell }};text-align:right">{{ Entry::format($row['minutes']) }}</td>
                                @if ($data['with_costs'])<td style="{{ $cell }};text-align:right" data-rotina="cost">{{ Reports::money($row['cost']) }}</td>@endif
                                @if ($data['with_revenue'])
                                    <td style="{{ $cell }};text-align:right">{{ Reports::money($row['revenue']) }}</td>
                                    <td style="{{ $cell }};text-align:right;font-weight:600;{{ ($row['margin'] ?? 0) < 0 ? 'color:#dc2626' : '' }}" data-rotina="margin">{{ Reports::money($row['margin']) }}</td>
                                @endif
                            </tr>
                        @endforeach
                        <tr>
                            <td style="{{ $cell }};font-weight:700">{{ __('rotina.reports.total') }}</td>
                            <td style="{{ $cell }};text-align:right;font-weight:700">{{ Entry::format($data['total']) }}</td>
                            @if ($data['with_costs'])<td style="{{ $cell }};text-align:right;font-weight:700">{{ Reports::money($data['cost']) }}</td>@endif
                            @if ($data['with_revenue'])
                                <td style="{{ $cell }};text-align:right;font-weight:700">{{ Reports::money($data['revenue']) }}</td>
                                <td style="{{ $cell }};text-align:right;font-weight:700"></td>
                            @endif
                        </tr>
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    <x-filament::section :heading="__('rotina.reports.by_person')">
        @if ($data['people'] === [])
            <p style="margin:0;opacity:.7">{{ __('rotina.reports.none') }}</p>
        @else
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:14px" data-rotina="people">
                    <thead>
                        <tr>
                            <th style="{{ $cell }}">{{ __('rotina.fields.person') }}</th>
                            <th style="{{ $cell }};text-align:right">{{ __('rotina.reports.hours') }}</th>
                            <th style="{{ $cell }};text-align:right">{{ __('rotina.reports.tasks_done') }}</th>
                            @if ($data['with_ponto'])
                                <th style="{{ $cell }};text-align:right">{{ __('rotina.reports.clocked') }}</th>
                                <th style="{{ $cell }};text-align:right">{{ __('rotina.reports.share') }}</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($data['people'] as $row)
                            <tr>
                                <td style="{{ $cell }};font-weight:600">{{ $row['name'] }}</td>
                                <td style="{{ $cell }};text-align:right">{{ Entry::format($row['minutes']) }}</td>
                                <td style="{{ $cell }};text-align:right">{{ $row['tasks_done'] }}</td>
                                @if ($data['with_ponto'])
                                    <td style="{{ $cell }};text-align:right">{{ $row['clocked'] === null ? '—' : Entry::format($row['clocked']) }}</td>
                                    <td style="{{ $cell }};text-align:right" data-rotina="share">{{ ($share = \App\Modules\Rotina\Services\RotinaReports::share($row['minutes'], $row['clocked'])) === null ? '—' : $share.'%' }}</td>
                                @endif
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>

    @if ($data['with_ponto'])
        <p style="font-size:13px;opacity:.7;margin:0">{{ __('rotina.reports.share_help') }}</p>
    @endif
    @unless ($data['with_costs'])
        <p style="font-size:13px;opacity:.7;margin:0">{{ __('rotina.rates.hint') }}</p>
    @endunless
    <p style="font-size:13px;opacity:.7;margin:0">{{ __('rotina.reports.not_timeclock') }}</p>
</x-filament-panels::page>
