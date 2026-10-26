<x-layout :title="__('app.no_access.title')">
    <h1>{{ __('app.no_access.title') }}</h1>
    <p>{{ $tenant ? __('app.no_access.body', ['company' => $tenant->name]) : __('app.no_access.body_none') }}</p>
    @if ($email)
        <p class="muted">{{ __('app.no_access.signed_as', ['email' => $email]) }}</p>
    @endif
    <a class="button" href="{{ config('kiban.core.url') }}/conta">{{ __('app.no_access.account') }}</a>
    @if ($tenant)
        <a class="button secondary" href="{{ $tenant->url('/auth/login') }}">{{ __('app.no_access.retry') }}</a>
    @endif
</x-layout>
