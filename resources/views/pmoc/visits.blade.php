{{-- The last visits of a plan on its edit screen: date, level of activities and the Campo work order. --}}
@php($cell = 'padding:7px 10px;border-bottom:1px solid rgba(127,127,127,.2);text-align:left;font-size:14px')
<x-filament::section :heading="__('pmoc.plans.last_visits')" icon="heroicon-o-calendar-days">
    @if ($visits->isEmpty())
        <p style="margin:0;opacity:.7;font-size:14px">{{ __('pmoc.plans.no_visits') }}</p>
    @else
        <div style="overflow-x:auto">
            <table style="width:100%;border-collapse:collapse" data-pmoc="visits">
                <thead>
                    <tr>
                        <th style="{{ $cell }}">{{ __('pmoc.visit.date') }}</th>
                        <th style="{{ $cell }}">{{ __('pmoc.visit.level') }}</th>
                        <th style="{{ $cell }}">{{ __('pmoc.visit.order') }}</th>
                        <th style="{{ $cell }}">{{ __('pmoc.visit.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($visits as $visit)
                        <tr>
                            <td style="{{ $cell }}">{{ $visit->due_on->translatedFormat(__('app.date_format')) }}</td>
                            <td style="{{ $cell }}">{{ __('pmoc.levels.'.$visit->level) }}</td>
                            <td style="{{ $cell }}">{{ $visit->workOrder?->label() ?? '—' }}</td>
                            <td style="{{ $cell }}">{{ $visit->workOrder?->status?->getLabel() ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</x-filament::section>
