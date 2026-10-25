@php
    use App\Modules\TimeClock\Filament\TimesheetPage;
    use App\Modules\TimeClock\Services\ScheduleCalendar;
    $rankings = $this->rankings();
    $row = 'display:flex;justify-content:space-between;gap:8px;padding:6px 0;border-bottom:1px solid rgba(127,127,127,.2);color:inherit;text-decoration:none';
@endphp

<x-filament-widgets::widget>
    <x-filament::section icon="heroicon-o-user-group">
        <x-slot name="heading">{{ __('timeclock.dashboard.ranking') }}</x-slot>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:20px">
            @foreach ($rankings as $key => $people)
                <div>
                    <div style="font-weight:600;margin-bottom:4px">{{ __('timeclock.dashboard.rankings.'.$key) }}</div>
                    @forelse ($people as $item)
                        <a href="{{ TimesheetPage::getUrl(['employee' => $item['employee']->getKey(), 'month' => $this->monthKey()]) }}" style="{{ $row }}">
                            <span>{{ $item['employee']->person?->name }}</span>
                            <strong style="white-space:nowrap;color:{{ $key === 'extra' || ($key === 'bank' && $item['value'] > 0) ? '#15803d' : '#dc2626' }}">
                                {{ $key === 'bank' ? ($item['value'] < 0 ? '−' : '+') : '' }}{{ ScheduleCalendar::hours(abs($item['value'])) }}
                            </strong>
                        </a>
                    @empty
                        <p style="opacity:.7;font-size:14px">{{ __('timeclock.dashboard.nobody') }}</p>
                    @endforelse
                </div>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-widgets::widget>
