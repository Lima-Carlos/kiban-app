<x-layout :title="__('app.central.title')">
    <h1>{{ __('app.central.title') }}</h1>
    <p>{{ __('app.central.intro') }}</p>

    <form method="get" action="{{ route('central.go') }}">
        <label for="company">{{ __('app.central.address') }}</label>
        <div class="address">
            <input id="company" name="company" value="{{ old('company', request('company')) }}" autocomplete="off" autocapitalize="none" required autofocus placeholder="{{ __('app.central.placeholder') }}">
            <span>.{{ config('kiban.tenant_domain') }}</span>
        </div>
        @if ($notFound)
            <p style="color:#b42318;text-align:left;margin-top:8px">{{ __('app.central.not_found') }}</p>
        @endif
        <button type="submit">{{ __('app.central.go') }}</button>
    </form>

    <p class="muted" style="margin-top:18px">{!! __('app.central.account', ['url' => e(config('kiban.core.url').'/conta')]) !!}</p>
</x-layout>
