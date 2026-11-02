{{--
    "Your invoice is due in N days / today / overdue · See invoices" (PaymentBanner): owner and administrators only,
    from 3 days before the due date. One line, also on phones; stronger colour on the due date and after it.
--}}
@php
    $tenant = app(\App\Tenancy\TenantContext::class)->current();
    $payment = $tenant ? app(\App\Licensing\PaymentBanner::class)->for($tenant, auth()->user()) : null;
    $urgent = $payment && ($payment['overdue'] || $payment['days'] === 0);
@endphp

@if ($payment)
    <div data-payment-banner
         style="margin:8px 0 12px;padding:7px 12px;border-radius:10px;display:flex;align-items:center;gap:10px;font-size:13px;line-height:1.3;white-space:nowrap;{{ $urgent ? 'background:rgba(220,38,38,.12);color:#b91c1c;font-weight:600' : 'background:rgba(234,88,12,.12);color:#c2410c' }}">
        <x-filament::icon icon="heroicon-m-banknotes" style="width:16px;height:16px;flex:none" />
        <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis">
            @if ($payment['overdue'])
                {{ __('app.payment.overdue') }}
            @elseif ($payment['days'] === 0)
                {{ __('app.payment.today') }}
            @else
                {{ trans_choice('app.payment.days', $payment['days'], ['count' => $payment['days']]) }}
            @endif
        </span>
        <a href="{{ $payment['url'] }}" target="_blank" rel="noopener" style="flex:none;font-weight:600;text-decoration:underline;color:inherit">{{ __('app.payment.see') }}</a>
    </div>
@endif
