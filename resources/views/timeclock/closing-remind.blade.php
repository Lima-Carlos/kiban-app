{{-- The people still to sign: who gets the bell notice (has a login) and, for everyone, the link and the free WhatsApp button. --}}
<div style="display:flex;flex-direction:column;font-size:14px">
    @forelse ($rows as $row)
        <div style="display:flex;flex-wrap:wrap;align-items:center;justify-content:space-between;gap:8px 16px;padding:10px 0;border-bottom:1px solid rgba(127,127,127,.2)" x-data="{ copied: false }">
            <div>
                <div style="font-weight:600">{{ $row['name'] }}</div>
                <div style="opacity:.7;font-size:13px">
                    {{ $row['login'] ? __('timeclock.closing.remind_bell') : __('timeclock.closing.remind_no_login') }}
                    @if ($row['reminded']) · {{ __('timeclock.closing.reminded_at', ['date' => $row['reminded']]) }}@endif
                </div>
            </div>
            <div style="display:flex;align-items:center;gap:16px">
                @if ($row['whatsapp'])
                    <x-filament::link :href="$row['whatsapp']" target="_blank" icon="heroicon-o-chat-bubble-left-right" color="success">WhatsApp</x-filament::link>
                @else
                    <span style="opacity:.6">{{ __('timeclock.closing.no_phone') }}</span>
                @endif
                <x-filament::link tag="button" type="button" icon="heroicon-o-clipboard-document"
                    x-on:click="navigator.clipboard.writeText(@js($row['link'])); copied = true; setTimeout(() => copied = false, 2000)">
                    <span x-show="! copied">{{ __('timeclock.closing.copy_link') }}</span>
                    <span x-show="copied" x-cloak>{{ __('timeclock.closing.copied') }}</span>
                </x-filament::link>
            </div>
        </div>
    @empty
        <p style="padding:8px 0;opacity:.7">{{ __('timeclock.closing.remind_nobody') }}</p>
    @endforelse
</div>
