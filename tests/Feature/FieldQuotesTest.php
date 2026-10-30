<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Enums\QuoteStatus;
use App\Modules\Field\Filament\Quotes\Pages\CreateQuote;
use App\Modules\Field\Filament\Quotes\Pages\EditQuote;
use App\Modules\Field\Filament\Quotes\Pages\ViewQuote;
use App\Modules\Field\Filament\Quotes\QuoteResource;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\Material;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\Field\Services\QuoteService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Campo, part 2b: quotes with services and materials of the catalog, a discount and the validity; sent by
 * a link (WhatsApp); the customer approves (with the name) or declines on the phone; the approved quote becomes
 * a work order by itself and the team is told in the bell. Answered quotes never change.
 */
class FieldQuotesTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected Customer $customer;

    protected ServiceType $install;

    protected Material $router;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $this->company = $this->tenant('provedor', ['campo' => []]);
        $this->owner = $this->signIn($this->company);

        FieldSetting::current()->update(['business' => 'isp', 'configured_at' => now()]);
        app(FieldTemplates::class)->apply('isp');
        $this->install = ServiceType::query()->where('name', 'Instalação')->sole();
        $this->install->update(['price_cents' => 15000]);
        $this->router = Material::query()->where('name', 'Roteador Wi-Fi')->sole();
        $this->router->update(['price_cents' => 18990]);

        $this->customer = Customer::create(['name' => 'Maria da Silva', 'phone' => '5531999990000']);
        $this->customer->sites()->create(['street' => 'Rua da Bahia', 'number' => '10', 'city' => 'Belo Horizonte', 'is_main' => true]);
    }

    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    protected function quote(array $items = [], int $discount = 0): Quote
    {
        return app(QuoteService::class)->create(['customer_id' => $this->customer->getKey(), 'discount_cents' => $discount], $items ?: [
            ['service_type_id' => $this->install->getKey(), 'description' => 'Instalação', 'quantity' => 1, 'unit' => 'un', 'unit_price_cents' => 15000],
            ['material_id' => $this->router->getKey(), 'description' => 'Roteador Wi-Fi', 'quantity' => 2, 'unit' => 'un', 'unit_price_cents' => 18990],
        ], $this->owner);
    }

    public function test_write_a_quote_from_the_catalog(): void
    {
        Livewire::test(CreateQuote::class)
            ->fillForm([
                'customer_id' => $this->customer->getKey(),
                'items' => [
                    ['service_type_id' => $this->install->getKey(), 'description' => 'Instalação', 'quantity' => '1', 'unit' => 'un', 'unit_price_cents' => '150.00'],
                    ['material_id' => $this->router->getKey(), 'description' => 'Roteador Wi-Fi', 'quantity' => '2', 'unit' => 'un', 'unit_price_cents' => '189.90'],
                    ['description' => 'Cabo drop', 'quantity' => '35.5', 'unit' => 'm', 'unit_price_cents' => '1.20'],
                ],
                'discount_cents' => '20.00',
                'notes' => 'Pagamento na instalação.',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $quote = Quote::query()->sole();
        $this->assertSame([1, QuoteStatus::Draft, '2026-11-18'], [$quote->number, $quote->status, $quote->valid_until->toDateString()]);
        // 150,00 + 2 × 189,90 + 35,5 × 1,20 = 572,40; minus 20,00 = 552,40.
        $this->assertSame([57240, 2000, 55240], [$quote->subtotalCents(), $quote->discount_cents, $quote->total_cents]);
        $this->assertSame([15000, 37980, 4260], $quote->items->pluck('total_cents')->all());
        $this->assertSame('Orçamento 000001', $quote->label());

        // Choosing from the catalog fills the line.
        $this->assertSame('Roteador Wi-Fi · R$ 189,90', str_replace("\u{A0}", ' ', collect(QuoteResource::catalogOptions())->collapse()->get('m:'.$this->router->getKey())));

        // A discount bigger than the subtotal is capped.
        $big = $this->quote(discount: 999999);
        $this->assertSame([0, 52980], [$big->total_cents, $big->discount_cents]);

        // A draft cannot be sent empty, and its link does not open yet.
        $this->get(app(QuoteService::class)->link($quote))->assertNotFound();
        $this->assertSame(__('field.errors.quote_empty'), rescue(fn () => app(QuoteService::class)->send($this->quote([['description' => '']])), fn ($e) => $e->getMessage(), false));
    }

    public function test_send_by_whatsapp_and_the_customer_approves(): void
    {
        $quote = $this->quote();

        Livewire::test(ViewQuote::class, ['record' => $quote->getKey()])
            ->callAction('send')
            ->assertNotified(__('field.quotes.sent'));
        $quote->refresh();
        $this->assertSame(QuoteStatus::Sent, $quote->status);

        $whatsapp = app(QuoteService::class)->whatsappUrl($quote);
        $this->assertStringStartsWith('https://wa.me/5531999990000?text=', $whatsapp);
        $this->assertStringContainsString(rawurlencode(app(QuoteService::class)->link($quote)), $whatsapp);

        // The customer's page: lines and total; opening it is noted.
        $link = app(QuoteService::class)->link($quote);
        $this->get($link)->assertOk()->assertSee('Roteador Wi-Fi')->assertSee('529,80')->assertSee(__('field.quote_page.approve'));
        $this->assertNotNull($quote->fresh()->viewed_at);

        // Approving needs the name and the agreement.
        $this->post($link, ['answer' => 'approve', 'name' => ''])->assertSessionHasErrors(['name', 'agree']);
        $this->post($link, ['answer' => 'approve', 'name' => 'Maria da Silva', 'agree' => '1'], ['User-Agent' => 'Celular da Maria'])->assertRedirect($link);

        $quote->refresh();
        $this->assertSame([QuoteStatus::Approved, 'Maria da Silva', 'Celular da Maria'], [$quote->status, $quote->answered_by, $quote->answered_device]);
        $this->get($link)->assertSee(__('field.quote_page.approved', ['name' => 'Maria da Silva', 'date' => '03/11/2026']));

        // It became a work order of the first service, waiting for the agenda, with the quote's lines.
        $order = WorkOrder::query()->sole();
        $this->assertSame([$order->getKey(), WorkOrder::QUOTE, $quote->getKey(), $this->install->getKey(), OrderStatus::Open], [$quote->order_id, $order->origin, $order->quote_id, $order->service_type_id, $order->status]);
        $this->assertStringContainsString('- 2 · Roteador Wi-Fi', $order->reported);

        // The team is told in the bell.
        $this->assertSame(1, $this->owner->notifications()->count());

        // Answered: never again, never changed, never deleted.
        $this->post($link, ['answer' => 'decline'])->assertOk()->assertSee(__('field.errors.quote_answered'));
        $this->assertFalse(QuoteResource::canEdit($quote) && $quote->isEditable());
        $this->assertFalse(QuoteResource::canDelete($quote));
        Livewire::test(EditQuote::class, ['record' => $quote->getKey()])->call('save')->assertNotified(__('field.errors.quote_answered'));
    }

    public function test_the_customer_declines(): void
    {
        $quote = app(QuoteService::class)->send($this->quote());

        $this->post(app(QuoteService::class)->link($quote), ['answer' => 'decline', 'reason' => 'Achei caro'])->assertRedirect();

        $quote->refresh();
        $this->assertSame([QuoteStatus::Declined, 'Achei caro', null], [$quote->status, $quote->decline_reason, $quote->order_id]);
        $this->assertSame(0, WorkOrder::count());
    }

    public function test_an_expired_quote_cannot_be_approved(): void
    {
        $quote = app(QuoteService::class)->send($this->quote());
        $link = app(QuoteService::class)->link($quote);

        $quote->forceFill(['valid_until' => '2026-11-02'])->save();
        $this->get($link)->assertOk()->assertSee(__('field.quote_page.expired', ['company' => $this->company->name]))->assertDontSee(__('field.quote_page.approve'));
        $this->post($link, ['answer' => 'approve', 'name' => 'Maria', 'agree' => '1'])->assertOk()->assertSee(__('field.errors.quote_expired'));
        $this->assertSame(QuoteStatus::Sent, $quote->fresh()->status);
    }

    public function test_approved_without_a_service_the_desk_opens_the_order(): void
    {
        $quote = app(QuoteService::class)->send($this->quote([['material_id' => $this->router->getKey(), 'description' => 'Roteador', 'quantity' => 1, 'unit_price_cents' => 18990]]));
        app(QuoteService::class)->approve($quote, 'Maria');
        $this->assertNull($quote->fresh()->order_id);

        Livewire::test(ViewQuote::class, ['record' => $quote->getKey()])
            ->callAction('convert', ['service_type_id' => $this->install->getKey()])
            ->assertHasNoActionErrors();
        $this->assertSame(WorkOrder::query()->sole()->getKey(), $quote->fresh()->order_id);
    }

    public function test_links_never_cross_companies(): void
    {
        $quote = app(QuoteService::class)->send($this->quote());
        $other = $this->tenant('outra', ['campo' => []]);

        // The same token on another company's address does not exist there.
        app(TenantContext::class)->set($other);
        $this->get($other->url('/orcamento/'.$quote->token))->assertNotFound();
        $this->get($this->company->url('/orcamento/'.str_repeat('a', 48)))->assertNotFound();
    }
}
