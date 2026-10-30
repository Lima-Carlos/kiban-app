<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Filament\Pages\CompanyProfilePage;
use App\Models\CompanyProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Filament\Orders\Pages\ViewOrder;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\Field\Services\OrderExecutionService;
use App\Modules\Field\Services\OrderReport;
use App\Modules\Field\Services\WorkOrderService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Campo, part 5: the report of a done work order with the company's identity (logo, color, data, footer),
 * at a signed link and as a PDF, sent to the customer by WhatsApp, with a verification code.
 */
class FieldReportTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected User $technician;

    protected WorkOrder $order;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $this->company = $this->tenant('provedor', ['campo' => []]);
        $this->owner = $this->signIn($this->company);

        FieldSetting::current()->update(['business' => 'isp', 'configured_at' => now()]);
        app(FieldTemplates::class)->apply('isp');

        $this->technician = User::factory()->memberOf($this->company, 'member')->create(['name' => 'Lucas Técnico']);
        app(AccessManager::class)->set($this->technician, 'campo', FieldRole::Technician->value);
        $this->coreMember($this->company, $this->technician);

        $customer = Customer::create(['name' => 'Maria da Silva', 'phone' => '5531999990000']);
        $customer->sites()->create(['street' => 'Rua da Bahia', 'number' => '10', 'city' => 'Belo Horizonte', 'is_main' => true]);

        $this->order = app(WorkOrderService::class)->open([
            'customer_id' => $customer->getKey(),
            'service_type_id' => ServiceType::query()->where('name', 'Visita técnica')->sole()->getKey(),
            'technician_id' => $this->technician->getKey(),
            'scheduled_at' => '2026-11-03 11:00:00',
        ], $this->owner);
    }

    protected function finish(): void
    {
        $image = 'data:image/png;base64,'.base64_encode(UploadedFile::fake()->image('a.png', 40, 40)->getContent());
        $send = fn (string $type, array $data = []) => app(OrderExecutionService::class)->apply($this->order->fresh(), $this->technician, ['id' => (string) Str::uuid(), 'type' => $type, 'taken_at' => now()->getTimestampMs(), 'data' => $data]);

        $send('check_in');
        $send('answer', ['key' => 'problem_found', 'value' => 'Roteador travado']);
        $send('answer', ['key' => 'solution', 'value' => 'Reiniciado e atualizado']);
        $send('photo', ['key' => 'photo_after', 'image' => $image]);
        $send('note', ['text' => 'Tudo funcionando.']);
        $send('signature', ['name' => 'Maria da Silva', 'image' => $image]);
        $send('finish');
        $this->order->refresh();
    }

    public function test_the_company_sets_its_identity(): void
    {
        Livewire::test(CompanyProfilePage::class)
            ->assertFormSet(['legal_name' => 'Provedor', 'color' => '#044299'])
            ->fillForm(['legal_name' => 'Rede Exemplo Fibra Ltda', 'color' => '#0F766E', 'document' => '11.444.777/0001-61', 'phone' => '(31) 3333-0000', 'report_footer' => 'Garantia de 90 dias.'])
            ->call('save')
            ->assertHasNoFormErrors();

        $profile = CompanyProfile::current();
        $this->assertSame(['Rede Exemplo Fibra Ltda', '#0F766E', 'Garantia de 90 dias.'], [$profile->name(), $profile->color(), $profile->report_footer]);

        // Only owners and administrators.
        $this->actingAs($this->technician);
        $this->get(CompanyProfilePage::getUrl())->assertForbidden();
    }

    public function test_the_report_of_a_done_order(): void
    {
        CompanyProfile::query()->create(['legal_name' => 'Rede Exemplo Fibra Ltda', 'color' => '#0F766E', 'report_footer' => 'Garantia de 90 dias.']);
        $reports = app(OrderReport::class);

        // Not done yet: no report.
        $this->get($reports->url($this->order))->assertNotFound();

        $this->finish();

        $this->get($reports->url($this->order))
            ->assertOk()
            ->assertSee('Rede Exemplo Fibra Ltda')
            ->assertSee('#0F766E', false)
            ->assertSee('Problema encontrado: Roteador travado')
            ->assertSee('Tudo funcionando.')
            ->assertSee('Garantia de 90 dias.')
            ->assertSee($reports->verificationCode($this->order))
            ->assertSee('data:image/png;base64', false);

        $this->get($reports->pdfUrl($this->order))->assertOk()->assertHeader('Content-Type', 'application/pdf');

        // A changed link does not open.
        $this->get(str_replace('/'.$this->order->getKey().'/', '/'.($this->order->getKey() + 1).'/', $reports->url($this->order)))->assertForbidden();
        $this->get($this->company->url('/os/'.$this->order->getKey().'/relatorio'))->assertForbidden();

        // To the customer by WhatsApp, with the link.
        $whatsapp = $reports->whatsappUrl($this->order);
        $this->assertStringStartsWith('https://wa.me/5531999990000?text=', $whatsapp);
        $this->assertStringContainsString(rawurlencode($reports->url($this->order)), $whatsapp);

        // The office has the buttons on the order's page.
        Livewire::test(ViewOrder::class, ['record' => $this->order->getKey()])
            ->assertActionVisible('reportWhatsapp')
            ->assertActionVisible('reportPdf');
    }
}
