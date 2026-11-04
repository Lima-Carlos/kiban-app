<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Pmoc\Models\Equipment;
use App\Modules\Pmoc\Models\Plan;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * PMOC: the QR label opens a page without login (status of the maintenance, responsible technician with the ART)
 * where whoever is in front of the equipment asks for service, which becomes a Campo work order.
 */
class PmocPublicPageTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected Equipment $equipment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $this->company = $this->tenant('climafrio', ['campo' => [], 'pmoc' => []]);
        $this->signIn($this->company);
        $customer = Customer::create(['name' => 'Clínica Sol', 'phone' => '11999990000']);
        $plan = Plan::create(['customer_id' => $customer->getKey(), 'name' => 'PMOC Clínica', 'starts_on' => '2026-11-10', 'next_due_on' => '2026-11-10', 'responsible_name' => 'Eng. Paula Reis', 'art_number' => 'ART 2026-123']);
        $this->equipment = $plan->equipment()->create(['tag' => 'EV-01', 'kind' => 'split', 'room' => 'Recepção', 'serial' => 'SN-77']);
        auth()->logout();
    }

    protected function page(): string
    {
        return $this->company->url('/pmoc/'.$this->equipment->public_token);
    }

    public function test_the_page_opens_without_login_and_shows_only_what_is_public(): void
    {
        $this->assertSame(48, strlen($this->equipment->public_token));

        $this->get($this->page())->assertOk()
            ->assertSee('EV-01')->assertSee('Recepção')
            ->assertSee(__('pmoc.public.up_to_date'))
            ->assertSee('Eng. Paula Reis')->assertSee('ART 2026-123')
            ->assertDontSee('11999990000')->assertDontSee('SN-77');

        // Late after the due date; a wrong link or an inactive equipment shows nothing.
        $this->equipment->plan->update(['next_due_on' => '2026-10-30']);
        $this->get($this->page())->assertOk()->assertSee(__('pmoc.public.late'));
        $this->get($this->company->url('/pmoc/'.str_repeat('a', 48)))->assertNotFound();
        $this->equipment->update(['is_active' => false]);
        $this->get($this->page())->assertNotFound();
    }

    public function test_asking_for_service_opens_one_work_order_and_tells_the_office(): void
    {
        $ask = ['name' => 'Recepcionista Ana', 'phone' => '11 98888-7777', 'problem' => 'Pingando água no balcão'];

        $this->post($this->page(), $ask)->assertRedirect($this->page());
        $order = WorkOrder::query()->sole();
        $this->assertSame([WorkOrder::QR, 'high'], [$order->origin, $order->priority->value]);
        $this->assertStringContainsString('Pingando água no balcão', $order->reported);
        $this->assertStringContainsString('EV-01', $order->reported);
        $this->assertDatabaseHas('notifications', ['type' => \App\Notices\Notice::class]);

        // Asking again while it is open adds to the same order.
        $this->post($this->page(), ['problem' => 'Agora parou de gelar'] + $ask);
        $this->assertSame(1, WorkOrder::query()->count());
        $this->assertStringContainsString('Agora parou de gelar', WorkOrder::query()->sole()->reported);

        // Robots (the hidden field) and missing fields open nothing.
        $this->post($this->page(), ['website' => 'x'] + $ask);
        $this->post($this->page(), ['problem' => ''] + $ask)->assertSessionHasErrors('problem');
        $this->assertSame(1, WorkOrder::query()->count());
    }

    public function test_the_label_points_to_the_public_page(): void
    {
        $this->signIn($this->company);

        $this->get($this->company->url('/etiquetas/pmoc/'.$this->equipment->plan_id))->assertOk()->assertSee('EV-01');
        $this->assertStringContainsString('/pmoc/'.$this->equipment->public_token, $this->equipment->publicUrl());
    }

    public function test_texts_in_the_three_languages(): void
    {
        foreach (['pt_BR', 'en', 'es'] as $locale) {
            app()->setLocale($locale);

            foreach (['pmoc.public.ask', 'pmoc.public.up_to_date', 'pmoc.public.reported', 'pmoc.fields.art_number', 'pmoc.helpers.art_number'] as $key) {
                $this->assertNotSame($key, __($key), $locale.' '.$key);
            }
        }
    }
}
