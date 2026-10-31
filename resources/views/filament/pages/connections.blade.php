@php
    $catalog = app(\App\Connections\ConnectionCatalog::class);
    $enabled = $catalog->enabled();
    $active = $this->active();
    $potential = $this->potential();
    $user = auth()->user();
    $card = 'border:1px solid rgba(127,127,127,.22);border-radius:12px;padding:12px 14px;display:flex;gap:12px';
@endphp

<x-filament-panels::page>
    <x-filament::section icon="heroicon-o-puzzle-piece">
        <x-slot name="heading">{{ __('connections.page.active') }}</x-slot>

        @if ($active === [])
            <p style="opacity:.75;font-size:14px;margin:0">{{ __('connections.page.none') }}</p>
        @else
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:12px">
                @foreach ($active as $connection)
                    <div data-connection="{{ $connection->key }}" style="{{ $card }}">
                        <x-filament::icon :icon="$connection->icon" style="width:22px;height:22px;flex:none;color:var(--primary-600);margin-top:2px" />
                        <div style="min-width:0;flex:1">
                            <div style="font-size:11px;font-weight:600;letter-spacing:.03em;text-transform:uppercase;color:var(--primary-600)">{{ $catalog->systemsLabel($connection, $enabled) }}</div>
                            <div style="font-size:15px;font-weight:700;margin-top:2px">{{ $connection->title() }}</div>
                            <p style="font-size:14px;margin:4px 0 0;line-height:1.45">{{ $connection->body() }}</p>
                            @if ($connection->matters($user) && ($where = $connection->urlFor($user)))
                                <a href="{{ $where }}" style="display:inline-block;margin-top:6px;font-size:14px;font-weight:600;color:var(--primary-600);text-decoration:underline">{{ __('connections.window.see_where') }} →</a>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    @foreach ($potential as $key => $item)
        <x-filament::section :icon="$item['module']->icon()" data-potential="{{ $key }}">
            <x-slot name="heading">{{ __('connections.page.with', ['system' => $item['module']->name()]) }}</x-slot>
            <x-slot name="description">{{ __('connections.page.with_help') }}</x-slot>

            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,320px),1fr));gap:12px">
                @foreach ($item['connections'] as $connection)
                    <div style="{{ $card }};border-style:dashed;opacity:.9">
                        <x-filament::icon :icon="$connection->icon" style="width:22px;height:22px;flex:none;opacity:.7;margin-top:2px" />
                        <div style="min-width:0;flex:1">
                            <div style="font-size:11px;font-weight:600;letter-spacing:.03em;text-transform:uppercase;opacity:.7">{{ $catalog->systemsLabel($connection, [...$enabled, $key]) }}</div>
                            <div style="font-size:15px;font-weight:700;margin-top:2px">{{ $connection->title() }}</div>
                            <p style="font-size:14px;margin:4px 0 0;line-height:1.45">{{ $connection->body() }}</p>
                        </div>
                    </div>
                @endforeach
            </div>

            <div style="margin-top:12px">
                <x-filament::button tag="a" :href="$this->plansUrl()" target="_blank" color="gray" icon="heroicon-o-arrow-top-right-on-square">
                    {{ __('connections.page.plans', ['system' => $item['module']->name()]) }}
                </x-filament::button>
            </div>
        </x-filament::section>
    @endforeach
</x-filament-panels::page>
