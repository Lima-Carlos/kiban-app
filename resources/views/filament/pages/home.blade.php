<x-filament-panels::page>
    @php($modules = $this->getModules())
    @php($attention = $this->getAttention())

    {{-- Urgent and still open: it disappears when solved. --}}
    @if ($attention !== [])
        <x-filament::section icon="heroicon-o-exclamation-triangle" icon-color="danger">
            <x-slot name="heading">{{ __('app.notices.attention') }}</x-slot>
            @foreach ($attention as $item)
                <a @if ($item['url']) href="{{ $item['url'] }}" @endif
                   style="display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid rgba(127,127,127,.2);color:inherit;text-decoration:none">
                    <x-filament::badge :color="$item['color']" icon="heroicon-m-exclamation-circle">!</x-filament::badge>
                    <span>{{ $item['text'] }}</span>
                </a>
            @endforeach
        </x-filament::section>
    @endif

    @forelse ($modules as $item)
        @php($setupPending = $item['total'] > 0 && $item['done'] < $item['total'])

        {{-- While the first steps are not done, they come first: a new company sees what to do, not a page of zeros. --}}
        @if ($setupPending)
            @include('filament.pages.home-steps', ['item' => $item])
        @endif

        {{-- Block made for this person (e.g. the driver's vehicles and big buttons). --}}
        @if ($item['personal'])
            @include($item['personal']['view'], $item['personal']['data'] + ['module' => $item['module']])
        @endif

        {{-- Summary of the module (e.g. fleet spending and consumption) for who manages or follows it. --}}
        @foreach ($item['widgets'] as $widget)
            @livewire($widget, ['pageFilters' => ['period' => '3', 'home' => true]], key($item['module']->key().'-'.$widget))
        @endforeach

        {{-- All done: the list stays at the end, closed (one line: "all set"). --}}
        @if ($item['total'] > 0 && ! $setupPending)
            @include('filament.pages.home-steps', ['item' => $item])
        @endif
    @empty
        @if ($this->companyHasModules())
            {{-- The company has systems, but this person was not given access to any yet. --}}
            <x-filament::section>
                <x-slot name="heading">{{ __('app.home.no_access') }}</x-slot>
                <x-slot name="description">{{ __('app.home.no_access_help') }}</x-slot>
            </x-filament::section>
        @else
            <x-filament::section>
                <x-slot name="heading">{{ __('app.home.no_modules') }}</x-slot>
                <x-slot name="description">{{ __('app.home.no_modules_help') }}</x-slot>
            </x-filament::section>
        @endif
    @endforelse
</x-filament-panels::page>
