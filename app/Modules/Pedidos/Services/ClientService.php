<?php

namespace App\Modules\Pedidos\Services;

use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Services\CustomerRules;
use App\Modules\Pedidos\Enums\OrderStatus;
use App\Modules\Pedidos\Models\Client;
use App\Modules\Pedidos\Models\ClientDetail;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Models\OrderLine;
use App\Modules\Pedidos\Models\PedidosSetting;
use App\Support\Country;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * The customers of the Pedidos (the company's shared customer register): found by the phone, created in a few
 * seconds while on the phone, and their "usual order". One phone, one customer in the company: the same number never
 * becomes two customers (the rule of the Pedidos; the Campo may have a company with the same phone as its owner).
 */
class ClientService
{
    /** The customer with this phone (main or second phone), or null. */
    public function findByPhone(?string $typed): ?Client
    {
        $phone = Country::phone($typed);

        if ($phone === null || strlen($phone) < 8) {
            return null;
        }

        return Client::query()->with(['mainSite', 'details'])
            ->where(fn ($query) => $query->where('phone', $phone)->orWhere('phone_2', $phone))
            ->orderByDesc('is_active')->orderBy('id')->first();
    }

    /** Another customer of the company already has this phone. */
    public function phoneTaken(?string $typed, ?Customer $ignore = null): bool
    {
        $phone = Country::phone($typed);

        return $phone !== null && Customer::query()
            ->where(fn ($query) => $query->where('phone', $phone)->orWhere('phone_2', $phone))
            ->when($ignore, fn ($query) => $query->whereKeyNot($ignore->getKey()))
            ->exists();
    }

    /** Validation rule for the phone on the forms. */
    public function phoneRule(?Customer $ignore = null): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($ignore): void {
            if (filled($value) && $this->phoneTaken((string) $value, $ignore)) {
                $fail(__('pedidos.errors.phone_taken'));
            }
        };
    }

    /**
     * A new customer while on the phone: name, phone and (for deliveries) the address. The address is searched on
     * the map in the background (like the Campo's customers).
     *
     * @param  array<string, mixed>  $data  name, phone, street, number, complement, district, city, state, postal_code, reference, payment_preference, whatsapp_consent
     */
    public function create(array $data): Client
    {
        if (blank($data['name'] ?? null)) {
            throw new PedidosException(__('pedidos.errors.name_required'));
        }

        if (blank(Country::phone($data['phone'] ?? null))) {
            throw new PedidosException(__('pedidos.errors.phone_required'));
        }

        if ($this->phoneTaken($data['phone'])) {
            throw new PedidosException(__('pedidos.errors.phone_taken'));
        }

        return DB::transaction(function () use ($data): Client {
            $client = new Client;
            $client->fill(app(CustomerRules::class)->prepare(['name' => $data['name'], 'phone' => $data['phone']]));
            $client->kind = Customer::PERSON;
            $client->save();

            $this->addSite($client, $data);
            $this->saveDetails($client, $data);

            return $client->refresh();
        });
    }

    /**
     * A new address for the customer (the first one becomes the main one). Null when nothing was typed.
     *
     * @param  array<string, mixed>  $data
     */
    public function addSite(Client $client, array $data): ?Site
    {
        $fields = ['street', 'number', 'complement', 'district', 'city', 'state', 'postal_code', 'reference'];
        $address = collect($data)->only($fields)->map(fn ($value) => filled($value) ? trim((string) $value) : null)->all();

        if (blank($address['street'] ?? null)) {
            return null;
        }

        $settings = PedidosSetting::current();
        $address['city'] ??= $settings->city;
        $address['state'] ??= $settings->state;

        $site = new Site($address + ['is_main' => ! $client->sites()->exists(), 'country' => Country::current()]);
        $site->customer_id = $client->getKey();
        $site->save();
        $client->ensureMainSite();

        return $site;
    }

    /**
     * @param  array<string, mixed>  $data  payment_preference, whatsapp_consent
     */
    public function saveDetails(Client $client, array $data): ClientDetail
    {
        $detail = ClientDetail::query()->firstOrNew(['customer_id' => $client->getKey()]);

        if (array_key_exists('payment_preference', $data)) {
            $detail->payment_preference = filled($data['payment_preference']) ? $data['payment_preference'] : null;
        }

        if (array_key_exists('whatsapp_consent', $data)) {
            $detail->whatsapp_consent = (bool) $data['whatsapp_consent'];
        }

        $detail->save();

        return $detail;
    }

    /**
     * "The usual": the order the customer repeats the most among the last ten (same items and quantities); with
     * no repetition, the last one. Each line: menu item, name, price of the menu now (or of the order), quantity, notes.
     *
     * @return array<int, array{menu_item_id: ?int, name: string, unit_price_cents: int, quantity: int, notes: ?string}>
     */
    public function usual(Client $client): array
    {
        $orders = Order::query()->with('lines')->where('customer_id', $client->getKey())
            ->where('status', '!=', OrderStatus::Cancelled->value)->latest('created_at')->latest('id')->limit(10)->get();

        if ($orders->isEmpty()) {
            return [];
        }

        $signature = fn (Order $order): string => $order->lines->map(fn (OrderLine $line): string => ($line->menu_item_id ?? $line->name).'x'.$line->quantity)->sort()->implode('|');
        $counts = $orders->countBy($signature);
        $top = $counts->sortDesc()->keys()->first();
        $chosen = $counts->get($top) > 1 ? $orders->first(fn (Order $order): bool => $signature($order) === $top) : $orders->first();

        return $chosen->lines->map(fn (OrderLine $line): array => [
            'menu_item_id' => $line->menu_item_id,
            'name' => $line->name,
            'unit_price_cents' => $line->unit_price_cents,
            'quantity' => $line->quantity,
            'notes' => $line->notes,
        ])->values()->all();
    }
}
