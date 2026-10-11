<x-layout :title="__('app.failed.title')">
    <h1>{{ __('app.failed.title') }}</h1>
    <p>{{ __('app.failed.'.$reason) }}</p>
    @if ($tenant)
        <a class="button" href="{{ $tenant->url('/auth/login') }}">{{ __('app.failed.again') }}</a>
    @else
        <a class="button" href="{{ route('central.home') }}">{{ __('app.failed.again') }}</a>
    @endif
</x-layout>
