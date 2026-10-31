{{-- Lets phones install the app (icon on the home screen). --}}
<link rel="manifest" href="{{ rtrim(config('app.url'), '/') }}/manifest.webmanifest">
<meta name="theme-color" content="{{ config('kiban.brand.blue') }}">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ config('app.name') }}">
<link rel="apple-touch-icon" href="{{ asset('images/brand/icon.png') }}">
{{-- Phone: tabs above a list wrap to a second line instead of being cut at the side ("Cance..."). --}}
<style>
    @media (max-width: 640px) {
        .fi-tabs:not(.fi-vertical) { flex-wrap: wrap; overflow-x: visible; row-gap: .25rem; }
    }
</style>
<script>
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
    }
</script>
