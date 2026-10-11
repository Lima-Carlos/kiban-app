<x-layout :title="__('app.no_access.title')">
    <h1>{{ __('app.no_access.title') }}</h1>
    <p>{{ __('app.no_access.body', ['company' => $tenant->name]) }}</p>
    @if ($email)
        <p class="muted">{{ __('app.no_access.signed_as', ['email' => $email]) }}</p>
    @endif
    <a class="button" href="{{ config('kiban.core.url') }}/conta">{{ __('app.no_access.account') }}</a>
    <a class="button secondary" href="{{ $tenant->url('/auth/login') }}">{{ __('app.no_access.retry') }}</a>
</x-layout>
