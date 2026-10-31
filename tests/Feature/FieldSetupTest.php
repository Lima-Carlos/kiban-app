<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Filament\FieldSettingsPage;
use App\Modules\Field\Filament\Materials\Pages\ManageMaterials;
use App\Modules\Field\Filament\Orders\Pages\ListOrders;
use App\Modules\Field\Filament\ServiceTypes\Pages\ManageServiceTypes;
use App\Modules\Field\Filament\ServiceTypes\ServiceTypeResource;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\Material;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Services\FieldFormat;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\ModuleRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Core Campo, part 2: the business chosen in the first steps brings the ready types of service (with the
 * technician's checklist) and materials; the manager adjusts types, checklists, prices and materials.
 */
class FieldSetupTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->tenant('provedor', ['campo' => []]);
        $this->owner = $this->signIn($this->company);
    }

    public function test_choosing_the_business_brings_the_ready_model(): void
    {
        $module = app(ModuleRegistry::class)->get(FieldModule::KEY);
        $this->assertFalse($module->isStepDone($this->company, 'business'));

        // Before choosing the business, types of service cannot be created yet (the step says why).
        $this->assertSame(__('app.denied.step', ['step' => __('field.steps.business')]), ServiceTypeResource::getCreateAuthorizationResponse()->message());

        Livewire::test(FieldSettingsPage::class)
            ->fillForm(['business' => 'isp', 'currency' => 'BRL', 'signature_required' => true])
            ->call('save')
            ->assertHasNoFormErrors()
            ->assertNotified();

        $this->assertTrue($module->isStepDone($this->company, 'business'));
        $this->assertTrue($module->isStepDone($this->company, 'service_types'));
        $this->assertSame(['Instalação', 'Sem sinal / lentidão', 'Mudança de endereço', 'Retirada de equipamento', 'Visita técnica'], ServiceType::query()->orderBy('sort')->pluck('name')->all());
        $this->assertSame(6, Material::count());

        // The installation checklist: the signal power is a required number in dBm; the cause is a list.
        $install = ServiceType::query()->where('name', 'Instalação')->sole();
        $this->assertSame(120, $install->duration_minutes);
        $this->assertSame(['key' => 'signal_power', 'label' => 'Potência do sinal na ONU', 'type' => 'number', 'required' => true, 'unit' => 'dBm', 'options' => []], $install->checklist[0]);
        $noSignal = ServiceType::query()->where('name', 'Sem sinal / lentidão')->sole();
        $this->assertSame(['Fibra rompida', 'Conector ou emenda', 'Equipamento com defeito', 'Problema do lado do cliente', 'Outra'], $noSignal->checklist[1]['options']);
        $this->assertSame('m', Material::query()->where('name', 'Cabo drop')->sole()->unit);

        // Another business later only adds what is missing ("Visita técnica" and "Instalação" already exist).
        $this->assertSame(['types' => 2, 'materials' => 3], app(FieldTemplates::class)->apply('general'));
        $this->assertSame(['types' => 0, 'materials' => 0], app(FieldTemplates::class)->apply('general'));
        $this->assertSame(1, ServiceType::query()->where('name', 'Visita técnica')->count());

        // "Start from scratch" creates nothing.
        $this->assertSame(['types' => 0, 'materials' => 0], app(FieldTemplates::class)->apply('none'));
    }

    public function test_the_model_comes_in_the_language_of_who_chooses(): void
    {
        app()->setLocale('es');
        app(FieldTemplates::class)->apply('hvac');

        $this->assertSame(['Instalación de split', 'Limpieza e higienización', 'Reparación', 'Carga de gas'], ServiceType::query()->orderBy('sort')->pluck('name')->all());
        $this->assertSame(['R-410A', 'R-32', 'R-22'], ServiceType::query()->where('name', 'Carga de gas')->sole()->checklist[0]['options']);
        $this->assertTrue(Material::query()->where('name', 'Tubo de cobre')->exists());
    }

    public function test_the_manager_builds_a_type_of_service_with_its_checklist(): void
    {
        FieldSetting::current()->update(['business' => 'none', 'configured_at' => now()]);

        Livewire::test(ManageServiceTypes::class)
            ->callAction('create', [
                'name' => 'Troca de roteador',
                'color' => '#0F766E',
                'duration_minutes' => 45,
                'price_cents' => '150.50',
                'signature_required' => true,
                'photo_required' => true,
                'checklist' => [
                    ['label' => 'Modelo do roteador novo', 'type' => 'text', 'required' => true],
                    ['label' => 'Sinal Wi-Fi no quarto', 'type' => 'number', 'unit' => 'dBm', 'required' => false],
                    ['label' => 'Senha trocada', 'type' => 'yes_no', 'required' => true],
                    ['label' => 'Faixa', 'type' => 'choice', 'options' => ['2,4 GHz', '5 GHz', '5 GHz'], 'required' => true],
                ],
            ])
            ->assertHasNoActionErrors();

        $type = ServiceType::query()->sole();
        $this->assertSame([15050, 45, true], [$type->price_cents, $type->duration_minutes, $type->photo_required]);
        $this->assertCount(4, $type->checklist);
        $this->assertSame(['dBm', ['2,4 GHz', '5 GHz']], [$type->checklist[1]['unit'], $type->checklist[3]['options']]);
        $this->assertSame(4, collect($type->checklist)->pluck('key')->filter()->unique()->count());
        $this->assertSame('R$ 150,50', str_replace("\u{A0}", ' ', FieldFormat::money($type->price_cents)));

        // Keys stay when the checklist is changed (answers of the work orders keep pointing to the right question).
        $keys = collect($type->checklist)->pluck('key')->all();
        $type->update(['checklist' => array_reverse($type->checklist)]);
        $this->assertSame(array_reverse($keys), collect($type->fresh()->checklist)->pluck('key')->all());

        // Duplicate: a copy to adjust, with the same questions.
        Livewire::test(ManageServiceTypes::class)->callTableAction('duplicate', $type);
        $copy = ServiceType::query()->where('name', 'Troca de roteador (cópia)')->sole();
        $this->assertSame(collect($type->fresh()->checklist)->pluck('label')->all(), collect($copy->checklist)->pluck('label')->all());
    }

    public function test_the_checklist_keeps_only_what_makes_sense(): void
    {
        $clean = ServiceType::cleanChecklist([
            ['label' => '', 'type' => 'text'],
            'not an item',
            ['label' => ' Foto ', 'type' => 'photo', 'unit' => 'm', 'options' => ['a']],
            ['label' => 'Algo', 'type' => 'unknown', 'key' => 'k1'],
            ['label' => 'Repetida', 'type' => 'check', 'key' => 'k1'],
        ]);

        $this->assertCount(3, $clean);
        $this->assertSame(['Foto', 'photo', null, []], [$clean[0]['label'], $clean[0]['type'], $clean[0]['unit'], $clean[0]['options']]);
        $this->assertSame(['k1', 'check'], [$clean[1]['key'], $clean[1]['type']]);
        $this->assertNotSame('k1', $clean[2]['key']);
    }

    public function test_materials_with_unit_and_price(): void
    {
        Livewire::test(ManageMaterials::class)
            ->callAction('create', ['name' => 'Cabo drop', 'code' => 'DROP-01', 'unit' => 'm', 'price_cents' => '2.35'])
            ->assertHasNoActionErrors();

        $this->assertSame([235, 'm', 'DROP-01'], [Material::query()->sole()->price_cents, Material::query()->sole()->unit, Material::query()->sole()->code]);
    }

    public function test_who_sets_up(): void
    {
        FieldSetting::current()->update(['business' => 'none', 'configured_at' => now()]);

        // The service desk sees the types of service but does not change them, nor the settings.
        $desk = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($desk, 'campo', FieldRole::Dispatcher->value);
        $this->actingAs($desk);
        $this->coreMember($this->company, $desk);
        $this->get(ServiceTypeResource::getUrl('index'))->assertOk();
        $this->assertFalse(ServiceTypeResource::canCreate());
        $this->get(FieldSettingsPage::getUrl())->assertForbidden();

        // The technician does not see the set-up screens.
        $technician = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($technician, 'campo', FieldRole::Technician->value);
        $this->actingAs($technician);
        $this->coreMember($this->company, $technician);
        $this->get(ServiceTypeResource::getUrl('index'))->assertForbidden();
    }

    public function test_the_empty_orders_list_points_to_the_missing_step(): void
    {
        // The new order waits for the service types, which wait for the business: the list points to the business.
        Livewire::test(ListOrders::class)
            ->assertSee(__('app.empty.step', ['step' => __('field.steps.business')]))
            ->assertSee(FieldSettingsPage::getUrl(), false)
            ->assertDontSee(__('field.orders.empty_help'));
    }
}
