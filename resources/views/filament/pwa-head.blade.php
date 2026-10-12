{{-- Lets phones install the app (icon on the home screen). --}}
<link rel="manifest" href="{{ url('/manifest.webmanifest') }}">
<meta name="theme-color" content="{{ config('kiban.brand.blue') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<link rel="apple-touch-icon" href="{{ asset('images/brand/icon.png') }}">
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
</script>
