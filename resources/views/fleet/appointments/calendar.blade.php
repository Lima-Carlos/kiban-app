<x-filament-panels::page>
    @php
        $weeks = $this->getWeeks();
        $timezone = \App\Modules\Fleet\Filament\Appointments\AppointmentResource::timezone();
    @endphp

    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px">
        <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previous" :label="__('fleet.appointments.previous')" />
        <div style="font-size:1.2rem;font-weight:600">{{ ucfirst($this->first()->locale(app()->getLocale())->translatedFormat('F Y')) }}</div>
        <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="next" :label="__('fleet.appointments.next')" />
    </div>

    <div style="overflow-x:auto">
        <table style="width:100%;min-width:680px;border-collapse:collapse;table-layout:fixed">
            <thead>
                <tr>
                    @foreach (range(0, 6) as $i)
                        <th style="padding:6px;font-size:12px;opacity:.7;text-align:left">{{ ucfirst(\Carbon\CarbonImmutable::now()->startOfWeek(\Carbon\CarbonImmutable::MONDAY)->addDays($i)->locale(app()->getLocale())->translatedFormat('D')) }}</th>
                    @endforeach
                </tr>
            </thead>
            <tbody>
                @foreach ($weeks as $week)
                    <tr>
                        @foreach ($week as $day)
                            <td style="vertical-align:top;height:96px;padding:6px;border:1px solid rgba(127,127,127,.2);{{ $day['inMonth'] ? '' : 'opacity:.45;' }}{{ $day['today'] ? 'background:rgba(4,66,153,.12);' : '' }}">
                                <div style="font-size:12px;font-weight:600;margin-bottom:4px">{{ $day['date']->day }}</div>
                                @foreach ($day['items'] as $appointment)
                                    @php
                                        $overdue = $appointment->isOpen() && $appointment->scheduled_at->isPast();
                                        $color = $overdue ? '#dc2626' : ($appointment->kind === \App\Modules\Fleet\Enums\ServiceKind::Wash ? '#0284c7' : '#d97706');
                                    @endphp
                                    <a href="{{ \App\Modules\Fleet\Filament\Appointments\AppointmentResource::getUrl('index') }}"
                                       title="{{ $appointment->kind->getLabel() }} · {{ $appointment->status->getLabel() }}"
                                       style="display:block;margin-bottom:3px;padding:2px 6px;border-radius:6px;font-size:12px;color:#fff;text-decoration:none;background:{{ $color }};{{ $appointment->isOpen() ? '' : 'opacity:.55;text-decoration:line-through' }};white-space:nowrap;overflow:hidden;text-overflow:ellipsis">
                                        {{ $appointment->scheduled_at->timezone($timezone)->format('H:i') }} {{ $appointment->vehicle->plate }}
                                    </a>
                                @endforeach
                            </td>
                        @endforeach
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div style="display:flex;gap:16px;font-size:12px;opacity:.8;flex-wrap:wrap">
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#d97706"></span> {{ __('fleet.enums.service_kind.maintenance') }}</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#0284c7"></span> {{ __('fleet.enums.service_kind.wash') }}</span>
        <span><span style="display:inline-block;width:10px;height:10px;border-radius:3px;background:#dc2626"></span> {{ __('fleet.appointments.overdue') }}</span>
    </div>
</x-filament-panels::page>
