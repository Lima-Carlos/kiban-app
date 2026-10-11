<x-layout :title="__('app.blocked.title')">
    <h1>{{ __('app.blocked.title') }}</h1>
    <p><strong>{{ $tenant->name }}</strong></p>
    <p>{{ __('app.blocked.'.$reason) }}</p>
    <a class="button" href="{{ config('kiban.core.url') }}/conta">{{ __('app.blocked.action') }}</a>
</x-layout>
