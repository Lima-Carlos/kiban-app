<?php

namespace App\Modules\Pedidos\Services;

use App\Models\CompanyProfile;
use App\Modules\Pedidos\Models\Client;
use App\People\PeopleService;
use Illuminate\Support\Str;

/**
 * The "come back" WhatsApp message (free: a wa.me link the person taps, no paid service). Only for customers who
 * agreed to receive offers (LGPD). In the text, {nome} / {name} / {nombre} becomes the customer's first name and
 * {empresa} / {company} the company's name.
 */
class PedidosMessages
{
    /** The wa.me link with the message, or null when the customer did not agree or has no phone. */
    public function promo(Client $client, string $message): ?string
    {
        if (! $client->hasWhatsAppConsent() || blank($client->phone)) {
            return null;
        }

        return PeopleService::whatsappUrl($client->phone, $this->fill($client, $message));
    }

    public function fill(Client $client, string $message): string
    {
        $first = Str::before(trim((string) $client->name), ' ');
        $company = CompanyProfile::current()->name();

        return strtr($message, ['{nome}' => $first, '{name}' => $first, '{nombre}' => $first, '{empresa}' => $company, '{company}' => $company]);
    }
}
