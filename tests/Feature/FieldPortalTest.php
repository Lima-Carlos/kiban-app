<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Filament\Customers\Pages\ViewCustomer;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Services\CustomerPortal;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\Field\Services\QuoteService;
use App\Modules\Field\Services\WorkOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The end customer's page: one link with the next visits, the quotes, the finished services with their reports,
 * in the company's identity; only that customer's things; a new link cancels the old one.
 */
class FieldPortalTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected Customer $maria;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $this->company = $this->tenant('provedor', ['campo' => []]);
        $this->owner = $this->signIn($this->company);
        FieldSetting::current()->update(['business' => 'isp', 'configured_at' => now()]);
        app(FieldTemplates::class)->apply('isp');

        $this->maria = Customer::create(['name' => 'Maria da Silva', 'phone' => '5531999990000']);
        $this->maria->sites()->create(['street' => 'Rua da Bahia', 'number' => '10', 'city' => 'Belo Horizonte', 'is_main' => true]);
        $other = Customer::create(['name' => 'Outro Cliente']);
        $type = ServiceType::query()->where('name', 'Visita técnica')->sole();

        app(WorkOrderService::class)->open(['customer_id' => $this->maria->id, 'service_type_id' => $type->id, 'scheduled_at' => '2026-11-10 09:00:00'], $this->owner);
        $done = app(WorkOrderService::class)->open(['customer_id' => $this->maria->id, 'service_type_id' => ServiceType::query()->where('name', 'Instalação')->sole()->id], $this->owner);
        $done->forceFill(['status' => OrderStatus::Done, 'finished_at' => now()->subDay()])->save();
        app(WorkOrderService::class)->open(['customer_id' => $other->id, 'service_type_id' => $type->id, 'scheduled_at' => '2026-11-11 09:00:00'], $this->owner);
        app(QuoteService::class)->send(app(QuoteService::class)->create(['customer_id' => $this->maria->id], [
            ['description' => 'Troca do roteador', 'quantity' => 1, 'unit' => 'un', 'unit_price_cents' => 18990],
        ], $this->owner));
    }

    public function test_the_customer_sees_everything_in_one_link(): void
    {
        $url = app(CustomerPortal::class)->url($this->maria);

        $this->get($url)->assertOk()
            ->assertSee(__('field.portal.hello', ['name' => 'Maria da Silva']))
            ->assertSee('Visita técnica')
            ->assertSee(__('field.portal.to_schedule'))
            ->assertSee('Instalação')
            ->assertSee(__('field.portal.report'))
            ->assertSee('189,90')
            ->assertDontSee('11/11/2026');

        // Same link every time; a new one cancels the old.
        $this->assertSame($url, app(CustomerPortal::class)->url($this->maria->fresh()));
        $new = app(CustomerPortal::class)->renew($this->maria);
        $this->get($url)->assertNotFound();
        $this->get($new)->assertOk();
        $this->assertStringContainsString(urlencode($new), (string) app(CustomerPortal::class)->whatsappUrl($this->maria->fresh()));
    }

    public function test_the_buttons_on_the_customer(): void
    {
        Livewire::test(ViewCustomer::class, ['record' => $this->maria->id])
            ->assertActionVisible('portalOpen')
            ->assertActionVisible('portalWhatsapp')
            ->callAction('portalRenew')
            ->assertNotified(__('field.portal.renewed'));
        $this->assertNotNull($this->maria->fresh()->portal_token);
    }
}
