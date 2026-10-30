@php
    use App\Modules\Field\Services\FieldFormat;
    use App\Support\Country;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $order->label() }} · {{ $company }}</title>
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; font-family: Arial, Helvetica, sans-serif; background: #f4f5f7; color: #39383A; }
        .page { max-width: 760px; margin: 0 auto; padding: 16px; }
        .top { background: {{ $color }}; color: #fff; border-radius: 12px 12px 0 0; padding: 18px 20px; display: flex; gap: 16px; align-items: center; justify-content: space-between; flex-wrap: wrap; }
        .top img { max-height: 56px; max-width: 180px; background: #fff; border-radius: 8px; padding: 6px; }
        .card { background: #fff; border-radius: 0 0 12px 12px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,.08); }
        h1 { font-size: 20px; margin: 0; }
        h2 { font-size: 15px; color: {{ $color }}; margin: 22px 0 8px; border-bottom: 1px solid #eee; padding-bottom: 6px; }
        .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 8px 18px; font-size: 14px; }
        .label { color: #6b7280; font-size: 12px; }
        ul { margin: 0; padding-left: 18px; font-size: 14px; line-height: 1.7; }
        .photos { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
        .photos img { width: 100%; height: 120px; object-fit: cover; border-radius: 8px; }
        .signature img { max-height: 110px; border-bottom: 1px solid #999; }
        .footer { font-size: 12px; color: #6b7280; margin-top: 22px; white-space: pre-line; }
        .button { display: inline-block; margin-top: 18px; padding: 11px 18px; background: {{ $color }}; color: #fff; border-radius: 8px; text-decoration: none; font-weight: bold; }
        .code { font-family: monospace; font-weight: bold; }
        .stars { display: inline-flex; flex-direction: row-reverse; margin-bottom: 8px; }
        .stars input { position: absolute; opacity: 0; width: 1px; height: 1px; }
        .stars label { font-size: 36px; color: #d1d5db; cursor: pointer; padding: 0 2px; }
        .stars input:checked ~ label, .stars label:hover, .stars label:hover ~ label { color: #f59e0b; }
        .stars input:focus-visible + label { outline: 2px solid {{ $color }}; border-radius: 4px; }
        .stars-show { color: #f59e0b; font-size: 20px; letter-spacing: 1px; }
    </style>
</head>
<body>
<div class="page">
    <div class="top">
        <div>
            <div style="font-size:13px;opacity:.85">{{ __('field.report.title') }}</div>
            <h1>{{ $order->label() }} · {{ $order->serviceType->name }}</h1>
        </div>
        @if ($logo)
            <img src="{{ $logo }}" alt="{{ $company }}">
        @else
            <strong style="font-size:18px">{{ $company }}</strong>
        @endif
    </div>
    <div class="card">
        <div class="grid">
            <div><div class="label">{{ __('field.fields.customer') }}</div>{{ $order->customer->displayName() }}</div>
            <div><div class="label">{{ __('field.fields.site') }}</div>{{ $order->site?->address() ?: '—' }}</div>
            <div><div class="label">{{ __('field.fields.technician') }}</div>{{ $order->technician?->name ?? '—' }}</div>
            <div><div class="label">{{ __('field.report.when') }}</div>{{ $arrived?->translatedFormat(__('app.date_format')) }} · {{ $arrived?->format('H:i') }}–{{ $finished?->format('H:i') }}</div>
        </div>

        @if ($order->reported)
            <h2>{{ __('field.fields.reported') }}</h2>
            <p style="font-size:14px;white-space:pre-line;margin:0">{{ $order->reported }}</p>
        @endif

        @if ($order->work_done)
            <h2>{{ __('field.execute.work_done') }}</h2>
            <p style="font-size:14px;white-space:pre-line;margin:0">{{ $order->work_done }}</p>
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
            <div class="photos">@foreach ($photos as $photo)<img src="{{ $photo }}" alt="">@endforeach</div>
            @if ($morePhotos > 0)<p class="label">{{ __('field.report.more_photos', ['count' => $morePhotos]) }}</p>@endif
        @endif

        @if ($signature)
            <h2>{{ __('field.report.signature') }}</h2>
            <div class="signature"><img src="{{ $signature }}" alt=""></div>
            <div style="font-size:14px">{{ $order->signed_by }} · {{ $order->signed_at?->setTimezone(\App\Modules\Field\Filament\Orders\OrderResource::timezone())->translatedFormat(__('app.datetime_format')) }}</div>
        @endif

        {{-- The customer's rating (once). --}}
        <h2 id="rating">{{ __('field.report.rating_title') }}</h2>
        @if ($order->rated_at)
            <p style="font-size:14px;margin:0"><span class="stars-show">{{ str_repeat('★', $order->rating) }}<span style="color:#d1d5db">{{ str_repeat('★', 5 - $order->rating) }}</span></span> · {{ __('field.report.rating_thanks') }}</p>
        @else
            <form method="POST" action="{{ $rateUrl }}">
                @csrf
                <p style="font-size:14px;margin:0 0 4px">{{ __('field.report.rating_question', ['company' => $company]) }}</p>
                <div class="stars">
                    @foreach ([5, 4, 3, 2, 1] as $star)
                        <input type="radio" name="rating" id="star{{ $star }}" value="{{ $star }}" required>
                        <label for="star{{ $star }}" title="{{ $star }}">★</label>
                    @endforeach
                </div>
                <textarea name="comment" rows="2" maxlength="1000" placeholder="{{ __('field.report.rating_comment') }}" style="width:100%;border:1px solid #d1d5db;border-radius:8px;padding:10px;font-size:14px;font-family:inherit"></textarea>
                <button type="submit" class="button" style="border:0;cursor:pointer;margin-top:8px">{{ __('field.report.rating_send') }}</button>
            </form>
        @endif

        <a class="button" href="{{ $pdfUrl }}">{{ __('field.report.download') }}</a>

        <div class="footer" style="white-space:normal;line-height:1.6">
            <div><strong>{{ $company }}</strong>{{ collect([$profile->document, $profile->phone, $profile->email, $profile->website])->filter()->map(fn ($value) => ' · '.$value)->implode('') }}</div>
            @if ($profile->address)<div>{{ $profile->address }}</div>@endif
            @if ($profile->report_footer)<div style="white-space:pre-line;margin-top:6px">{{ $profile->report_footer }}</div>@endif
            <div style="margin-top:6px">{{ __('field.report.code') }}: <span class="code">{{ $code }}</span></div>
        </div>
    </div>
</div>
</body>
</html>
