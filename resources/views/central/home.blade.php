<x-layout :title="__('app.central.title')">
    @if ($companies !== null)
        <h1>{{ __('app.central.choose') }}</h1>
        <p>{{ __('app.central.choose_intro') }}</p>
        @foreach ($companies as $company)
            <a class="button" href="{{ $company->url() }}">{{ $company->name }}</a>
        @endforeach
    @else
        <h1>{{ __('app.central.title') }}</h1>
        <p>{{ __('app.central.intro') }}</p>
        <a class="button" href="{{ route('central.login') }}">{{ __('app.central.login') }}</a>

        <form method="get" action="{{ route('central.go') }}" style="margin-top:24px">
            <label for="company">{{ __('app.central.or_company') }}</label>
            <div class="address">
                <span>{{ preg_replace('#^https?://#', '', rtrim(config('app.url'), '/')) }}/</span>
                <input id="company" name="company" value="{{ old('company', request('company')) }}" autocomplete="off" autocapitalize="none" required placeholder="{{ __('app.central.placeholder') }}">
            </div>
            @if ($notFound)
                <p style="color:#b42318;text-align:left;margin-top:8px">{{ __('app.central.not_found') }}</p>
            @endif
            <button type="submit" class="secondary">{{ __('app.central.go') }}</button>
        </form>

        <p class="muted" style="margin-top:18px">{!! __('app.central.account', ['url' => e(config('kiban.core.url').'/conta')]) !!}</p>
    @endif
</x-layout>