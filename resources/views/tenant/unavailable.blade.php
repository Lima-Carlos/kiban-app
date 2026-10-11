<x-layout :title="__('app.blocked.title')">
    <h1>{{ __('app.blocked.title') }}</h1>
    <p>{{ __('app.failed.core') }}</p>
    <a class="button" href="{{ url()->full() }}">{{ __('app.failed.again') }}</a>
</x-layout>
