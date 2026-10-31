{{-- One delivery: the items (and returns), the signature and its record (moment, IP, device, check code). --}}
<div style="display:flex;flex-direction:column;gap:14px;font-size:14px">
    <div style="opacity:.8">
        {{ $delivery->person->name }} · {{ $delivery->reason->getLabel() }}
        @if ($delivery->creator)
            · {{ __('epi.details.by', ['name' => $delivery->creator->name]) }}
        @endif
    </div>
    @if ($delivery->notes)
        <div style="white-space:pre-line">{{ $delivery->notes }}</div>
    @endif

    <div style="border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:8px 14px">
        @foreach ($delivery->lines as $line)
            <div style="padding:6px 0;border-top:{{ $loop->first ? '0' : '1px solid rgba(127,127,127,.15)' }}">
                <div style="display:flex;justify-content:space-between;gap:12px">
                    <strong>{{ $line->label() }}</strong>
                    <span>× {{ $line->quantity }}</span>
                </div>
                <div style="font-size:12px;opacity:.75">
                    {{ $line->kind->getLabel() }}
                    @if ($line->ca_number) · {{ __('epi.fields.ca_short') }} {{ $line->ca_number }}@endif
                    @if ($line->replace_on) · {{ __('epi.details.replace_on', ['date' => $line->replace_on->translatedFormat(__('app.date_format'))]) }}@endif
                </div>
                @foreach ($line->returns as $return)
                    <div style="font-size:12px;margin-top:2px">
                        ↩ {{ __('epi.details.returned', ['count' => $return->quantity, 'date' => $return->returned_on->translatedFormat(__('app.date_format'))]) }}
                        — {{ $return->reason->getLabel() }} · {{ $return->outcome->getLabel() }}
                    </div>
                @endforeach
            </div>
        @endforeach
    </div>

    @if ($delivery->isSigned())
        <div>
            <div style="font-weight:600;margin-bottom:4px">{{ __('epi.fields.signature') }}</div>
            @if ($signature)
                <img src="{{ $signature }}" alt="" style="max-width:100%;max-height:120px;background:#fff;border-bottom:1px solid #9ca3af">
            @endif
            <div style="font-size:12px;opacity:.8;margin-top:4px;line-height:1.6">
                {{ $delivery->signer_name }} · {{ $delivery->signed_at->setTimezone($timezone)->translatedFormat(__('app.datetime_format')) }}<br>
                {{ $delivery->signed_on_own_device ? __('epi.details.own_device') : __('epi.details.manager_device') }}
                @if ($delivery->signed_ip) · IP {{ $delivery->signed_ip }}@endif<br>
                @if ($delivery->signed_user_agent)<span style="word-break:break-all">{{ __('epi.details.device') }}: {{ \Illuminate\Support\Str::limit($delivery->signed_user_agent, 140) }}</span><br>@endif
                {{ __('epi.details.code') }}: <strong style="font-family:monospace">{{ $code }}</strong>
                @if ($intact)
                    · <span style="color:#15803d">✓ {{ __('epi.details.intact') }}</span>
                @else
                    · <span style="color:#b91c1c">⚠ {{ __('epi.details.changed') }}</span>
                @endif
            </div>
        </div>
        <details style="font-size:12px">
            <summary style="cursor:pointer">{{ __('epi.details.terms') }}</summary>
            <div style="white-space:pre-line;margin-top:6px;opacity:.85">{{ $delivery->terms }}</div>
        </details>
    @else
        <div style="color:#b45309">⏳ {{ __('epi.sign.pending_long') }}</div>
    @endif
</div>
