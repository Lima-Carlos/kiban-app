{{-- The page of the PMOC QR label (no login): the equipment, the maintenance status and "ask for service". --}}
@php($date = fn ($value) => $value?->translatedFormat(__('app.date_format')))
<x-layout :title="$equipment->tag" :brand="$company" :logo="$logo" :color="$color">
    <h1>{{ $equipment->tag }}</h1>
    <p>{{ collect([__('pmoc.kinds.'.$equipment->kind), $equipment->room, collect([$equipment->brand, $equipment->model])->filter()->implode(' ')])->filter()->implode(' · ') }}</p>

    <div data-pmoc="public-status" style="margin:16px 0;padding:12px 14px;border-radius:10px;font-weight:bold;text-align:center;{{ $upToDate ? 'background:#ecfdf5;color:#047857' : 'background:#fef2f2;color:#b91c1c' }}">
        {{ $upToDate ? __('pmoc.public.up_to_date') : __('pmoc.public.late') }}
    </div>

    <div style="text-align:left;border:1px solid #e5e7eb;border-radius:10px;font-size:14px">
        @foreach ([
            __('pmoc.public.last') => $date($lastDone) ?? __('pmoc.public.none_yet'),
            __('pmoc.fields.next_due_on') => $date($plan->next_due_on),
            __('pmoc.fields.frequency') => trans_choice('pmoc.frequency', $plan->frequency_months, ['count' => $plan->frequency_months]),
            __('pmoc.fields.responsible_name') => collect([$plan->responsible_name, $plan->responsible_registration])->filter()->implode(' · ') ?: '—',
            __('pmoc.fields.art_number') => $plan->art_number ?: '—',
            __('pmoc.public.company') => $company,
        ] as $label => $value)
            <div style="display:flex;justify-content:space-between;gap:12px;padding:10px 14px;border-bottom:1px solid #f0f0f0">
                <span style="color:#6b7280">{{ $label }}</span><strong style="text-align:right">{{ $value }}</strong>
            </div>
        @endforeach
    </div>

    @if (session('pmoc_asked'))
        <p data-pmoc="asked" style="margin-top:18px;color:#047857;font-weight:bold">{{ __('pmoc.public.asked', ['company' => $company]) }}</p>
    @else
        <details style="margin-top:18px" @if ($errors->any()) open @endif>
            <summary class="button" style="list-style:none">{{ __('pmoc.public.ask') }}</summary>
            <form method="POST" action="{{ route('pmoc.public.ask', ['token' => $equipment->public_token]) }}" style="text-align:left">
                @csrf
                <div style="position:absolute;left:-9999px" aria-hidden="true"><input name="website" tabindex="-1" autocomplete="off"></div>
                <label for="problem">{{ __('pmoc.public.problem') }}</label>
                <textarea id="problem" name="problem" rows="3" maxlength="1000" required placeholder="{{ __('pmoc.public.problem_hint') }}" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:15px">{{ old('problem') }}</textarea>
                @error('problem')<p style="color:#b91c1c;text-align:left">{{ $message }}</p>@enderror
                <label for="name">{{ __('pmoc.public.name') }}</label>
                <div class="address"><input id="name" name="name" value="{{ old('name') }}" autocomplete="name" maxlength="120" required></div>
                @error('name')<p style="color:#b91c1c;text-align:left">{{ $message }}</p>@enderror
                <label for="phone">{{ __('pmoc.public.phone') }}</label>
                <div class="address"><input id="phone" name="phone" type="tel" value="{{ old('phone') }}" autocomplete="tel" maxlength="30" required></div>
                @error('phone')<p style="color:#b91c1c;text-align:left">{{ $message }}</p>@enderror
                <p class="muted" style="text-align:left;margin-top:10px">{{ __('pmoc.public.privacy', ['company' => $company]) }}</p>
                <button type="submit">{{ __('pmoc.public.send') }}</button>
            </form>
        </details>
    @endif

    <a href="{{ $staffUrl }}" class="muted" style="display:block;margin-top:18px;text-align:center;color:#6b7280">{{ __('pmoc.public.staff') }}</a>
</x-layout>
