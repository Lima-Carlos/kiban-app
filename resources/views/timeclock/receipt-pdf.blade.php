<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ __('timeclock.receipt.title') }}</title>
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 9px; color: #39383A; margin: 0; }
        h1 { font-size: 12px; color: #044299; margin: 0 0 4px; text-align: center; }
        .center { text-align: center; }
        .line { border-top: 1px dashed #999; margin: 8px 0; }
        .row { margin: 3px 0; }
        .label { color: #777; }
        .code { font-family: "DejaVu Sans Mono", monospace; font-size: 10px; font-weight: bold; }
    </style>
</head>
<body>
    <h1>{{ __('timeclock.receipt.title') }}</h1>
    <div class="center">{{ $company }}</div>
    <div class="line"></div>
    <div class="row"><span class="label">{{ __('timeclock.receipt.person') }}:</span> {{ $punch->employee->person->name }}</div>
    @if ($punch->employee->person->documentLabel())
        <div class="row"><span class="label">{{ __('timeclock.receipt.document') }}:</span> {{ $punch->employee->person->documentLabel() }}</div>
    @endif
    <div class="row"><span class="label">{{ __('timeclock.receipt.when') }}:</span> {{ $local->translatedFormat(__('app.date_format')) }} {{ $local->format('H:i:s') }}</div>
    <div class="row"><span class="label">{{ __('timeclock.receipt.timezone') }}:</span> {{ $punch->timezone }} (UTC{{ $local->format('P') }})</div>
    <div class="row"><span class="label">{{ __('timeclock.receipt.nsr') }}:</span> {{ $punch->nsr }}</div>
    @if ($punch->hasFlag('offline') && $punch->created_at)
        <div class="row"><span class="label">{{ __('timeclock.receipt.received') }}:</span> {{ $punch->created_at->setTimezone($punch->timezone)->translatedFormat(__('app.date_format')) }} {{ $punch->created_at->setTimezone($punch->timezone)->format('H:i:s') }}</div>
        <div class="row" style="font-size:9px">{{ __('timeclock.receipt.offline') }}</div>
    @endif
    <div class="row"><span class="label">{{ __('timeclock.receipt.how') }}:</span> {{ __('timeclock.punches.sources.'.$punch->source) }}@if ($punch->kiosk) · {{ $punch->kiosk->name }}@endif</div>
    @if ($workplace)
        <div class="row"><span class="label">{{ __('timeclock.fields.workplace') }}:</span> {{ $workplace->name }}</div>
    @endif
    @if ($punch->latitude !== null)
        <div class="row"><span class="label">{{ __('timeclock.receipt.location') }}:</span> {{ number_format($punch->latitude, 6, '.', '') }}, {{ number_format($punch->longitude, 6, '.', '') }}</div>
    @endif
    <div class="line"></div>
    <div class="center label">{{ __('timeclock.receipt.code') }}</div>
    <div class="center code">{{ $code }}</div>
    <div class="line"></div>
    <div class="center label">Kiban Ponto</div>
</body>
</html>
