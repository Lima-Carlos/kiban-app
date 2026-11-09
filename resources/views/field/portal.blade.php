@php
    use App\Modules\Field\Services\FieldFormat;
    $tz = \Filament\Support\Facades\FilamentTimezone::get();
    $row = 'display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px solid #f0f0f0;text-align:left;font-size:14px';
    $heading = 'font-size:15px;margin:22px 0 4px;text-align:left';
@endphp
<x-layout :title="__('field.portal.title')" :brand="$company" :logo="$logo" :color="$color">
    <h1>{{ __('field.portal.hello', ['name' => $customer->displayName()]) }}</h1>
    <p>{{ __('field.portal.intro', ['company' => $company]) }}</p>

    @if ($next->isNotEmpty())
        <h2 style="{{ $heading }}">{{ __('field.portal.next') }}</h2>
        @foreach ($next as $order)
            <div style="{{ $row }}">
                <div>
                    <strong>{{ $order->serviceType?->name ?? $order->label() }}</strong>
                    @if ($order->site)<div style="color:#6b7280">{{ $order->site->address() }}</div>@endif
                </div>
                <div style="text-align:right;white-space:nowrap">
                    {{ $order->scheduled_at ? $order->scheduled_at->setTimezone($tz)->translatedFormat(__('app.date_format')).' '.$order->scheduled_at->setTimezone($tz)->format('H:i') : __('field.portal.to_schedule') }}
                    <div style="color:#6b7280">{{ $order->status->getLabel() }}</div>
                </div>
            </div>
        @endforeach
    @endif

    @if ($quotes->isNotEmpty())
        <h2 style="{{ $heading }}">{{ __('field.portal.quotes') }}</h2>
        @foreach ($quotes as $quote)
            <div style="{{ $row }}">
                <div><strong>{{ $quote->label() }}</strong><div style="color:#6b7280">{{ $quote->status->getLabel() }}</div></div>
                <div style="text-align:right;white-space:nowrap">
                    {{ FieldFormat::money($quote->total_cents) }}
                    <div><a href="{{ route('field.quote', ['token' => $quote->token]) }}" style="color:{{ $color }}">{{ __('field.portal.open') }}</a></div>
                </div>
            </div>
        @endforeach
    @endif

    @if ($done->isNotEmpty())
        <h2 style="{{ $heading }}">{{ __('field.portal.done') }}</h2>
        @foreach ($done as $order)
            <div style="{{ $row }}">
                <div>
                    <strong>{{ $order->serviceType?->name ?? $order->label() }}</strong>
                    <div style="color:#6b7280">{{ $order->finished_at?->setTimezone($tz)->translatedFormat(__('app.date_format')) }}</div>
                </div>
                <div style="text-align:right;white-space:nowrap"><a href="{{ $reports->url($order) }}" style="color:{{ $color }}">{{ __('field.portal.report') }}</a></div>
            </div>
        @endforeach
    @endif

    @if (count($equipment) > 0)
        <h2 style="{{ $heading }}">{{ __('field.portal.equipment') }}</h2>
        @foreach ($equipment as $item)
            <div style="{{ $row }}">
                <div><strong>{{ $item->tag }}</strong>@if ($item->room) · {{ $item->room }}@endif<div style="color:#6b7280">{{ $item->plan?->site?->address() }}</div></div>
                <div style="text-align:right;white-space:nowrap"><a href="{{ $item->publicUrl() }}" style="color:{{ $color }}">{{ __('field.portal.open') }}</a></div>
            </div>
        @endforeach
    @endif

    @if ($next->isEmpty() && $quotes->isEmpty() && $done->isEmpty() && count($equipment) === 0)
        <p>{{ __('field.portal.empty') }}</p>
    @endif
</x-layout>
