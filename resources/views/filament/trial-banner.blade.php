{{--
    "Free trial: N days left · See plans" (TrialBanner): only while every system is in trial and only for the
    owner/administrators. One line, also on phones; stronger colour in the last days.
--}}
@php
    $tenant = app(\App\Tenancy\TenantContext::class)->current();
    $trial = $tenant ? app(\App\Licensing\TrialBanner::class)->for($tenant, auth()->user()) : null;
@endphp

@if ($trial)
    <div data-trial-banner
         style="margin:8px 0 12px;padding:7px 12px;border-radius:10px;display:flex;align-items:center;gap:10px;font-size:13px;line-height:1.3;white-space:nowrap;{{ $trial['urgent'] ? 'background:rgba(234,88,12,.14);color:#c2410c;font-weight:600' : 'background:rgba(4,66,153,.08)' }}">
        <x-filament::icon :icon="$trial['urgent'] ? 'heroicon-m-clock' : 'heroicon-m-gift'" style="width:16px;height:16px;flex:none" />
        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis">
            {{ $trial['days'] === 0 ? __('app.trial.today') : trans_choice('app.trial.days', $trial['days'], ['count' => $trial['days']]) }}
        </span>
        <a href="{{ $trial['url'] }}" target="_blank" rel="noopener"
           style="flex:none;font-weight:600;text-decoration:underline;color:{{ $trial['urgent'] ? 'inherit' : 'var(--primary-600)' }}">{{ __('app.trial.plans') }}</a>
    </div>
    <style>.dark [data-trial-banner] a { color: {{ $trial['urgent'] ? 'inherit' : 'var(--primary-400)' }} !important; } .dark [data-trial-banner] { color: {{ $trial['urgent'] ? '#fb923c' : 'inherit' }} !important; }</style>
@endif
