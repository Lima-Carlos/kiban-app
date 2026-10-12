@props(['title' => null])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $title ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    <link rel="icon" href="{{ asset('images/brand/favicon.png') }}">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; font-family: Arial, Helvetica, sans-serif; background: #f4f5f7; color: #39383A; display: flex; flex-direction: column; align-items: center; justify-content: center; }
        .card { width: calc(100% - 32px); max-width: 460px; margin: 32px 16px; background: #fff; border-radius: 12px; box-shadow: 0 1px 3px rgba(0,0,0,.08); padding: 32px; }
        .logo { display: block; margin: 0 auto 20px; height: 52px; }
        h1 { font-size: 21px; margin: 0 0 8px; text-align: center; }
        p { text-align: center; color: #6b7280; font-size: 14px; line-height: 1.55; margin: 0 0 12px; }
        label { display: block; font-size: 14px; font-weight: bold; margin: 16px 0 6px; }
        .address { display: flex; align-items: center; border: 1px solid #d1d5db; border-radius: 8px; overflow: hidden; }
        .address input { flex: 1; min-width: 0; border: 0; padding: 11px 12px; font-size: 15px; }
        .address input:focus { outline: none; }
        .address:focus-within { outline: 2px solid #044299; border-color: #044299; }
        .address span { padding: 0 12px; color: #6b7280; font-size: 14px; background: #f9fafb; align-self: stretch; display: flex; align-items: center; }
        .button, button { display: block; width: 100%; margin-top: 20px; padding: 12px; background: #044299; color: #fff; border: 0; border-radius: 8px; font-size: 15px; font-weight: bold; cursor: pointer; text-align: center; text-decoration: none; }
        .button.secondary { background: #fff; color: #044299; border: 1px solid #044299; margin-top: 10px; }
        .muted { font-size: 13px; }
        .languages { position: absolute; top: 16px; right: 24px; display: flex; gap: 12px; font-size: 13px; }
        .languages a { color: #6b7280; text-decoration: none; } .languages a.active { color: #044299; font-weight: bold; }
    </style>
</head>
<body>
    <nav class="languages">
        @foreach (config('kiban.locales') as $code => $name)
            <a href="{{ route('locale.switch', $code) }}" @class(['active' => app()->getLocale() === $code])>{{ $name }}</a>
        @endforeach
    </nav>
    <main class="card">
        <img class="logo" src="{{ asset('images/brand/logo.png') }}" alt="{{ config('app.name') }}">
        {{ $slot }}
    </main>
</body>
</html>
