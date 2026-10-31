{{-- The numbers on top of "PPE and tools": a tap filters the list (or opens the items). --}}
@php
    $cards = array_values(array_filter([
        ['count' => $counts['due'], 'label' => trans_choice('epi.summary.due', $counts['due']), 'color' => '#b91c1c', 'click' => "showOnly('situation', 'due')"],
        ['count' => $counts['soon'], 'label' => trans_choice('epi.summary.soon', $counts['soon']), 'color' => '#b45309', 'click' => "showOnly('situation', 'soon')"],
        ['count' => $counts['unsigned'], 'label' => trans_choice('epi.summary.unsigned', $counts['unsigned']), 'color' => '#b45309', 'click' => "showOnly('signature', 'pending')"],
        $withEpi ? ['count' => $counts['ca'], 'label' => trans_choice('epi.summary.ca', $counts['ca']), 'color' => '#b91c1c', 'url' => $itemsUrl.'?filtro=ca'] : null,
        ['count' => $counts['low'], 'label' => trans_choice('epi.summary.low', $counts['low']), 'color' => '#b91c1c', 'url' => $itemsUrl.'?filtro=low'],
    ]));
@endphp
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:10px">
    @foreach ($cards as $card)
        @php $tag = isset($card['url']) ? 'a' : 'button'; @endphp
        <{{ $tag }}
            @if (isset($card['url'])) href="{{ $card['url'] }}" @else type="button" wire:click="{{ $card['click'] }}" @endif
            style="text-align:left;border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:10px 14px;background:rgba(127,127,127,.04);cursor:pointer;display:flex;flex-direction:column;justify-content:flex-start;align-items:flex-start;gap:2px"
        >
            <div style="font-size:24px;font-weight:700;line-height:1.1;color:{{ $card['count'] > 0 ? $card['color'] : 'inherit' }};{{ $card['count'] > 0 ? '' : 'opacity:.45' }}">{{ $card['count'] }}</div>
            <div style="font-size:13px;opacity:.8">{{ $card['label'] }}</div>
        </{{ $tag }}>
    @endforeach
</div>
