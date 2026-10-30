@php use App\Modules\Field\Services\FieldFormat; @endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <title>{{ $order->label() }}</title>
    <style>
        * { font-family: "DejaVu Sans", sans-serif; }
        body { font-size: 10px; color: #39383A; margin: 0; }
        .top { background: {{ $color }}; color: #fff; padding: 12px 16px; }
        .top td { color: #fff; vertical-align: middle; }
        h1 { font-size: 15px; margin: 2px 0 0; }
        h2 { font-size: 11px; color: {{ $color }}; margin: 14px 0 5px; border-bottom: 1px solid #ddd; padding-bottom: 3px; }
        .body { padding: 6px 16px; }
        .label { color: #777; font-size: 8px; }
        table.info { width: 100%; border-collapse: collapse; }
        table.info td { padding: 3px 6px 3px 0; vertical-align: top; width: 50%; }
        ul { margin: 0; padding-left: 14px; line-height: 1.6; }
        .photo { width: 155px; height: 115px; margin: 0 6px 6px 0; }
        .footer { margin-top: 16px; font-size: 8px; color: #666; border-top: 1px solid #ddd; padding-top: 6px; }
        .code { font-family: "DejaVu Sans Mono", monospace; font-weight: bold; }
    </style>
</head>
<body>
    <div class="top">
        <table style="width:100%"><tr>
            <td>
                <div style="font-size:9px">{{ __('field.report.title') }}</div>
                <h1>{{ $order->label() }} · {{ $order->serviceType->name }}</h1>
            </td>
            <td style="text-align:right">
                @if ($logo)<img src="{{ $logo }}" style="max-height:44px;max-width:150px;background:#fff;padding:4px">@else<strong>{{ $company }}</strong>@endif
            </td>
        </tr></table>
    </div>
    <div class="body">
        <table class="info">
            <tr>
                <td><div class="label">{{ __('field.fields.customer') }}</div>{{ $order->customer->displayName() }}</td>
                <td><div class="label">{{ __('field.fields.site') }}</div>{{ $order->site?->address() ?: '—' }}</td>
            </tr>
            <tr>
                <td><div class="label">{{ __('field.fields.technician') }}</div>{{ $order->technician?->name ?? '—' }}</td>
                <td><div class="label">{{ __('field.report.when') }}</div>{{ $arrived?->translatedFormat(__('app.date_format')) }} · {{ $arrived?->format('H:i') }}–{{ $finished?->format('H:i') }}</td>
            </tr>
        </table>

        @if ($order->reported)
            <h2>{{ __('field.fields.reported') }}</h2>
            <div style="white-space:pre-line">{{ $order->reported }}</div>
        @endif
        @if ($order->work_done)
            <h2>{{ __('field.execute.work_done') }}</h2>
            <div style="white-space:pre-line">{{ $order->work_done }}</div>
        @endif
        @if ($answers !== [])
            <h2>{{ __('field.report.checklist') }}</h2>
            <ul>@foreach ($answers as $line)<li>{{ $line }}</li>@endforeach</ul>
        @endif
        @if ($order->materials->isNotEmpty())
            <h2>{{ __('field.report.materials') }}</h2>
            <ul>@foreach ($order->materials as $material)<li>{{ FieldFormat::quantity($material->quantity, $material->unit) }} · {{ $material->description }}</li>@endforeach</ul>
        @endif
        @if ($photos !== [])
            <h2>{{ __('field.orders.photos') }}</h2>
            <div>@foreach ($photos as $photo)<img class="photo" src="{{ $photo }}">@endforeach</div>
            @if ($morePhotos > 0)<div class="label">{{ __('field.report.more_photos', ['count' => $morePhotos]) }}</div>@endif
        @endif
        @if ($signature)
            <h2>{{ __('field.report.signature') }}</h2>
            <img src="{{ $signature }}" style="max-height:70px;border-bottom:1px solid #999">
            <div>{{ $order->signed_by }} · {{ $order->signed_at?->setTimezone(\App\Modules\Field\Filament\Orders\OrderResource::timezone())->translatedFormat(__('app.datetime_format')) }}</div>
        @endif

        <div class="footer">
            <strong>{{ $company }}</strong>@if ($profile->document) · {{ $profile->document }}@endif @if ($profile->phone) · {{ $profile->phone }}@endif @if ($profile->email) · {{ $profile->email }}@endif
            @if ($profile->address)<br>{{ $profile->address }}@endif
            @if ($profile->report_footer)<br>{{ $profile->report_footer }}@endif
            <br>{{ __('field.report.code') }}: <span class="code">{{ $code }}</span>
        </div>
    </div>
</body>
</html>
