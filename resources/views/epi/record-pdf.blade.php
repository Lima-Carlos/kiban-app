<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __($brazil ? 'epi.record.title_brazil' : 'epi.record.title') }} · {{ $person->name }}</title>
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        @page { margin: 22px 26px; }
        body { font-size: 9px; color: #39383A; margin: 0; }
        .top { background: {{ $color }}; color: #fff; padding: 10px 14px; }
        .top td { color: #fff; vertical-align: middle; }
        h1 { font-size: 14px; margin: 2px 0 0; }
        h2 { font-size: 10px; color: {{ $color }}; margin: 12px 0 5px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
        .label { color: #777; font-size: 7.5px; }
        table.info { width: 100%; border-collapse: collapse; margin-top: 8px; }
        table.info td { padding: 2px 8px 2px 0; vertical-align: top; }
        table.grid { width: 100%; border-collapse: collapse; }
        table.grid th { background: #f1f3f6; text-align: left; font-size: 7.5px; text-transform: uppercase; color: #555; padding: 4px 5px; border: 1px solid #d6d9de; }
        table.grid td { padding: 4px 5px; border: 1px solid #d6d9de; vertical-align: middle; }
        .sign { text-align: center; width: 150px; }
        .sign img { max-height: 46px; max-width: 150px; }
        .code { font-family: "DejaVu Sans Mono", monospace; font-size: 6.5px; color: #666; }
        .pending { color: #b45309; font-weight: bold; }
        .terms { white-space: pre-line; line-height: 1.45; font-size: 8px; border: 1px solid #ddd; padding: 6px 8px; }
        .footer { margin-top: 12px; font-size: 7.5px; color: #666; border-top: 1px solid #ddd; padding-top: 5px; }
        .muted { color: #777; }
    </style>
</head>
<body>
    <div class="top">
        <table style="width:100%"><tr>
            <td>
                <div style="font-size:8px">{{ $company }}</div>
                <h1>{{ __($brazil ? 'epi.record.title_brazil' : 'epi.record.title') }}</h1>
            </td>
            <td style="text-align:right">
                @if ($logo)<img src="{{ $logo }}" style="max-height:40px;max-width:150px;background:#fff;padding:4px">@endif
            </td>
        </tr></table>
    </div>

    <table class="info">
        <tr>
            <td style="width:34%"><div class="label">{{ __('epi.record.company') }}</div>{{ $company }}@if ($profile->document) · {{ $profile->document }}@endif</td>
            <td style="width:33%"><div class="label">{{ __('epi.fields.person') }}</div><strong>{{ $person->name }}</strong></td>
            <td style="width:33%"><div class="label">{{ __('epi.record.document') }}</div>{{ $person->documentLabel() ?? '—' }}</td>
        </tr>
        <tr>
            <td><div class="label">{{ __('epi.record.address') }}</div>{{ $profile->address ?: '—' }}</td>
            <td><div class="label">{{ __('app.teams.team') }}</div>{{ $person->team?->name ?? '—' }}</td>
            <td><div class="label">{{ __('epi.record.generated') }}</div>{{ $generated->translatedFormat(__('app.datetime_format')) }}</td>
        </tr>
    </table>

    <h2>{{ __('epi.record.deliveries') }}</h2>
    <table class="grid">
        <thead>
            <tr>
                <th style="width:58px">{{ __('epi.record.date') }}</th>
                <th>{{ __('epi.fields.item') }}</th>
                <th style="width:70px">{{ __($brazil ? 'epi.fields.ca_short' : 'epi.fields.certificate') }}</th>
                <th style="width:28px">{{ __('epi.record.qty') }}</th>
                <th style="width:85px">{{ __('epi.fields.reason') }}</th>
                <th style="width:150px">{{ __('epi.record.returns') }}</th>
                <th class="sign">{{ __('epi.record.signature') }}</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($deliveries as $delivery)
                @foreach ($delivery->lines as $line)
                    <tr>
                        <td>{{ $delivery->delivered_on->translatedFormat(__('app.date_format')) }}</td>
                        <td>
                            {{ $line->label() }}
                            <div class="muted" style="font-size:7px">{{ $line->kind->getLabel() }}@if ($line->replace_on) · {{ __('epi.details.replace_on', ['date' => $line->replace_on->translatedFormat(__('app.date_format'))]) }}@endif</div>
                        </td>
                        <td>{{ $line->ca_number ?: '—' }}@if ($line->ca_expires_on)<div class="muted" style="font-size:7px">{{ __('epi.items.valid_until', ['date' => $line->ca_expires_on->translatedFormat(__('app.date_format'))]) }}</div>@endif</td>
                        <td style="text-align:center">{{ $line->quantity }}</td>
                        <td>{{ $delivery->reason->getLabel() }}</td>
                        <td>
                            @forelse ($line->returns as $return)
                                <div>{{ $return->returned_on->translatedFormat(__('app.date_format')) }} · {{ $return->quantity }} · {{ $return->outcome->getLabel() }}</div>
                            @empty
                                <span class="muted">—</span>
                            @endforelse
                        </td>
                        @if ($loop->first)
                            <td class="sign" rowspan="{{ $delivery->lines->count() }}">
                                @if ($delivery->isSigned())
                                    @if ($signatures[$delivery->getKey()])<img src="{{ $signatures[$delivery->getKey()] }}"><br>@endif
                                    <span style="font-size:7px">{{ $delivery->signer_name }} · {{ $delivery->signed_at->setTimezone($timezone)->translatedFormat(__('app.datetime_format')) }}</span><br>
                                    <span class="code">{{ $codes[$delivery->getKey()] }}</span>
                                @else
                                    <span class="pending">{{ __('epi.sign.pending') }}</span>
                                @endif
                            </td>
                        @endif
                    </tr>
                @endforeach
            @empty
                <tr><td colspan="7" class="muted">{{ __('epi.person.empty') }}</td></tr>
            @endforelse
        </tbody>
    </table>

    <h2>{{ __('epi.record.terms') }}</h2>
    <div class="terms">{{ $terms }}</div>

    <div class="footer">
        {{ __('epi.record.electronic') }}
        <br><strong>{{ $company }}</strong>@if ($profile->document) · {{ $profile->document }}@endif @if ($profile->phone) · {{ $profile->phone }}@endif @if ($profile->email) · {{ $profile->email }}@endif
        @if ($profile->address)<br>{{ $profile->address }}@endif
    </div>
</body>
</html>
