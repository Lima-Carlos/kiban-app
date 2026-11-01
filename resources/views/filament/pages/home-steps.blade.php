{{-- The first steps of one system (what to do, in which order). Included by home.blade.php. --}}
{{-- When everything is done, the list stays closed (one line: "all set"). --}}
<x-filament::section :icon="$item['module']->icon()" collapsible :collapsed="$item['done'] === $item['total']">
    <x-slot name="heading">{{ $item['module']->name() }}</x-slot>
    <x-slot name="description">
        @if ($item['done'] === $item['total'])
            {{ __('app.home.all_done') }}
        @else
            {{ __('app.home.progress', ['done' => $item['done'], 'total' => $item['total']]) }}
        @endif
    </x-slot>
    @if ($item['read_only'])
        <x-slot name="afterHeader">
            <x-filament::badge color="warning">{{ __('app.license.read_only_badge') }}</x-filament::badge>
        </x-slot>
    @endif

    <div style="height:8px;border-radius:999px;background:rgba(127,127,127,.18);overflow:hidden;margin-bottom:16px">
        <div style="height:100%;width:{{ $item['percent'] }}%;background:{{ config('kiban.brand.blue') }}"></div>
    </div>

    @include('filament.pages.home-step-items', ['steps' => $item['steps'], 'start' => 1])
</x-filament::section>
