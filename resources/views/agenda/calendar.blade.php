@php
    use App\Modules\Agenda\Enums\AppointmentStatus;
    use App\Modules\Agenda\Filament\Appointments\AppointmentResource;
    use App\Modules\Agenda\Services\AgendaFormat;
    use App\Modules\Agenda\Services\AgendaMessages;
    $messages = app(AgendaMessages::class);
    $canCreate = AppointmentResource::canCreate();
    $card = 'display:block;padding:8px 10px;border-radius:10px;border:1px solid rgba(127,127,127,.25);color:inherit;margin-bottom:8px;background:rgba(127,127,127,.12)';
    $clockColors = ['in' => 'success', 'out' => 'gray', 'none' => 'warning'];
@endphp
<x-filament-panels::page>

    {{-- Previous, today, next; day or week. --}}
    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:8px">
            <x-filament::icon-button icon="heroicon-o-chevron-left" wire:click="previous" :label="__('agenda.calendar.previous')" />
            <div style="font-size:1.15rem;font-weight:600" data-agenda="day">
                @if ($mode === 'week')
                    {{ __('agenda.calendar.week_of', ['date' => $this->date()->startOfWeek()->translatedFormat(__('app.date_format'))]) }}
                @else
                    {{ ucfirst($this->date()->locale(app()->getLocale())->translatedFormat(__('field.agenda.day_format'))) }}
                @endif
            </div>
            <x-filament::icon-button icon="heroicon-o-chevron-right" wire:click="next" :label="__('agenda.calendar.next')" />
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap">
            <x-filament::button color="gray" size="sm" wire:click="today">{{ __('agenda.calendar.today') }}</x-filament::button>
            <x-filament::button :color="$mode === 'day' ? 'primary' : 'gray'" size="sm" wire:click="setMode('day')">{{ __('agenda.calendar.day') }}</x-filament::button>
            <x-filament::button :color="$mode === 'week' ? 'primary' : 'gray'" size="sm" wire:click="setMode('week')">{{ __('agenda.calendar.week') }}</x-filament::button>
        </div>
    </div>

    @php($professionals = $this->professionals())
    @if ($professionals->isEmpty())
        <x-filament::section>
            <p style="margin:0">{{ __('agenda.calendar.no_professionals') }}</p>
        </x-filament::section>
    @endif

    @if ($mode === 'week')
        @if ($professionals->count() > 1 || $this->professional)
            <div style="display:flex;gap:6px;flex-wrap:wrap">
                <x-filament::button size="xs" :color="$this->professional ? 'gray' : 'primary'" wire:click="$set('professional', null)">{{ __('agenda.calendar.everyone') }}</x-filament::button>
                @foreach (\App\Modules\Agenda\Models\Professional::query()->active()->with('person')->orderBy('sort')->get() as $one)
                    <x-filament::button size="xs" :color="$this->professional === $one->getKey() ? 'primary' : 'gray'" wire:click="$set('professional', {{ $one->getKey() }})">{{ $one->name() }}</x-filament::button>
                @endforeach
            </div>
        @endif
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:12px;align-items:start">
            @foreach ($this->week() as $date => $day)
                <div data-agenda="weekday" style="border-radius:12px;padding:10px;background:rgba(127,127,127,.06)">
                    <div style="display:flex;justify-content:space-between;margin-bottom:8px">
                        <strong>{{ ucfirst($day['date']->locale(app()->getLocale())->translatedFormat('D d/m')) }}</strong>
                        <span style="font-size:12px;opacity:.7">{{ $day['appointments']->count() }}</span>
                    </div>
                    @forelse ($day['appointments'] as $appointment)
                        <a href="{{ AppointmentResource::getUrl('view', ['record' => $appointment]) }}" style="{{ $card }};border-left:4px solid {{ $appointment->professional->displayColor() }};text-decoration:none">
                            <div style="display:flex;justify-content:space-between;gap:6px;font-size:13px">
                                <strong>{{ AgendaFormat::time($appointment->starts_at) }}</strong>
                                <x-filament::badge size="sm" :color="$appointment->status->getColor()">{{ $appointment->status->getLabel() }}</x-filament::badge>
                            </div>
                            <div style="font-weight:600">{{ $appointment->title() }}</div>
                            <div style="font-size:12px;opacity:.75">{{ $appointment->servicesLabel() }} · {{ $appointment->professional->name() }}</div>
                        </a>
                    @empty
                        <p style="font-size:13px;opacity:.6;margin:4px 0">{{ __('agenda.calendar.free') }}</p>
                    @endforelse
                    @if ($canCreate)
                        <a href="{{ AppointmentResource::getUrl('create', ['data' => $date, 'profissional' => $this->professional]) }}" style="font-size:12px;text-decoration:underline;opacity:.8">+ {{ __('agenda.calendar.book_here') }}</a>
                    @endif
                </div>
            @endforeach
        </div>
    @else
        {{-- One column per professional; on the phone, one under the other. --}}
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:12px;align-items:start">
            @foreach ($this->columns() as $column)
                @php($professional = $column['professional'])
                <div data-agenda="professional" style="border-radius:12px;padding:10px;background:rgba(127,127,127,.06)">
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-bottom:4px">
                        <span style="width:12px;height:12px;border-radius:999px;background:{{ $professional->displayColor() }}"></span>
                        <strong>{{ $professional->name() }}</strong>
                        @if ($column['clock'])
                            <x-filament::badge size="sm" :color="$clockColors[$column['clock']['state']]" icon="heroicon-o-finger-print">
                                {{ __('agenda.calendar.clock.'.$column['clock']['state'], ['time' => $column['clock']['since'] ? AgendaFormat::time($column['clock']['since']) : '']) }}
                            </x-filament::badge>
                        @endif
                        <span style="margin-left:auto;font-size:12px;opacity:.7">{{ $column['appointments']->count() }}</span>
                    </div>
                    <div style="font-size:12px;opacity:.7;margin-bottom:8px">{{ $column['hours'] ?? __('agenda.calendar.day_off') }}</div>

                    @forelse ($column['appointments'] as $appointment)
                        @php($next = $this->nextStep($appointment))
                        <div wire:key="card-{{ $appointment->getKey() }}" data-agenda="card" style="{{ $card }};border-left:4px solid {{ $appointment->lines->first()?->service?->color ?: $professional->displayColor() }}">
                            <a href="{{ AppointmentResource::getUrl('view', ['record' => $appointment]) }}" style="display:block;color:inherit;text-decoration:none">
                                <div style="display:flex;justify-content:space-between;gap:6px;font-size:13px">
                                    <strong>{{ AgendaFormat::time($appointment->starts_at) }}–{{ AgendaFormat::time($appointment->ends_at) }}</strong>
                                    <x-filament::badge size="sm" :color="$appointment->status->getColor()">{{ $appointment->status->getLabel() }}</x-filament::badge>
                                </div>
                                <div style="font-weight:600;margin-top:2px">
                                    {{ $appointment->title() }}
                                    @if ($appointment->item?->size) <span style="font-weight:normal;opacity:.7">({{ $appointment->item->size->short() }})</span> @endif
                                    @if ($appointment->flagged) <span title="{{ __('agenda.calendar.flagged') }}" style="color:#dc2626">⚠</span> @endif
                                    @if ($appointment->pickup || $appointment->dropoff) <span title="{{ __('agenda.fields.pickup') }}">🚐</span> @endif
                                    @if ($appointment->origin === 'online') <span title="{{ __('agenda.origins.online') }}" style="font-size:11px;opacity:.7">· {{ __('agenda.origins.online') }}</span> @endif
                                </div>
                                <div style="font-size:12px;opacity:.75">{{ $appointment->client?->name }} · {{ $appointment->servicesLabel() }}</div>
                                @if ($appointment->item?->notes)
                                    <div style="font-size:12px;color:#b91c1c">{{ \Illuminate\Support\Str::limit($appointment->item->notes, 80) }}</div>
                                @endif
                            </a>
                            @if ($this->canWork($appointment))
                                <div style="display:flex;gap:6px;flex-wrap:wrap;margin-top:6px">
                                    @if ($next)
                                        <x-filament::button size="xs" :color="$next === AppointmentStatus::Ready ? 'success' : 'primary'" wire:click="advance({{ $appointment->getKey() }})">
                                            {{ __('agenda.actions.to.'.$next->value) }}
                                        </x-filament::button>
                                    @endif
                                    @if ($appointment->status === AppointmentStatus::Ready && $appointment->client?->phone)
                                        <x-filament::button size="xs" color="success" tag="a" target="_blank" :href="$messages->ready($appointment)" icon="heroicon-o-chat-bubble-left-right" wire:click="markSent({{ $appointment->getKey() }}, 'ready')">
                                            {{ $appointment->ready_sent_at ? __('agenda.actions.whatsapp_again') : __('agenda.actions.whatsapp_ready') }}
                                        </x-filament::button>
                                    @endif
                                </div>
                            @endif
                        </div>
                    @empty
                        <p style="font-size:13px;opacity:.6;margin:4px 0">{{ __('agenda.calendar.free') }}</p>
                    @endforelse
                    @if ($canCreate && $column['hours'])
                        <a href="{{ AppointmentResource::getUrl('create', ['data' => $this->day, 'profissional' => $professional->getKey()]) }}" style="font-size:12px;text-decoration:underline;opacity:.8">+ {{ __('agenda.calendar.book_here') }}</a>
                    @endif
                </div>
            @endforeach
        </div>
    @endif
</x-filament-panels::page>
