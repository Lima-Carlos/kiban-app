@php
    use App\Modules\Agenda\Enums\AppointmentStatus;
    use App\Modules\Agenda\Services\AgendaFormat;
    $input = 'width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;font-size:15px;background:#fff';
    $status = $appointment->status;
@endphp
<x-layout :title="__('agenda.manage.title')" :brand="$company" :logo="$logo" :color="$color">
    @if (session('agenda.booked'))
        <p style="color:#15803d;font-weight:bold;font-size:16px" data-agenda="booked">{{ __('agenda.manage.booked') }}</p>
    @endif
    @if (session('agenda.done'))
        <p style="color:#15803d;font-weight:bold" data-agenda="done">{{ __('agenda.manage.done.'.session('agenda.done')) }}</p>
    @endif
    @if (session('agenda.error'))
        <p style="color:#b91c1c;font-weight:bold" data-agenda="error">{{ session('agenda.error') }}</p>
    @endif

    <h1>{{ $appointment->item?->name ?? $appointment->client->name }}</h1>
    <p style="font-size:17px;color:#39383A"><strong>{{ AgendaFormat::when($appointment->starts_at) }}</strong></p>
    <p>{{ $appointment->servicesLabel() }} · {{ $appointment->professional->name() }}</p>
    <p>{{ __('agenda.fields.total') }}: <strong>{{ AgendaFormat::money($appointment->price_cents) }}</strong>@if ($appointment->client_package_id) · {{ __('agenda.manage.package') }}@endif</p>
    @if ($appointment->pickup || $appointment->dropoff)
        <p>🚐 {{ __('agenda.manage.pickup', ['address' => $appointment->pickupSite?->address() ?? '—']) }}</p>
    @endif
    <p><span style="display:inline-block;padding:4px 10px;border-radius:999px;background:#f3f4f6;font-weight:bold" data-agenda="status">{{ $status->getLabel() }}</span></p>

    @if ($status === AppointmentStatus::Scheduled)
        <form method="POST" action="{{ route('agenda.manage.update', ['token' => $appointment->token]) }}">
            @csrf
            <button type="submit" name="action" value="confirm">{{ __('agenda.manage.confirm') }}</button>
        </form>
    @endif

    @if ($mayChange)
        <details style="margin-top:16px;text-align:left" @if ($day) open @endif>
            <summary style="cursor:pointer;font-weight:bold;color:{{ $color }}">{{ __('agenda.manage.reschedule') }}</summary>
            <form method="GET" action="{{ route('agenda.manage', ['token' => $appointment->token]) }}">
                <label for="day">{{ __('agenda.booking.day') }}</label>
                <input id="day" type="date" name="day" value="{{ $day }}" min="{{ $first->toDateString() }}" max="{{ $last->toDateString() }}" style="{{ $input }}" onchange="this.form.submit()" required>
                <label style="display:flex;gap:8px;font-weight:normal"><input type="checkbox" name="same" value="1" @checked(request()->boolean('same')) onchange="this.form.submit()"> {{ __('agenda.manage.same_professional', ['name' => $appointment->professional->name()]) }}</label>
                @if (! $day)<button type="submit" class="button secondary">{{ __('agenda.booking.see_times') }}</button>@endif
            </form>
            @if ($day)
                @if ($times === [])
                    <p style="text-align:left">{{ __('agenda.booking.no_times') }}</p>
                @else
                    <form method="POST" action="{{ route('agenda.manage.update', ['token' => $appointment->token]) }}">
                        @csrf
                        <input type="hidden" name="day" value="{{ $day }}">
                        <input type="hidden" name="same" value="{{ request()->boolean('same') ? 1 : 0 }}">
                        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(76px,1fr));gap:8px;margin-top:10px">
                            @foreach ($times as $time => $who)
                                <label style="border:1px solid #d1d5db;border-radius:8px;padding:8px;text-align:center;font-weight:normal;margin:0">
                                    <input type="radio" name="time" value="{{ $time }}" required> {{ $time }}
                                </label>
                            @endforeach
                        </div>
                        <button type="submit" name="action" value="move">{{ __('agenda.manage.move') }}</button>
                    </form>
                @endif
            @endif
        </details>

        <details style="margin-top:14px;text-align:left">
            <summary style="cursor:pointer;color:#6b7280">{{ __('agenda.manage.cancel_open') }}</summary>
            <form method="POST" action="{{ route('agenda.manage.update', ['token' => $appointment->token]) }}">
                @csrf
                <label for="reason">{{ __('agenda.manage.reason') }}</label>
                <input id="reason" name="reason" maxlength="250" style="{{ $input }}">
                <button type="submit" name="action" value="cancel" class="button secondary">{{ __('agenda.manage.cancel') }}</button>
            </form>
        </details>
    @elseif ($status->isOpen())
        <p class="muted" style="margin-top:14px">{{ __('agenda.manage.too_late', ['hours' => \App\Modules\Agenda\Models\AgendaSetting::current()->cancel_hours]) }}</p>
    @endif

    @if ($contact)
        <a class="button secondary" href="{{ $contact }}" target="_blank" rel="noopener">{{ __('agenda.booking.talk') }}</a>
    @endif
    <a class="button secondary" href="{{ route('agenda.booking') }}">{{ __('agenda.manage.book_again') }}</a>
</x-layout>
