@php
    $digest = $this->digest();
@endphp

<x-filament-panels::page>
    @if ($digest['sections'] === [])
        <x-filament::section>
            <p style="opacity:.75;font-size:14px;margin:0">{{ __('digest.empty') }}</p>
        </x-filament::section>
    @else
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:14px;align-items:start">
            @foreach ($digest['sections'] as $section)
                <x-filament::section data-digest-module="{{ $section['module'] }}">
                    <x-slot name="heading">{{ $section['name'] }}</x-slot>
                    @if ($section['lines'] !== [])
                        <ul style="margin:0;padding-left:18px;list-style:disc;display:flex;flex-direction:column;gap:6px;font-size:14px">
                            @foreach ($section['lines'] as $line)
                                <li>{{ $line }}</li>
                            @endforeach
                        </ul>
                    @endif
                    @if ($section['attention'] !== [])
                        <div style="margin-top:{{ $section['lines'] !== [] ? '12px' : '0' }};font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:#b45309">{{ __('digest.attention') }}</div>
                        <ul style="margin:4px 0 0;padding-left:18px;list-style:disc;display:flex;flex-direction:column;gap:4px;font-size:14px">
                            @foreach ($section['attention'] as $item)
                                <li>{{ $item }}</li>
                            @endforeach
                        </ul>
                    @endif
                </x-filament::section>
            @endforeach
        </div>
    @endif
    <p style="font-size:12px;opacity:.7">{{ __('digest.footer') }}</p>
</x-filament-panels::page>
