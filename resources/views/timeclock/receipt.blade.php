<x-layout :title="__('timeclock.receipt.title')">
    <h1>{{ __('timeclock.receipt.title') }}</h1>
    <p>{{ $company }}</p>

    <div style="text-align:left;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;margin:14px 0;line-height:1.7;font-size:14px">
        <div><strong>{{ __('timeclock.receipt.person') }}:</strong> {{ $punch->employee->person->name }}</div>
        @if ($punch->employee->person->documentLabel())
            <div><strong>{{ __('timeclock.receipt.document') }}:</strong> {{ $punch->employee->person->documentLabel() }}</div>
        @endif
        <div><strong>{{ __('timeclock.receipt.when') }}:</strong> {{ $local->translatedFormat(__('app.date_format')) }} {{ $local->format('H:i:s') }} ({{ $punch->timezone }})</div>
        <div><strong>{{ __('timeclock.receipt.nsr') }}:</strong> {{ $punch->nsr }}</div>
        @if ($punch->hasFlag('offline') && $punch->created_at)
            <div><strong>{{ __('timeclock.receipt.received') }}:</strong> {{ $punch->created_at->setTimezone($punch->timezone)->translatedFormat(__('app.date_format')) }} {{ $punch->created_at->setTimezone($punch->timezone)->format('H:i:s') }}</div>
            <div class="muted" style="font-size:13px">{{ __('timeclock.receipt.offline') }}</div>
        @endif
        <div><strong>{{ __('timeclock.receipt.how') }}:</strong> {{ __('timeclock.punches.sources.'.$punch->source) }}</div>
        @if ($workplace)
            <div><strong>{{ __('timeclock.fields.workplace') }}:</strong> {{ $workplace->name }}</div>
        @endif
        <div><strong>{{ __('timeclock.receipt.code') }}:</strong> <span style="font-family:monospace">{{ $code }}</span></div>
    </div>

    <a class="button" href="{{ url($pdfUrl) }}">{{ __('timeclock.receipt.download') }}</a>
    <p class="muted" style="margin-top:14px">{{ __('timeclock.receipt.help') }}</p>
</x-layout>
