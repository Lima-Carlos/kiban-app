@php
    use App\Modules\Fleet\Filament\Appointments\AppointmentResource;
    use App\Modules\Fleet\Filament\Requests\ServiceRequestResource;
    $timezone = \App\Modules\Fleet\Filament\FleetFields::timezone();
@endphp

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(300px,1fr));gap:16px">
    <x-filament::section icon="heroicon-o-chat-bubble-left-ellipsis">
        <x-slot name="heading">{{ __('fleet.home.waiting_requests') }}</x-slot>
        <x-slot name="afterHeader">
            <x-filament::button tag="a" size="sm" color="gray" :href="ServiceRequestResource::getUrl('index')">{{ __('fleet.home.see_all') }}</x-filament::button>
        </x-slot>

        @forelse ($requests as $request)
            <a href="{{ ServiceRequestResource::getUrl('index') }}" style="display:block;padding:10px 0;border-bottom:1px solid rgba(127,127,127,.2);color:inherit;text-decoration:none">
                <div style="display:flex;justify-content:space-between;gap:8px">
                    <strong>{{ $request->vehicle->plate }}</strong>
                    <x-filament::badge color="gray">{{ $request->kind->getLabel() }}</x-filament::badge>
                </div>
                <div style="font-size:13px;opacity:.8;margin-top:2px">{{ \Illuminate\Support\Str::limit($request->description, 90) }}</div>
                <div style="font-size:12px;opacity:.6;margin-top:2px">{{ $request->requester?->name }} · {{ $request->created_at->timezone($timezone)->translatedFormat(__('app.datetime_format')) }}</div>
            </a>
        @empty
            <p style="opacity:.7">{{ __('fleet.home.no_requests') }}</p>
        @endforelse
    </x-filament::section>

    <x-filament::section icon="heroicon-o-calendar-days">
        <x-slot name="heading">{{ __('fleet.home.next_appointments') }}</x-slot>
        <x-slot name="afterHeader">
            <x-filament::button tag="a" size="sm" color="gray" :href="AppointmentResource::getUrl('calendar')">{{ __('fleet.appointments.calendar') }}</x-filament::button>
        </x-slot>

        @forelse ($appointments as $appointment)
            <a href="{{ AppointmentResource::getUrl('index') }}" style="display:block;padding:10px 0;border-bottom:1px solid rgba(127,127,127,.2);color:inherit;text-decoration:none">
                <div style="display:flex;justify-content:space-between;gap:8px">
                    <strong style="{{ $appointment->scheduled_at->isPast() ? 'color:#dc2626' : '' }}">{{ $appointment->scheduled_at->timezone($timezone)->translatedFormat(__('app.datetime_format')) }}</strong>
                    <x-filament::badge :color="$appointment->kind === \App\Modules\Fleet\Enums\ServiceKind::Wash ? 'info' : 'warning'">{{ $appointment->kind->getLabel() }}</x-filament::badge>
                </div>
                <div style="font-size:13px;opacity:.8;margin-top:2px">{{ $appointment->vehicle->plate }} · {{ $appointment->vehicle->brand }} {{ $appointment->vehicle->model }}{{ $appointment->place() ? ' · '.$appointment->place() : '' }}</div>
            </a>
        @empty
            <p style="opacity:.7">{{ __('fleet.home.no_appointments') }}</p>
        @endforelse
    </x-filament::section>
</div>
