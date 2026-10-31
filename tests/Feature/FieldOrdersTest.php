<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Filament\Orders\Pages\Agenda;
use App\Modules\Field\Filament\Orders\Pages\CreateOrder;
use App\Modules\Field\Filament\Orders\Pages\EditOrder;
use App\Modules\Field\Filament\Orders\Pages\ViewOrder;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldException;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\Field\Services\WorkOrderService;
use App\People\PeopleService;
use App\People\PersonRuleException;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Kiban Core Campo, part 3: work orders with their own number, the customer's address, a copy of the type's
 * checklist, the technician's agenda (clashes are warned, never blocked), reschedule, take off the agenda,
 * cancel with the reason, the history, the agenda of the day with the map, and the plan limit of technicians.
 */
class FieldOrdersTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected User $technician;

    protected Customer $customer;

    protected ServiceType $install;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-03 12:00:00'));
        $this->company = $this->tenant('provedor', ['campo' => ['limits' => ['technicians' => 2]]]);
        $this->owner = $this->signIn($this->company);

        FieldSetting::current()->update(['business' => 'isp', 'configured_at' => now()]);
        app(FieldTemplates::class)->apply('isp');
        $this->install = ServiceType::query()->where('name', 'Instalação')->sole();

        $this->customer = Customer::create(['name' => 'Maria da Silva', 'phone' => '5531999990000']);
        $this->customer->sites()->create(['label' => 'Casa', 'street' => 'Rua da Bahia', 'number' => '10', 'district' => 'Centro', 'city' => 'Belo Horizonte', 'latitude' => -19.92, 'longitude' => -43.94, 'is_main' => true]);

        $this->technician = $this->member('Lucas Técnico', FieldRole::Technician);
    }

    protected function member(string $name, FieldRole $role): User
    {
        $user = User::factory()->memberOf($this->company, 'member')->create(['name' => $name]);
        app(AccessManager::class)->set($user, 'campo', $role->value);
        $this->coreMember($this->company, $user);

        return $user;
    }

    public function test_missing_data_gets_plain_messages(): void
    {
        Livewire::test(CreateOrder::class)
            ->fillForm(['service_type_id' => $this->install->getKey(), 'technician_id' => $this->technician->getKey()])
            ->call('create')
            ->assertHasFormErrors(['customer_id', 'scheduled_at'])
            ->assertSee(__('field.validation.customer'))
            ->assertSee(__('field.validation.time_with_technician'))
            ->assertDontSee('obrigatória a indicação');
        $this->assertSame(0, WorkOrder::count());
    }

    public function test_open_an_order_on_the_technicians_agenda(): void
    {
        // 14:00 in Brazil = 17:00 UTC (the form works in the company's time zone; the state is in UTC).
        Livewire::test(CreateOrder::class)
            ->fillForm([
                'customer_id' => $this->customer->getKey(),
                'service_type_id' => $this->install->getKey(),
                'priority' => 'high',
                'reported' => 'Quer internet de 500 mega',
                'technician_id' => $this->technician->getKey(),
                'scheduled_at' => '2026-11-04 17:00:00',
            ])
            ->assertFormSet(['site_id' => $this->customer->mainSite->getKey(), 'duration_minutes' => 120])
            ->call('create')
            ->assertHasNoFormErrors();

        $order = WorkOrder::query()->sole();
        $this->assertSame([1, OrderStatus::Scheduled, 'high', $this->technician->getKey(), 120], [$order->number, $order->status, $order->priority->value, $order->technician_id, $order->duration_minutes]);
        $this->assertSame('2026-11-04 17:00:00', $order->scheduled_at->toDateTimeString());
        $this->assertSame('OS 000001', $order->label());
        $this->assertSame($this->install->checklist, $order->checklist);
        $this->assertSame(['scheduled', 'opened'], $order->events()->pluck('type')->all());

        // The type's checklist changes later: the open order keeps its copy.
        $this->install->update(['checklist' => [['label' => 'Nova pergunta', 'type' => 'check']]]);
        $this->assertCount(6, $order->fresh()->checklist);

        // The next order gets the next number; without a time it waits on "To schedule".
        $second = app(WorkOrderService::class)->open(['customer_id' => $this->customer->getKey(), 'service_type_id' => $this->install->getKey()], $this->owner);
        $this->assertSame([2, OrderStatus::Open, null], [$second->number, $second->status, $second->technician_id]);

        // The order's page: the customer, the address, the checklist and the history in words.
        $this->get(OrderResource::getUrl('view', ['record' => $order]))
            ->assertOk()
            ->assertSee('Maria da Silva')
            ->assertSee('Rua da Bahia, 10')
            ->assertSee('Potência do sinal na ONU *')
            ->assertSee(__('field.events.opened'))
            ->assertSee('Lucas Técnico');
    }

    public function test_reschedule_take_off_and_cancel(): void
    {
        $service = app(WorkOrderService::class);
        $order = $service->open(['customer_id' => $this->customer->getKey(), 'service_type_id' => $this->install->getKey()], $this->owner);

        // Schedule from the order's page (company time 09:00 = 12:00 UTC).
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])
            ->callAction('schedule', ['technician_id' => $this->technician->getKey(), 'scheduled_at' => '2026-11-04 12:00:00', 'duration_minutes' => 60])
            ->assertHasNoActionErrors();
        $this->assertSame(['2026-11-04 12:00:00', OrderStatus::Scheduled], [$order->fresh()->scheduled_at->toDateTimeString(), $order->fresh()->status]);

        // Another order at the same time for the same technician: allowed, with a warning.
        $other = $service->open(['customer_id' => $this->customer->getKey(), 'service_type_id' => $this->install->getKey()], $this->owner);
        Livewire::test(ViewOrder::class, ['record' => $other->getKey()])
            ->callAction('schedule', ['technician_id' => $this->technician->getKey(), 'scheduled_at' => '2026-11-04 12:30:00', 'duration_minutes' => 60])
            ->assertNotified(__('field.orders.clash', ['name' => 'Lucas Técnico']));
        $this->assertCount(1, $service->clashes($this->technician, CarbonImmutable::parse('2026-11-04 12:30:00'), 60, $other->fresh()));

        // Rescheduling and taking off the agenda are written in the history.
        $service->schedule($order->fresh(), $this->technician, CarbonImmutable::parse('2026-11-05 13:00:00'), null, $this->owner);
        $service->unschedule($order->fresh(), $this->owner);
        $order->refresh();
        $this->assertSame([OrderStatus::Open, null, null], [$order->status, $order->technician_id, $order->scheduled_at]);
        $this->assertSame(['unscheduled', 'rescheduled', 'scheduled', 'opened'], $order->events()->pluck('type')->all());

        // Cancel needs the reason; then the order does not change any more.
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->callAction('cancel', ['reason' => ''])->assertHasActionErrors(['reason']);
        Livewire::test(ViewOrder::class, ['record' => $order->getKey()])->callAction('cancel', ['reason' => 'Cliente desistiu'])->assertHasNoActionErrors();
        $this->assertSame([OrderStatus::Canceled, 'Cliente desistiu'], [$order->fresh()->status, $order->fresh()->cancel_reason]);

        $this->expectException(FieldException::class);
        $service->schedule($order->fresh(), $this->technician, now()->addDay(), null, $this->owner);
    }

    public function test_only_technicians_of_the_company_get_orders(): void
    {
        $order = app(WorkOrderService::class)->open(['customer_id' => $this->customer->getKey(), 'service_type_id' => $this->install->getKey()], $this->owner);
        $desk = $this->member('Ana Atendimento', FieldRole::Dispatcher);

        $this->expectExceptionMessage(__('field.errors.not_a_technician', ['name' => 'Ana Atendimento']));
        app(WorkOrderService::class)->schedule($order, $desk, now()->addDay(), null, $this->owner);
    }

    public function test_editing_keeps_the_customer_and_the_type(): void
    {
        $order = app(WorkOrderService::class)->open(['customer_id' => $this->customer->getKey(), 'service_type_id' => $this->install->getKey(), 'reported' => 'Antes'], $this->owner);
        $otherType = ServiceType::query()->where('name', 'Visita técnica')->sole();

        Livewire::test(EditOrder::class, ['record' => $order->getKey()])
            ->fillForm(['reported' => 'Depois', 'priority' => 'urgent', 'service_type_id' => $otherType->getKey()])
            ->call('save')
            ->assertHasNoFormErrors();

        $order->refresh();
        $this->assertSame(['Depois', 'urgent', $this->install->getKey()], [$order->reported, $order->priority->value, $order->service_type_id]);
        $this->assertSame('edited', $order->events()->first()->type);
    }

    public function test_the_agenda_of_the_day(): void
    {
        $service = app(WorkOrderService::class);
        $second = $this->member('Bruno Técnico', FieldRole::Technician);
        $service->open(['customer_id' => $this->customer->getKey(), 'service_type_id' => $this->install->getKey(), 'technician_id' => $this->technician->getKey(), 'scheduled_at' => '2026-11-03 13:00:00'], $this->owner);
        $service->open(['customer_id' => $this->customer->getKey(), 'service_type_id' => $this->install->getKey(), 'priority' => 'urgent'], $this->owner);
        // Tomorrow: not on today's agenda.
        $service->open(['customer_id' => $this->customer->getKey(), 'service_type_id' => $this->install->getKey(), 'technician_id' => $second->getKey(), 'scheduled_at' => '2026-11-04 13:00:00'], $this->owner);

        $page = Livewire::test(Agenda::class);
        $columns = $page->instance()->getColumns();
        $this->assertSame(['Bruno Técnico', 'Lucas Técnico'], array_map(fn (array $column): string => $column['technician']->name, $columns));
        $this->assertSame([0, 1], array_map(fn (array $column): int => $column['orders']->count(), $columns));
        $this->assertCount(1, $page->instance()->getUnscheduled());
        $this->assertCount(1, $page->instance()->pins($columns));

        $page->assertSee('10:00–12:00')->assertSee(__('field.agenda.unscheduled'))->assertSee(__('field.enums.priority.urgent'));
        $page->call('next');
        $this->assertSame([1, 0], array_map(fn (array $column): int => $column['orders']->count(), $page->instance()->getColumns()));
    }

    public function test_the_customer_page_shows_the_orders(): void
    {
        $order = app(WorkOrderService::class)->open(['customer_id' => $this->customer->getKey(), 'service_type_id' => $this->install->getKey()], $this->owner);

        $this->get(CustomerResource::getUrl('view', ['record' => $this->customer]))
            ->assertOk()
            ->assertSee('OS 000001 · Instalação')
            ->assertSee(__('field.orders.new'));

        // A customer with orders is turned off, never deleted; an order is never deleted.
        $this->assertSame(__('field.denied.customer_has_orders'), CustomerResource::getDeleteAuthorizationResponse($this->customer)->message());
        $this->assertFalse(OrderResource::canDelete($order));

        // "Open work order" from the customer's page comes with the customer chosen.
        $this->get(OrderResource::getUrl('create', ['cliente' => $this->customer->getKey()]))->assertOk();
        Livewire::withQueryParams(['cliente' => $this->customer->getKey()])->test(CreateOrder::class)->assertFormSet(['customer_id' => $this->customer->getKey()]);
    }

    public function test_customer_search_by_document_or_phone(): void
    {
        Customer::create(['name' => 'Padaria Pão Quente', 'document' => '11444777000161', 'document_type' => 'cnpj']);

        $this->assertSame(['Padaria Pão Quente'], array_values(OrderResource::searchCustomers('11.444.777')));
        $this->assertSame(['Maria da Silva'], array_values(OrderResource::searchCustomers('99999-0000')));
        $this->assertSame(['Maria da Silva'], array_values(OrderResource::searchCustomers('maria')));
        $this->assertSame([], OrderResource::searchCustomers('xx'));
    }

    public function test_who_works_with_orders(): void
    {
        $viewer = $this->member('Vera Consulta', FieldRole::Viewer);
        $this->actingAs($viewer);
        $this->get(OrderResource::getUrl('index'))->assertOk();
        $this->get(OrderResource::getUrl('agenda'))->assertOk();
        $this->assertFalse(OrderResource::canCreate());

        $this->actingAs($this->technician);
        $this->get(OrderResource::getUrl('index'))->assertForbidden();
        $this->get(OrderResource::getUrl('agenda'))->assertForbidden();
    }

    public function test_the_plan_limits_the_technicians(): void
    {
        // The plan allows 2: Lucas plus one more.
        app(PeopleService::class)->add($this->company, ['name' => 'Bruno', 'phone' => '11977770001'], ['campo' => ['role' => 'technician']], $this->owner);

        try {
            app(PeopleService::class)->add($this->company, ['name' => 'Carla', 'phone' => '11977770002'], ['campo' => ['role' => 'technician']], $this->owner);
            $this->fail('The third technician should be refused.');
        } catch (PersonRuleException $exception) {
            $this->assertSame(__('field.errors.technician_limit', ['limit' => 2]), $exception->getMessage());
        }

        // Other roles are not counted.
        app(PeopleService::class)->add($this->company, ['name' => 'Davi', 'phone' => '11977770003'], ['campo' => ['role' => 'dispatcher']], $this->owner);
        $this->assertSame(FieldRole::Dispatcher->value, app(AccessManager::class)->role(User::query()->where('name', 'Davi')->sole(), 'campo'));
    }
}
