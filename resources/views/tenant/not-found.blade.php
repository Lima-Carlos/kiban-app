<x-layout :title="__('app.not_found.title')">
    <h1>{{ __('app.not_found.title') }}</h1>
    <p>{{ __('app.not_found.body') }}</p>
    <a class="button" href="{{ $central }}">{{ __('app.not_found.action') }}</a>
</x-layout>
