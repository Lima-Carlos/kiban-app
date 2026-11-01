{{-- Steps of a first-steps list (done, locked with what is missing, "Do it now"). Numbered from $start. --}}
<ol style="display:flex;flex-direction:column;gap:10px;margin:0;padding:0;list-style:none">
    @foreach ($steps as $index => $step)
        <li style="display:flex;flex-wrap:wrap;gap:12px;align-items:flex-start;padding:12px 14px;border:1px solid rgba(127,127,127,.2);border-radius:10px;{{ $step['available'] || $step['done'] ? '' : 'opacity:.6' }}">
            <div style="flex:none;width:28px;height:28px;border-radius:999px;display:flex;align-items:center;justify-content:center;font-weight:bold;font-size:13px;{{ $step['done'] ? 'background:#16a34a;color:#fff' : 'background:rgba(127,127,127,.15)' }}">
                @if ($step['done'])
                    <x-filament::icon icon="heroicon-m-check" style="width:16px;height:16px" />
                @elseif (! $step['available'])
                    <x-filament::icon icon="heroicon-m-lock-closed" style="width:14px;height:14px" />
                @else
                    {{ $start + $index }}
                @endif
            </div>
            <div style="flex:1 1 180px;min-width:0">
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
