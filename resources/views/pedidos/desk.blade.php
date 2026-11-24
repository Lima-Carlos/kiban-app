@php
    use App\Modules\Pedidos\Enums\Fulfillment;
    use App\Modules\Pedidos\Enums\OrderSource;
    use App\Modules\Pedidos\Services\PedidosFormat;
    use App\Support\Country;
    use App\Support\Money;
    $blocked = $this->blocked();
    $client = $this->client();
    $last = $client ? $this->lastOrder() : null;
    $rhythm = $client ? $this->rhythm() : null;
    $subtotal = $this->subtotal();
    $fee = $this->feeCents();
    $input = 'width:100%;border-radius:8px;border:1px solid rgba(127,127,127,.35);padding:8px 10px;background:transparent;color:inherit';
    $label = 'display:block;font-size:13px;font-weight:600;margin:0 0 4px';
    $chip = 'border-radius:999px;padding:6px 12px;border:1px solid rgba(127,127,127,.35);cursor:pointer;font-size:14px;background:transparent;color:inherit';
    $chipOn = 'border-radius:999px;padding:6px 12px;border:1px solid #044299;cursor:pointer;font-size:14px;background:#044299;color:#fff';
@endphp
<x-filament-panels::page>
    @if ($blocked)
        <x-filament::section>
            <p style="margin:0" data-pedidos="blocked">{{ $blocked }}</p>
        </x-filament::section>
    @else
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:16px;align-items:start" data-pedidos="desk">
        <div style="display:flex;flex-direction:column;gap:16px;min-width:0">
            {{-- The customer, by the phone. --}}
            <x-filament::section :heading="__('pedidos.desk.customer')">
                <label style="{{ $label }}" for="desk-phone">{{ __('pedidos.fields.phone') }}</label>
                <input id="desk-phone" type="tel" inputmode="tel" autocomplete="off" style="{{ $input }};font-size:1.2rem" wire:model.live.debounce.400ms="phone" wire:keydown.enter="lookup" placeholder="{{ __('pedidos.desk.phone_placeholder') }}" autofocus>

                @if ($client)
                    <div style="margin-top:12px" data-pedidos="known">
                        <div style="font-size:1.15rem;font-weight:700">{{ $client->name }}</div>
                        <div style="font-size:13px;opacity:.75">{{ Country::formatPhone($client->phone) }} · {{ PedidosFormat::rhythmLine($rhythm) }}</div>
                        @if ($client->notes)
                            <div style="font-size:13px;margin-top:4px;color:#b45309">{{ $client->notes }}</div>
                        @endif

                        @if ($last)
                            <div style="margin-top:10px;padding:10px;border-radius:10px;background:rgba(127,127,127,.08)">
                                <div style="font-size:13px;opacity:.75">{{ __('pedidos.desk.last_order', ['when' => PedidosFormat::moment($last->created_at)]) }}</div>
                                <div style="font-weight:600">{{ $last->summary() }} · {{ Money::format($last->total_cents) }}</div>
                                <div style="margin-top:8px">
                                    <x-filament::button size="sm" icon="heroicon-o-arrow-path" wire:click="repeatUsual">{{ __('pedidos.desk.repeat_usual') }}</x-filament::button>
                                </div>
                            </div>
                        @endif

                        @if ($this->fulfillment === 'delivery')
                            <div style="margin-top:12px">
                                <label style="{{ $label }}">{{ __('pedidos.fields.address') }}</label>
                                @if ($client->sites->isNotEmpty() && ! $this->newAddress)
                                    <select style="{{ $input }}" wire:model.live="siteId">
                                        @foreach ($client->sites as $site)
                                            <option value="{{ $site->getKey() }}">{{ $site->address() }}{{ $site->reference ? ' ('.$site->reference.')' : '' }}</option>
                                        @endforeach
                                    </select>
                                    <x-filament::link tag="button" size="sm" wire:click="$set('newAddress', true)" style="margin-top:6px">{{ __('pedidos.desk.other_address') }}</x-filament::link>
                                @endif
                            </div>
                        @endif
                    </div>
                @elseif ($this->searched)
                    <div style="margin-top:12px" data-pedidos="new-customer">
                        <x-filament::badge color="info">{{ __('pedidos.desk.new_customer') }}</x-filament::badge>
                        <div style="margin-top:8px">
                            <label style="{{ $label }}" for="desk-name">{{ __('pedidos.fields.client_name') }}</label>
                            <input id="desk-name" type="text" style="{{ $input }}" wire:model="clientName" maxlength="255">
                        </div>
                    </div>
                @endif

                @if ($this->fulfillment === 'delivery' && ($this->newAddress || ($this->searched && ! $client)))
                    <div style="display:grid;grid-template-columns:2fr 1fr;gap:8px;margin-top:10px" data-pedidos="address-fields">
                        <div><label style="{{ $label }}">{{ __('field.fields.street') }}</label><input type="text" style="{{ $input }}" wire:model="address.street" maxlength="255"></div>
                        <div><label style="{{ $label }}">{{ __('field.fields.number') }}</label><input type="text" style="{{ $input }}" wire:model="address.number" maxlength="20"></div>
                        <div><label style="{{ $label }}">{{ __('field.fields.district') }}</label><input type="text" style="{{ $input }}" wire:model="address.district" maxlength="120"></div>
                        <div><label style="{{ $label }}">{{ __('field.fields.complement') }}</label><input type="text" style="{{ $input }}" wire:model="address.complement" maxlength="120"></div>
                        <div style="grid-column:1 / -1"><label style="{{ $label }}">{{ __('pedidos.fields.reference') }}</label><input type="text" style="{{ $input }}" wire:model="address.reference" maxlength="255" placeholder="{{ __('pedidos.helpers.reference') }}"></div>
                    </div>
                @endif

                @if ($client || $this->searched)
                    <label style="display:flex;gap:8px;align-items:center;margin-top:12px;font-size:14px">
                        <input type="checkbox" wire:model="consent">
                        <span>{{ __('pedidos.fields.whatsapp_consent') }}</span>
                    </label>
                @endif
            </x-filament::section>

            {{-- The menu: one tap adds the item. --}}
            <x-filament::section :heading="__('pedidos.menu.page_title')">
                <input type="search" style="{{ $input }};margin-bottom:10px" wire:model.live.debounce.300ms="menuSearch" placeholder="{{ __('pedidos.desk.search_menu') }}">
                @forelse ($this->menu() as $category => $items)
                    <div style="font-size:12px;font-weight:700;text-transform:uppercase;opacity:.6;margin:10px 0 6px">{{ $category }}</div>
                    <div style="display:flex;flex-wrap:wrap;gap:8px">
                        @foreach ($items as $item)
                            <button type="button" style="{{ $chip }}" wire:click="add({{ $item->getKey() }})" wire:key="menu-{{ $item->getKey() }}" data-pedidos="menu-item">
                                {{ $item->name }} · {{ Money::format($item->price_cents) }}
                            </button>
                        @endforeach
                    </div>
                @empty
                    <p style="margin:0;opacity:.7">{{ __('pedidos.desk.no_menu') }}</p>
                @endforelse
            </x-filament::section>
        </div>

        {{-- The order. --}}
        <x-filament::section :heading="__('pedidos.desk.order')">
            @forelse ($this->cart as $index => $line)
                <div style="padding:8px 0;border-bottom:1px solid rgba(127,127,127,.2)" wire:key="line-{{ $index }}">
                    <div style="display:flex;justify-content:space-between;gap:8px;align-items:center">
                        <div style="font-weight:600;min-width:0">{{ $line['name'] }}</div>
                        <div style="display:flex;gap:6px;align-items:center;flex:none">
                            <x-filament::icon-button icon="heroicon-o-minus" size="sm" wire:click="less({{ $index }})" :label="__('pedidos.desk.less')" />
                            <span style="min-width:22px;text-align:center;font-weight:700">{{ $line['quantity'] }}</span>
                            <x-filament::icon-button icon="heroicon-o-plus" size="sm" wire:click="more({{ $index }})" :label="__('pedidos.desk.more')" />
                            <span style="min-width:80px;text-align:right">{{ Money::format($line['unit_price_cents'] * $line['quantity']) }}</span>
                        </div>
                    </div>
                    <input type="text" style="{{ $input }};margin-top:6px;font-size:13px;padding:5px 8px" wire:model.blur="cart.{{ $index }}.notes" maxlength="255" placeholder="{{ __('pedidos.helpers.line_notes') }}">
                </div>
            @empty
                <p style="margin:0 0 8px;opacity:.7">{{ __('pedidos.desk.empty_cart') }}</p>
            @endforelse

            <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:14px">
                @foreach (Fulfillment::cases() as $option)
                    <button type="button" style="{{ $this->fulfillment === $option->value ? $chipOn : $chip }}" wire:click="$set('fulfillment', '{{ $option->value }}')">{{ $option->getLabel() }}</button>
                @endforeach
            </div>

            <label style="{{ $label }};margin-top:12px">{{ __('pedidos.fields.source') }}</label>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                @foreach (OrderSource::cases() as $option)
                    <button type="button" style="{{ $this->source === $option->value ? $chipOn : $chip }}" wire:click="$set('source', '{{ $option->value }}')">{{ $option->getLabel() }}</button>
                @endforeach
            </div>
            @if ($this->source === 'app')
                <p style="font-size:12px;opacity:.7;margin:6px 0 0">{{ __('pedidos.desk.app_help') }}</p>
            @endif

            <label style="{{ $label }};margin-top:12px">{{ __('pedidos.fields.payment_method') }}</label>
            <div style="display:flex;flex-wrap:wrap;gap:8px">
                @foreach ($this->payments() as $value => $text)
                    <button type="button" style="{{ $this->payment === $value ? $chipOn : $chip }}" wire:click="$set('payment', '{{ $value }}')">{{ $text }}</button>
                @endforeach
            </div>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:12px">
                @if ($this->payment === 'cash')
                    <div><label style="{{ $label }}">{{ __('pedidos.fields.change_for') }}</label><input type="text" inputmode="decimal" style="{{ $input }}" wire:model.blur="changeFor"></div>
                @endif
                @if ($this->fulfillment === 'delivery')
                    <div><label style="{{ $label }}">{{ __('pedidos.fields.delivery_fee') }}</label><input type="text" inputmode="decimal" style="{{ $input }}" wire:model.live.debounce.500ms="fee"></div>
                @endif
            </div>

            <label style="{{ $label }};margin-top:12px">{{ __('pedidos.fields.notes') }}</label>
            <textarea rows="2" style="{{ $input }}" wire:model="notes" maxlength="1000" placeholder="{{ __('pedidos.helpers.notes') }}"></textarea>

            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:14px;gap:12px;flex-wrap:wrap">
                <div>
                    <div style="font-size:13px;opacity:.75">{{ __('pedidos.desk.subtotal', ['value' => Money::format($subtotal)]) }}@if ($fee > 0) · {{ __('pedidos.orders.with_fee', ['value' => Money::format($fee)]) }}@endif</div>
                    <div style="font-size:1.4rem;font-weight:800" data-pedidos="total">{{ Money::format($subtotal + $fee) }}</div>
                </div>
                <div style="display:flex;gap:8px">
                    <x-filament::button color="gray" wire:click="resetOrder">{{ __('pedidos.desk.clear') }}</x-filament::button>
                    <x-filament::button icon="heroicon-o-check" wire:click="save" :disabled="$this->cart === []">{{ __('pedidos.desk.save') }}</x-filament::button>
                </div>
            </div>
        </x-filament::section>
    </div>
    @endif
</x-filament-panels::page>
