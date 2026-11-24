<?php

namespace App\Modules\Pedidos\Models;

use App\Modules\Field\Models\Customer;
use App\Modules\Pedidos\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A customer of the Pedidos (who orders the pizza). It is the same record as the customers of the Campo and the
 * Agenda (table fd_customers, addresses in fd_sites): one company, one list of customers. This class only adds what
 * the Pedidos needs (orders, payment preference, WhatsApp consent) and lets the Pedidos have its own access rules.
 */
class Client extends Customer
{
    /** Relations keep the column of the shared table ("customer_id"), not "client_id". */
    public function getForeignKey(): string
    {
        return 'customer_id';
    }

    /**
     * @return HasOne<ClientDetail, $this>
     */
    public function details(): HasOne
    {
        return $this->hasOne(ClientDetail::class, 'customer_id');
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function foodOrders(): HasMany
    {
        return $this->hasMany(Order::class, 'customer_id')->orderByDesc('created_at');
    }

    /** The last order that was not cancelled. */
    public function lastOrder(): ?Order
    {
        return Order::query()->with('lines')->where('customer_id', $this->getKey())
            ->where('status', '!=', OrderStatus::Cancelled->value)->latest('created_at')->latest('id')->first();
    }

    public function hasWhatsAppConsent(): bool
    {
        return (bool) $this->details?->whatsapp_consent;
    }
}
