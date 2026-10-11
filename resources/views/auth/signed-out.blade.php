<x-layout :title="__('app.signed_out.title')">
    <h1>{{ $denied ? __('app.signed_out.denied_title') : __('app.signed_out.title') }}</h1>
    <p>{{ $denied ? __('app.signed_out.denied') : __('app.signed_out.body', ['company' => $tenant->name]) }}</p>
    <a class="button" href="{{ $tenant->url('/auth/login') }}">{{ __('app.signed_out.again') }}</a>
</x-layout>
