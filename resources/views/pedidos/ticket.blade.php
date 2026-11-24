{{--
    The kitchen ticket of an order: narrow (fits 80 mm and 58 mm receipt printers), big number, items with their
    notes, delivery or pickup, address, payment. A "Print" button that is not printed; the page prints by itself.
--}}
@php
    use App\Modules\Pedidos\Services\PedidosFormat;
    use App\Support\Country;
    use App\Support\Money;
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('pedidos.orders.title', ['number' => $order->number]) }}</title>
    <style>
        @page { margin: 4mm; }
        * { box-sizing: border-box; }
        body { font-family: ui-monospace, Menlo, Consolas, monospace; margin: 0; color: #000; background: #f3f4f6; font-size: 13px; }
        .bar { padding: 12px 16px; display: flex; gap: 12px; align-items: center; background: #fff; border-bottom: 1px solid #e5e7eb; font-family: system-ui, sans-serif; }
        .bar button { background: #044299; color: #fff; border: 0; border-radius: 8px; padding: 10px 18px; font-size: 15px; font-weight: 600; cursor: pointer; }
        .ticket { width: 72mm; margin: 16px auto; background: #fff; padding: 4mm; }
        .center { text-align: center; }
        .number { font-size: 28px; font-weight: 800; }
        .big { font-size: 15px; font-weight: 700; }
        hr { border: 0; border-top: 1px dashed #000; margin: 6px 0; }
        .line { display: flex; justify-content: space-between; gap: 6px; }
        .note { padding-left: 10px; font-weight: 700; }
        @media print {
            body { background: #fff; }
            .bar { display: none; }
            .ticket { margin: 0; width: auto; padding: 0; }
        }
    </style>
</head>
<body>
    <div class="bar">
        <button type="button" onclick="window.print()">{{ __('pedidos.ticket.print') }}</button>
        <span>{{ __('pedidos.ticket.help') }}</span>
    </div>
    <div class="ticket" data-pedidos="ticket">
        <div class="center">{{ $company }}</div>
        <div class="center number">#{{ $order->number }}</div>
        <div class="center">{{ PedidosFormat::moment($order->created_at) }} · {{ $order->source->getLabel() }}</div>
        <div class="center big">{{ mb_strtoupper($order->fulfillment->getLabel()) }}</div>
        <hr>
        <div class="big">{{ $order->customerName() }}</div>
        @if ($order->client?->phone)
            <div>{{ Country::formatPhone($order->client->phone) }}</div>
        @endif
        @if ($order->isDelivery() && $order->address)
            <div>{{ $order->address }}</div>
            @if ($order->site?->reference)
                <div>{{ $order->site->reference }}</div>
            @endif
        @endif
        <hr>
        @foreach ($order->lines as $line)
            <div class="line"><span class="big">{{ $line->quantity }}× {{ $line->name }}</span><span>{{ Money::format($line->total_cents) }}</span></div>
            @if ($line->notes)
                <div class="note">» {{ $line->notes }}</div>
            @endif
        @endforeach
        @if ($order->notes)
            <hr>
            <div class="note">{{ $order->notes }}</div>
        @endif
        <hr>
        @if ($order->delivery_fee_cents > 0)
            <div class="line"><span>{{ __('pedidos.fields.delivery_fee') }}</span><span>{{ Money::format($order->delivery_fee_cents) }}</span></div>
        @endif
        <div class="line big"><span>{{ __('pedidos.fields.total') }}</span><span>{{ Money::format($order->total_cents) }}</span></div>
        @if ($order->payment_method)
            <div>{{ __('pedidos.fields.payment_method') }}: {{ $order->payment_method->getLabel() }}</div>
        @endif
        @if ($order->change_for_cents)
            <div>{{ __('pedidos.orders.change_for', ['value' => Money::format($order->change_for_cents)]) }}</div>
        @endif
    </div>
</body>
</html>
