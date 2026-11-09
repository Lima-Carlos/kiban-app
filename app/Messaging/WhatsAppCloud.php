<?php

namespace App\Messaging;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Automatic WhatsApp messages through the official WhatsApp Business (Cloud) API of Meta. Ready but OFF: today the
 * messages go by the free wa.me button the person taps. To turn on (paid per message by Meta, decision of the
 * owner): WHATSAPP_TOKEN, WHATSAPP_PHONE_NUMBER_ID and KIBAN_WHATSAPP_AUTO=true in the .env, and the message
 * templates approved in the Meta panel with the names in config('services.whatsapp.templates').
 * Messages outside a conversation must be approved templates (Meta rule): the text goes as the template's
 * parameters, in the template's language.
 */
class WhatsAppCloud
{
    public function enabled(): bool
    {
        return (bool) config('kiban.whatsapp_auto') && filled(config('services.whatsapp.token')) && filled(config('services.whatsapp.phone_number_id'));
    }

    /**
     * Sends an approved template; true when Meta accepted it.
     *
     * @param  array<int, string>  $parameters  the {{1}}, {{2}}... of the template body
     */
    public function sendTemplate(?string $phone, string $template, string $locale, array $parameters): bool
    {
        $to = preg_replace('/\D/', '', (string) $phone);

        if (! $this->enabled() || $to === '' || blank($name = config('services.whatsapp.templates.'.$template))) {
            return false;
        }

        try {
            $response = Http::withToken((string) config('services.whatsapp.token'))
                ->timeout(15)
                ->post('https://graph.facebook.com/'.config('services.whatsapp.version').'/'.config('services.whatsapp.phone_number_id').'/messages', [
                    'messaging_product' => 'whatsapp',
                    'to' => $to,
                    'type' => 'template',
                    'template' => [
                        'name' => $name,
                        'language' => ['code' => $locale],
                        'components' => [[
                            'type' => 'body',
                            'parameters' => array_map(fn (string $text): array => ['type' => 'text', 'text' => mb_substr($text, 0, 1000)], array_values($parameters)),
                        ]],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('WhatsApp message not accepted.', ['template' => $template, 'status' => $response->status(), 'error' => $response->json('error.message')]);
            }

            return $response->successful();
        } catch (Throwable $exception) {
            Log::warning('WhatsApp message not sent.', ['template' => $template, 'error' => $exception->getMessage()]);

            return false;
        }
    }
}
