<?php

namespace App\Modules\Pedidos\Models;

use App\Modules\Pedidos\Enums\PaymentMethod;
use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * What the Pedidos keeps about a customer: the usual way of paying and the consent to receive offers by WhatsApp
 * (LGPD: without it, the "come back" message is never offered). The moment of the consent is kept.
 */
class ClientDetail extends Model
{
    use BelongsToTenant;

    protected $table = 'pd_client_details';

    protected $fillable = ['customer_id', 'payment_preference', 'whatsapp_consent', 'last_promo_at'];

    protected $attributes = ['whatsapp_consent' => false];

    protected function casts(): array
    {
        return [
            'payment_preference' => PaymentMethod::class,
            'whatsapp_consent' => 'boolean',
            'whatsapp_consent_at' => 'datetime',
            'last_promo_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (ClientDetail $detail): void {
            if ($detail->isDirty('whatsapp_consent')) {
                $detail->whatsapp_consent_at = $detail->whatsapp_consent ? now() : null;
            }
        });
    }
}
