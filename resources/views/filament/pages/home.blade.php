<x-filament-panels::page>
    @php($modules = $this->getModules())

    @forelse ($modules as $item)
        {{-- Block made for this person (e.g. the driver's vehicles and big buttons). --}}
        @if ($item['personal'])
            @include($item['personal']['view'], $item['personal']['data'] + ['module' => $item['module']])
        @endif

        {{-- Summary of the module (e.g. fleet spending and consumption) for who manages or follows it. --}}
        @foreach ($item['widgets'] as $widget)
            @livewire($widget, ['pageFilters' => ['period' => '3', 'home' => true]], key($item['module']->key().'-'.$widget))
        @endforeach

        @if ($item['total'] > 0)
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

                <ol style="display:flex;flex-direction:column;gap:10px;margin:0;padding:0;list-style:none">
                    @foreach ($item['steps'] as $index => $step)
                        <li style="display:flex;gap:12px;align-items:flex-start;padding:12px 14px;border:1px solid rgba(127,127,127,.2);border-radius:10px;{{ $step['available'] || $step['done'] ? '' : 'opacity:.6' }}">
                            <div style="flex:none;width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:13px;{{ $step['done'] ? 'background:#16a34a;color:#fff' : 'background:rgba(127,127,127,.15)' }}">
                                @if ($step['done'])
                                    <x-filament::icon icon="heroicon-m-check" style="width:16px;height:16px" />
                                @elseif (! $step['available'])
                                    <x-filament::icon icon="heroicon-m-lock-closed" style="width:14px;height:14px" />
                                @else
                                    {{ $index + 1 }}
                                @endif
                            </div>
                            <div style="flex:1;min-width:0">
                                <div style="font-weight:600">{{ $step['label'] }}</div>
                                @if ($step['description'])
                                    <div style="font-size:13px;opacity:.75;margin-top:2px">{{ $step['description'] }}</div>
                                @endif
                                @if (! $step['available'] && ! $step['done'])
                                    <div style="font-size:13px;margin-top:4px;color:#b45309">{{ __('app.home.locked', ['steps' => implode(', ', $step['missing'])]) }}</div>
                                @endif
                            </div>
                            @if ($step['available'] && ! $step['done'] && $step['url'])
                                <x-filament::button tag="a" :href="$step['url']" size="sm">{{ __('app.home.do_it') }}</x-filament::button>
                            @endif
                        </li>
                    @endforeach
                </ol>
            </x-filament::section>
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
