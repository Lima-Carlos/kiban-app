{{-- What the person signs: the items of the delivery and the acknowledgment text. --}}
<div style="display:flex;flex-direction:column;gap:12px">
    <div style="border:1px solid rgba(127,127,127,.25);border-radius:12px;padding:10px 14px">
        <div style="font-size:12px;opacity:.7;margin-bottom:4px">
            {{ $delivery->delivered_on->translatedFormat(__('app.date_format')) }} · {{ $delivery->reason->getLabel() }}
        </div>
        <ul style="margin:0;padding:0;list-style:none">
            @foreach ($delivery->lines as $line)
                <li style="display:flex;justify-content:space-between;gap:12px;padding:5px 0;border-top:{{ $loop->first ? '0' : '1px solid rgba(127,127,127,.15)' }}">
                    <span>
                        <strong>{{ $line->label() }}</strong>
                        @if ($line->ca_number)
                            <span style="font-size:12px;opacity:.7"> · {{ __('epi.fields.ca_short') }} {{ $line->ca_number }}</span>
                        @endif
                    </span>
                    <span style="white-space:nowrap">× {{ $line->quantity }}</span>
                </li>
            @endforeach
        </ul>
    </div>
    <div style="font-size:13px;line-height:1.5;white-space:pre-line;background:rgba(4,66,153,.06);border-radius:12px;padding:10px 14px">{{ $terms }}</div>
</div>
