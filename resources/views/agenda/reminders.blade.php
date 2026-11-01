<x-filament-panels::page>
    @php
        use App\Modules\Agenda\Enums\AppointmentStatus;
        use App\Modules\Agenda\Services\AgendaFormat;
        use App\Modules\Agenda\Services\AgendaMessages;
        use App\Support\Country;
        $messages = app(AgendaMessages::class);
        $appointments = $this->appointments();
        $row = 'display:flex;justify-content:space-between;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid rgba(127,127,127,.2);flex-wrap:wrap';
    @endphp

    <div style="display:flex;align-items:center;gap:8px">
        <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previous" :label="__('agenda.calendar.previous')" />
        <div style="font-size:1.15rem;font-weight:600">{{ ucfirst($this->date()->locale(app()->getLocale())->translatedFormat(__('field.agenda.day_format'))) }}</div>
        <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="next" :label="__('agenda.calendar.next')" />
    </div>

    <x-filament::section>
        <x-slot name="description">{{ __('agenda.reminders.help', ['count' => $appointments->count(), 'sent' => $appointments->whereNotNull('reminded_at')->count()]) }}</x-slot>

        @forelse ($appointments as $appointment)
            <div style="{{ $row }}" wire:key="reminder-{{ $appointment->getKey() }}">
                <div style="min-width:0">
                    <div style="font-weight:600">{{ AgendaFormat::time($appointment->starts_at) }} · {{ $appointment->title() }} · {{ $appointment->client->name }}</div>
                    <div style="font-size:13px;opacity:.75">{{ $appointment->servicesLabel() }} · {{ $appointment->professional->name() }} · {{ Country::formatPhone($appointment->client->phone) ?? __('agenda.reminders.no_phone') }}</div>
                </div>
                <div style="display:flex;gap:8px;align-items:center">
                    @if ($appointment->status === AppointmentStatus::Confirmed)
                        <x-filament::badge color="info">{{ $appointment->status->getLabel() }}</x-filament::badge>
                    @endif
                    @if ($appointment->client->phone)
                        <x-filament::button size="sm" tag="a" target="_blank" :href="$messages->reminder($appointment)" :color="$appointment->reminded_at ? 'gray' : 'success'" icon="heroicon-o-chat-bubble-left-right" wire:click="markSent({{ $appointment->getKey() }})">
                            {{ $appointment->reminded_at ? __('agenda.reminders.sent') : __('agenda.reminders.send') }}
                        </x-filament::button>
                    @endif
                </div>
            </div>
        @empty
            <p style="margin:0;opacity:.7">{{ __('agenda.reminders.none') }}</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
