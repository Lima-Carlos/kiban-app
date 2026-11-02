{{--
    Guide bar of the company first steps (StepGuide): shown above a step's screen when it was opened from the start
    list ("?passo=1"). "Back to the steps" and "Next step" (the next step still to do).
--}}
@php
    $tenant = app(\App\Tenancy\TenantContext::class)->current();
    $guide = $tenant && request()->query(\App\Onboarding\StepGuide::PARAM)
        ? app(\App\Onboarding\StepGuide::class)->for($tenant, auth()->user(), url()->current())
        : null;
@endphp

@if ($guide)
    <div data-step-guide style="margin:8px 0 12px;padding:8px 12px;border-radius:10px;display:flex;flex-wrap:wrap;align-items:center;gap:10px;font-size:13px;background:rgba(4,66,153,.08)">
        <span style="flex:1 1 200px;min-width:0;font-weight:600">{{ __('app.first_steps.guide', ['number' => $guide['number'], 'total' => $guide['total'], 'label' => $guide['label']]) }}</span>
        <x-filament::button tag="a" :href="$guide['home']" size="sm" color="gray" icon="heroicon-m-arrow-left">{{ __('app.first_steps.back') }}</x-filament::button>
        @if ($guide['next'])
            <x-filament::button tag="a" :href="$guide['next']" size="sm" icon="heroicon-m-arrow-right" icon-position="after" data-step-next>{{ __('app.first_steps.next') }}</x-filament::button>
        @endif
    </div>
@endif
