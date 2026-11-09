<?php

namespace Tests\Feature;

use App\Messaging\WhatsAppCloud;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * Automatic WhatsApp (Meta Cloud API), ready but off: nothing goes out while off; on, tomorrow's appointments get
 * the reminder template at 17 h and are marked as reminded (never twice). The Meta API is simulated.
 */
class WhatsAppAutoTest extends AgendaTest
{
    public function test_off_by_default(): void
    {
        $this->book('2026-11-04 09:00');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 18:00', 'America/Sao_Paulo'));

        $this->assertFalse(app(WhatsAppCloud::class)->enabled());
        $this->artisan('kiban:agenda-reminders')->assertSuccessful();
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'graph.facebook.com'));
    }

    public function test_on_the_reminders_go_by_themselves(): void
    {
        config(['kiban.whatsapp_auto' => true, 'services.whatsapp.token' => 'meta-token', 'services.whatsapp.phone_number_id' => '123456']);
        $appointment = $this->book('2026-11-04 09:00');
        Http::swap(new Factory);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);

        // Before 17 h: not yet.
        $this->travelTo(CarbonImmutable::parse('2026-11-03 15:00', 'America/Sao_Paulo'));
        $this->artisan('kiban:agenda-reminders')->assertSuccessful();
        Http::assertNothingSent();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 17:30', 'America/Sao_Paulo'));
        $this->artisan('kiban:agenda-reminders')->assertSuccessful();
        $this->artisan('kiban:agenda-reminders')->assertSuccessful();

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/v21.0/123456/messages')
            && $request['to'] === '5511988887777' && $request['template']['name'] === 'kiban_lembrete_agenda'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'Maria'
            && $request->header('Authorization')[0] === 'Bearer meta-token');
        $this->assertNotNull($appointment->fresh()->reminded_at);
    }
}
