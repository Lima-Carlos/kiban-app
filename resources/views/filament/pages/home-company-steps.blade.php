{{--
    "Company first steps" (CompanyFirstSteps): one list joining the first steps of every system of the company, in
    the recommended order, with a small header per group. Included by home.blade.php. Optional video at the top
    (public/videos/por-onde-comecar-<lang>.mp4). When everything is done, the list stays closed ("all set").
--}}
@php($allDone = $company['done'] === $company['total'])
<x-filament::section icon="heroicon-o-rocket-launch" collapsible :collapsed="$allDone">
    <x-slot name="heading">{{ __('app.first_steps.title') }}</x-slot>
    <x-slot name="description">
        @if ($allDone)
            {{ __('app.home.all_done') }}
        @else
            {{ __('app.first_steps.help') }} {{ __('app.home.progress', ['done' => $company['done'], 'total' => $company['total']]) }}
        @endif
    </x-slot>

    @if ($company['video'])
        <div style="margin-bottom:16px">
            <div style="font-weight:600;font-size:14px;margin-bottom:6px">{{ __('app.first_steps.video') }}</div>
            <video data-company-steps-video src="{{ $company['video']['src'] }}" @if ($company['video']['poster']) poster="{{ $company['video']['poster'] }}" @endif
                   controls playsinline preload="metadata"
                   style="width:100%;max-width:640px;border-radius:10px;background:#0f172a"></video>
        </div>
    @endif

    <div style="height:8px;border-radius:999px;background:rgba(127,127,127,.18);overflow:hidden;margin-bottom:16px">
        <div style="height:100%;width:{{ $company['percent'] }}%;background:{{ config('kiban.brand.blue') }}"></div>
    </div>

    @php($number = 1)
    @foreach ($company['groups'] as $group)
        @php($groupDone = collect($group['steps'])->every(fn ($step) => $step['done']))
        <div data-steps-group="{{ $group['key'] }}" style="margin-top:{{ $loop->first ? '0' : '18px' }}">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:12px;font-weight:700;letter-spacing:.03em;text-transform:uppercase;opacity:.75">
                <x-filament::icon :icon="$group['icon']" style="width:16px;height:16px" />
                <span>{{ $group['title'] }}</span>
                @if ($groupDone)
                    <x-filament::icon icon="heroicon-m-check-circle" style="width:16px;height:16px;color:#16a34a" />
                @endif
            </div>
            @include('filament.pages.home-step-items', ['steps' => $group['steps'], 'start' => $number])
        </div>
        @php($number += count($group['steps']))
    @endforeach
</x-filament::section>
