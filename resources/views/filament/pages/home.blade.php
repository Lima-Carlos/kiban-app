<x-filament-panels::page>
    @php($modules = $this->getModules())
    @php($attention = $this->getAttention())

    {{-- "There is news in the integrations of your systems. See" (owner, administrators, managers; until seen or closed). --}}
    @if ($this->showsIntegrationsNote())
        <div data-integrations-note
             style="padding:7px 12px;border-radius:10px;display:flex;align-items:center;gap:10px;font-size:13px;line-height:1.3;background:rgba(4,66,153,.08)">
            <x-filament::icon icon="heroicon-m-puzzle-piece" style="width:16px;height:16px;flex:none;color:var(--primary-600)" />
            <span style="flex:1;min-width:0">{{ __('connections.home.notice') }}</span>
            <a href="{{ \App\Filament\Pages\ConnectionsPage::getUrl() }}" style="flex:none;font-weight:600;text-decoration:underline;color:var(--primary-600)">{{ __('connections.home.see') }}</a>
            <button type="button" wire:click="hideIntegrationsNote" aria-label="{{ __('connections.home.close') }}" title="{{ __('connections.home.close') }}"
                    style="flex:none;display:flex;padding:2px;border-radius:6px;opacity:.6;background:none;border:0;cursor:pointer;color:inherit">
                <x-filament::icon icon="heroicon-m-x-mark" style="width:16px;height:16px" />
            </button>
        </div>
        <style>.dark [data-integrations-note] { background: rgba(96,165,250,.12) !important; } .dark [data-integrations-note] a { color: var(--primary-400) !important; }</style>
    @endif

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
