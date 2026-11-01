@php
    use App\Modules\Agenda\Enums\ItemSize;
    use App\Modules\Agenda\Services\AgendaFormat;
    $choice = 'display:flex;gap:10px;align-items:flex-start;text-align:left;border:1px solid #e5e7eb;border-radius:10px;padding:10px 12px;margin:0 0 8px;font-weight:normal;cursor:pointer';
    $input = 'width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px 12px;font-size:15px;background:#fff';
    $query = fn (array $extra = []): array => array_filter([
        'service' => $main?->getKey(), 'size' => $size?->value, 'extras' => $chosenExtras->modelKeys(),
        'professional' => $professional, 'date' => $day?->toDateString(),
    ] + $extra, fn ($value) => $value !== null && $value !== []);
@endphp
<x-layout :title="__('agenda.booking.title')" :brand="$company" :logo="$logo" :color="$color">
    <h1>{{ __('agenda.booking.heading', ['company' => $company]) }}</h1>
    @if ($message)
        <p style="white-space:pre-line">{{ $message }}</p>
    @endif

    @if (! $open)
        <p style="font-weight:bold">{{ __('agenda.booking.closed') }}</p>
        @if ($contact)
            <a class="button" href="{{ $contact }}" target="_blank" rel="noopener">{{ __('agenda.booking.talk') }}</a>
        @endif
    @else
        @isset($error)
            <p style="color:#b91c1c;font-weight:bold" data-agenda="error">{{ $error }}</p>
        @endisset

        {{-- Step 1: what, size, extras, who and when (a simple form: works on any phone, no app). --}}
        <form method="GET" action="{{ route('agenda.booking') }}" style="text-align:left" data-agenda="choose">
            <label>1. {{ __('agenda.booking.service') }}</label>
            @foreach ($services as $service)
                <label style="{{ $choice }}">
                    <input type="radio" name="service" value="{{ $service->getKey() }}" @checked($main?->is($service)) required onchange="this.form.submit()">
                    <span>
                        <strong>{{ $service->name }}</strong>
                        <span class="muted" style="display:block;color:#6b7280">{{ AgendaFormat::money($service->priceFor($size)) }} · {{ AgendaFormat::duration($service->durationFor($size)) }}@if ($service->variesBySize() && ! $size) · {{ __('agenda.booking.depends_on_size') }}@endif</span>
                        @if ($service->description)<span class="muted" style="display:block;color:#6b7280">{{ $service->description }}</span>@endif
                    </span>
                </label>
            @endforeach

            <label for="size">2. {{ __('agenda.booking.size') }}</label>
            <select id="size" name="size" style="{{ $input }}" onchange="this.form.submit()">
                <option value="">{{ __('agenda.booking.choose') }}</option>
                @foreach (ItemSize::cases() as $option)
                    <option value="{{ $option->value }}" @selected($size === $option)>{{ $option->getLabel() }}</option>
                @endforeach
            </select>

            @if ($extras->isNotEmpty())
                <label>3. {{ __('agenda.booking.extras') }}</label>
                @foreach ($extras as $extra)
                    <label style="{{ $choice }}">
                        <input type="checkbox" name="extras[]" value="{{ $extra->getKey() }}" @checked($chosenExtras->contains($extra)) onchange="this.form.submit()">
                        <span>{{ $extra->name }} <span style="color:#6b7280">+ {{ AgendaFormat::money($extra->priceFor($size)) }}</span></span>
                    </label>
                @endforeach
            @endif

            <label for="professional">{{ $extras->isNotEmpty() ? '4' : '3' }}. {{ __('agenda.booking.professional') }}</label>
            <select id="professional" name="professional" style="{{ $input }}" onchange="this.form.submit()">
                <option value="">{{ __('agenda.booking.anyone') }}</option>
                @foreach ($professionals as $one)
                    <option value="{{ $one->getKey() }}" @selected($professional === $one->getKey())>{{ $one->name() }}</option>
                @endforeach
            </select>

            <label for="date">{{ $extras->isNotEmpty() ? '5' : '4' }}. {{ __('agenda.booking.day') }}</label>
            <input id="date" type="date" name="date" value="{{ $day?->toDateString() }}" min="{{ $first->toDateString() }}" max="{{ $last->toDateString() }}" style="{{ $input }}" onchange="this.form.submit()" required>
            <noscript><button type="submit">{{ __('agenda.booking.see_times') }}</button></noscript>
            @if (! $day)
                <button type="submit">{{ __('agenda.booking.see_times') }}</button>
            @endif
        </form>

        {{-- Step 2: a free time and who you are. --}}
        @if ($main && $day)
            <form method="POST" action="{{ route('agenda.booking.store') }}" style="text-align:left;margin-top:18px" data-agenda="book">
                @csrf
                @foreach ($query() as $name => $value)
                    @if (is_array($value))
                        @foreach ($value as $item)<input type="hidden" name="{{ $name }}[]" value="{{ $item }}">@endforeach
                    @else
                        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
                    @endif
                @endforeach
                <input type="hidden" name="started" value="{{ $started }}">
                {{-- Robots fill every field; people never see this one. --}}
                <div style="position:absolute;left:-10000px" aria-hidden="true"><input type="text" name="website" tabindex="-1" autocomplete="off"></div>

                <label>{{ __('agenda.booking.times', ['day' => ucfirst($day->locale(app()->getLocale())->translatedFormat(__('agenda.format.day')))]) }}</label>
                @if ($times === [])
                    <p style="text-align:left">{{ __('agenda.booking.no_times') }}</p>
                @else
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(76px,1fr));gap:8px" data-agenda="times">
                        @foreach ($times as $time => $who)
                            <label style="border:1px solid #d1d5db;border-radius:8px;padding:8px;text-align:center;font-weight:normal;margin:0;cursor:pointer">
                                <input type="radio" name="time" value="{{ $time }}" required @checked(old('time') === $time)> {{ $time }}
                            </label>
                        @endforeach
                    </div>
                    @error('time')<p style="color:#b91c1c;text-align:left">{{ $message }}</p>@enderror

                    <p style="text-align:left;margin-top:14px">{{ __('agenda.booking.summary', ['total' => AgendaFormat::money($total), 'time' => AgendaFormat::duration($minutes)]) }}</p>

                    <label for="name">{{ __('agenda.booking.your_name') }}</label>
                    <input id="name" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="120" style="{{ $input }}" required>
                    @error('name')<p style="color:#b91c1c;text-align:left">{{ $message }}</p>@enderror
                    <label for="phone">{{ __('agenda.booking.your_phone') }}</label>
                    <input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" maxlength="30" style="{{ $input }}" placeholder="{{ __('agenda.booking.phone_hint') }}" required>
                    @error('phone')<p style="color:#b91c1c;text-align:left">{{ $message }}</p>@enderror
                    <label for="pet">{{ __('agenda.booking.pet_name') }}</label>
                    <input id="pet" name="pet" value="{{ old('pet') }}" maxlength="120" style="{{ $input }}" required>
                    @error('pet')<p style="color:#b91c1c;text-align:left">{{ $message }}</p>@enderror
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px">
                        <div>
                            <label for="species">{{ __('agenda.fields.species') }}</label>
                            <select id="species" name="species" style="{{ $input }}">
                                @foreach (\App\Modules\Agenda\Enums\Species::cases() as $species)
                                    <option value="{{ $species->value }}" @selected(old('species') === $species->value)>{{ $species->getLabel() }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label for="breed">{{ __('agenda.fields.breed') }}</label>
                            <input id="breed" name="breed" value="{{ old('breed') }}" maxlength="120" style="{{ $input }}">
                        </div>
                    </div>

                    @if ($pickup)
                        <label style="{{ $choice }};margin-top:14px">
                            <input type="checkbox" name="pickup" value="1" @checked(old('pickup')) onchange="document.getElementById('address-box').style.display = this.checked ? 'block' : 'none'">
                            <span>{{ __('agenda.booking.pickup', ['price' => AgendaFormat::money($pickup->price_cents)]) }}</span>
                        </label>
                        <div id="address-box" style="display:{{ old('pickup') ? 'block' : 'none' }}">
                            <label for="address">{{ __('agenda.booking.address') }}</label>
                            <input id="address" name="address" value="{{ old('address') }}" maxlength="250" style="{{ $input }}" autocomplete="street-address">
                            @error('address')<p style="color:#b91c1c;text-align:left">{{ $message }}</p>@enderror
                        </div>
                    @endif

                    <label for="notes">{{ __('agenda.booking.notes') }}</label>
                    <textarea id="notes" name="notes" rows="2" maxlength="500" style="{{ $input }}" placeholder="{{ __('agenda.booking.notes_hint') }}">{{ old('notes') }}</textarea>

                    <button type="submit">{{ __('agenda.booking.confirm') }}</button>
                    <p class="muted" style="margin-top:10px">{{ __('agenda.booking.privacy', ['company' => $company]) }}</p>
                @endif
            </form>
        @endif
    @endif
</x-layout>
