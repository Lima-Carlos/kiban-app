{{--
    A sheet of labels (A4, 3 columns × 7 rows of 63.5 × 38.1 mm, the usual sticker sheets). Opens with a short
    explanation and a "Print" button that are not printed.
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $title }}</title>
    <style>
        @page { size: A4; margin: 15mm 7mm; }
        * { box-sizing: border-box; }
        body { font-family: system-ui, -apple-system, "Segoe UI", sans-serif; margin: 0; color: #111827; background: #f3f4f6; }
        .bar { padding: 16px; display: flex; flex-wrap: wrap; gap: 12px; align-items: center; background: #fff; border-bottom: 1px solid #e5e7eb; }
        .bar h1 { font-size: 18px; margin: 0; flex: 1 1 260px; }
        .bar p { margin: 4px 0 0; font-size: 14px; color: #4b5563; }
        .bar button { background: #044299; color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font-size: 15px; font-weight: 600; cursor: pointer; }
        .sheet { width: 196mm; margin: 16px auto; background: #fff; display: grid; grid-template-columns: repeat(3, 63.5mm); grid-auto-rows: 38.1mm; column-gap: 2.75mm; padding: 4mm 0; }
        .label { display: flex; gap: 2.5mm; align-items: center; padding: 2.5mm 3mm; overflow: hidden; border: 1px dashed #d1d5db; }
        .label .qr { flex: none; width: 30mm; height: 30mm; }
        .label .qr svg { width: 100%; height: 100%; display: block; }
        .label .text { min-width: 0; font-size: 7.5pt; line-height: 1.25; }
        .label .company { font-size: 6.5pt; color: #6b7280; text-transform: uppercase; letter-spacing: .03em; }
        .label .title { font-size: 10.5pt; font-weight: 700; margin: 1mm 0; word-break: break-all; }
        .empty { text-align: center; padding: 40px 16px; color: #4b5563; }
        @media print {
            body { background: #fff; }
            .bar { display: none; }
            .sheet { margin: 0; padding: 0; }
            .label { border-color: transparent; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <div style="flex:1 1 260px">
            <h1>{{ $title }}</h1>
            <p>{{ trans_choice('app.labels.help', count($labels), ['count' => count($labels)]) }}</p>
        </div>
        @if ($labels !== [])
            <button type="button" onclick="window.print()">{{ __('app.labels.print') }}</button>
        @endif
    </div>
    @if ($labels === [])
        <p class="empty">{{ __('app.labels.empty') }}</p>
    @else
        <div class="sheet" data-labels="{{ count($labels) }}">
            @foreach ($labels as $label)
                <div class="label">
                    <div class="qr">{!! $label['qr'] !!}</div>
                    <div class="text">
                        <div class="company">{{ $company }}</div>
                        <div class="title">{{ $label['title'] }}</div>
                        @foreach (array_filter($label['lines']) as $line)
                            <div>{{ $line }}</div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </div>
    @endif
</body>
</html>
