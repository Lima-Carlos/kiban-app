<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Filament\Customers\Pages\CreateCustomer;
use App\Modules\Field\Filament\Customers\Pages\EditCustomer;
use App\Modules\Field\Filament\Customers\Pages\ListCustomers;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Services\AddressLocator;
use App\Modules\Field\Services\CustomerImporter;
use App\Modules\Field\Services\FieldAccess;
use App\Support\Country;
use App\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Kiban Campo, part 1: customers with several service addresses and the point on the map; CPF/CNPJ checked and
 * unique; the postal code fills the address; addresses without a point are searched on the map in the background;
 * import from a spreadsheet that updates instead of duplicating; who sees and who changes.
 */
class FieldCustomersTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    /** @var array<int, string> addresses asked to the map service */
    protected array $searches = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['kiban.field.locator_pause_ms' => 0]);
        $this->company = $this->tenant('provedor', ['campo' => []]);
        $this->owner = $this->signIn($this->company);

        $this->externalServices = [
            'viacep.com.br/ws/30130010/json/' => fn () => Http::response(['cep' => '30130-010', 'logradouro' => 'Avenida Afonso Pena', 'bairro' => 'Centro', 'localidade' => 'Belo Horizonte', 'uf' => 'MG']),
            'viacep.com.br/ws/*' => fn () => Http::response(['erro' => 'true']),
            'nominatim.openstreetmap.org/search*' => function (Request $request) {
                $this->searches[] = $request['q'] ?? (string) $request['street'].' / '.$request['city'];

                // Nothing in "Nowhere", and number 999 does not exist on the map (the street does).
                return str_contains((string) ($request['city'] ?? $request['q']), 'Nowhere') || str_starts_with((string) $request['street'], '999 ')
                    ? Http::response([])
                    : Http::response([['lat' => '-19.9227', 'lon' => '-43.9385']]);
            },
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function form(array $data): array
    {
        return $data + ['kind' => Customer::COMPANY, 'name' => 'Cliente'];
    }

    public function test_a_customer_with_two_addresses(): void
    {
        Livewire::test(CreateCustomer::class)
            ->fillForm([
                'kind' => Customer::PERSON,
                'name' => 'Maria da Silva',
                'document' => '529.982.247-25',
                'external_code' => '1001',
                'phone' => '(31) 99999-0000',
                'email' => 'Maria@Exemplo.com',
                'sites' => [
                    'a' => ['label' => 'Casa', 'postal_code' => '30130-010', 'street' => 'Avenida Afonso Pena', 'number' => '100', 'district' => 'Centro', 'city' => 'Belo Horizonte', 'state' => 'MG', 'reference' => 'Portão azul'],
                    'b' => ['label' => 'Loja', 'street' => 'Rua Um', 'number' => '5', 'city' => 'Contagem', 'state' => 'MG', 'latitude' => -19.93, 'longitude' => -44.05],
                ],
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $customer = Customer::query()->sole();
        $this->assertSame(['529.982.247-25' => '52998224725', 'cpf', 'person', '5531999990000', 'maria@exemplo.com'], [
            '529.982.247-25' => $customer->document, $customer->document_type, $customer->kind, $customer->phone, $customer->email,
        ]);
        $this->assertSame('CPF 529.982.247-25', $customer->documentLabel());

        // Exactly one main address (the first, none was marked); without a point it waits for the map search.
        [$home, $shop] = $customer->sites()->get()->all();
        $this->assertTrue($home->is_main);
        $this->assertFalse($shop->is_main);
        $this->assertSame(Site::PENDING, $home->locate_status);
        $this->assertNull($home->location_source);
        $this->assertSame([Site::TYPED, null], [$shop->location_source, $shop->locate_status]);
        $this->assertSame('Avenida Afonso Pena, 100 - Centro - Belo Horizonte/MG', $home->address());

        // The page of the customer shows both addresses and where each point came from.
        $this->get(CustomerResource::getUrl('view', ['record' => $customer]))
            ->assertOk()
            ->assertSee('Portão azul')
            ->assertSee(__('field.sites.points.pending'))
            ->assertSee(__('field.sites.points.typed'));

        // Editing keeps the document as typed and lets another address become the main one.
        Livewire::test(EditCustomer::class, ['record' => $customer->getKey()])
            ->assertFormSet(['document' => '529.982.247-25'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('52998224725', $customer->fresh()->document);
    }

    public function test_documents_are_checked_and_never_repeat(): void
    {
        Livewire::test(CreateCustomer::class)->fillForm($this->form(['document' => '111.111.111-11', 'sites' => []]))->call('create')->assertHasFormErrors(['document']);

        Livewire::test(CreateCustomer::class)->fillForm($this->form(['name' => 'Padaria', 'document' => '11.444.777/0001-61', 'external_code' => 'A1', 'sites' => []]))->call('create')->assertHasNoFormErrors();
        $this->assertSame([Customer::COMPANY, 'cnpj'], [Customer::query()->sole()->kind, Customer::query()->sole()->document_type]);

        Livewire::test(CreateCustomer::class)->fillForm($this->form(['document' => '11444777000161', 'sites' => []]))->call('create')->assertHasFormErrors(['document']);
        Livewire::test(CreateCustomer::class)->fillForm($this->form(['external_code' => 'A1', 'sites' => []]))->call('create')->assertHasFormErrors(['external_code']);
        $this->assertSame(1, Customer::count());
    }

    public function test_the_postal_code_fills_the_address(): void
    {
        $this->assertSame(
            ['street' => 'Avenida Afonso Pena', 'district' => 'Centro', 'city' => 'Belo Horizonte', 'state' => 'MG'],
            app(AddressLocator::class)->postalCode('30130-010'),
        );
        $this->assertNull(app(AddressLocator::class)->postalCode('99999-999'));
        $this->assertNull(app(AddressLocator::class)->postalCode('123'));
    }

    public function test_phones_are_shown_as_people_read_them(): void
    {
        $this->assertSame('+55 (31) 98888-1234', Country::formatPhone('5531988881234'));
        $this->assertSame('+55 (31) 3333-4444', Country::formatPhone('553133334444'));
        $this->assertSame('+52 5500000000', Country::formatPhone('525500000000'));
        $this->assertSame('+598 99123456', Country::formatPhone('59899123456'));
        $this->assertNull(Country::formatPhone(null));

        // The form shows the number formatted, and saving keeps the same digits.
        $customer = Customer::create(['name' => 'Cliente', 'phone' => '5531988881234']);
        Livewire::test(EditCustomer::class, ['record' => $customer->getKey()])
            ->assertFormSet(['phone' => '+55 (31) 98888-1234'])
            ->call('save')
            ->assertHasNoFormErrors();
        $this->assertSame('5531988881234', $customer->fresh()->phone);
    }

    public function test_addresses_without_a_point_are_searched_on_the_map(): void
    {
        $customer = Customer::create(['name' => 'Cliente']);
        $found = $customer->sites()->create(['street' => 'Rua da Bahia', 'number' => '10', 'city' => 'Belo Horizonte', 'state' => 'MG', 'is_main' => true]);
        $missing = $customer->sites()->create(['street' => 'Rua Sem Fim', 'number' => '1', 'district' => 'Centro', 'city' => 'Nowhere']);
        $streetOnly = $customer->sites()->create(['street' => 'Rua Longa', 'number' => '999', 'city' => 'Belo Horizonte']);
        $typed = $customer->sites()->create(['street' => 'Rua Dois', 'city' => 'Belo Horizonte', 'latitude' => -19.8, 'longitude' => -43.9]);
        $noAddress = $customer->sites()->create(['label' => 'Só o nome']);

        // Another company without Kiban Campo: never searched.
        $other = $this->tenant('outra', ['frota' => []]);
        app(TenantContext::class)->run($other, fn () => Customer::create(['name' => 'X'])->sites()->create(['street' => 'Rua X', 'city' => 'Belo Horizonte']));
        app(TenantContext::class)->set($this->company);

        $this->artisan('kiban:field')->assertSuccessful();

        // From the most precise to the least: street with number, street only, district.
        $this->assertSame([
            '10 Rua da Bahia / Belo Horizonte',
            '1 Rua Sem Fim / Nowhere', 'Rua Sem Fim / Nowhere', 'Centro, Nowhere',
            '999 Rua Longa / Belo Horizonte', 'Rua Longa / Belo Horizonte',
        ], $this->searches);
        $this->assertSame([Site::SEARCH, Site::FOUND], [$streetOnly->fresh()->location_source, $streetOnly->fresh()->locate_status]);
        $found->refresh();
        $this->assertSame([-19.9227, -43.9385, Site::SEARCH, Site::FOUND], [$found->latitude, $found->longitude, $found->location_source, $found->locate_status]);
        $this->assertSame([null, Site::NOT_FOUND], [$missing->fresh()->latitude, $missing->fresh()->locate_status]);
        $this->assertSame([Site::TYPED, null], [$typed->fresh()->location_source, $typed->fresh()->locate_status]);
        $this->assertNull($noAddress->fresh()->locate_status);

        // Nothing left to search: no new request. A changed address is searched again.
        $this->artisan('kiban:field')->assertSuccessful();
        $this->assertCount(6, $this->searches);
        $missing->refresh()->update(['city' => 'Belo Horizonte']);
        $this->assertSame(Site::PENDING, $missing->fresh()->locate_status);

        // A person moving the point makes it theirs.
        $found->update(['latitude' => -19.95, 'longitude' => -43.95]);
        $this->assertSame(Site::TYPED, $found->fresh()->location_source);
    }

    public function test_import_from_a_spreadsheet(): void
    {
        $existing = Customer::create(['name' => 'Antigo Nome', 'external_code' => '200', 'phone' => '5531911112222', 'notes' => 'Cliente antigo']);
        $existing->sites()->create(['street' => 'Rua Velha', 'number' => '1', 'city' => 'Belo Horizonte', 'is_main' => true]);

        $file = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($file, implode("\n", [
            'Código;Nome;CPF ou CNPJ;Celular;Rua;Número;Cidade;Estado;Latitude;Longitude;Coluna que não existe',
            '100;Maria da Silva;529.982.247-25;(31) 98888-7777;Avenida Brasil;50;Belo Horizonte;MG;-19.91;-43.93;x',
            '200;Nome Novo;;;Rua Velha;1;Belo Horizonte;MG;;;',
            ';;;;;;;;;;',
            '300;Documento Errado;123.456.789-00;;;;;;;;',
            ';Sem Código;;;;;;;;;',
        ]));

        $result = app(CustomerImporter::class)->import($file, 'csv');

        $this->assertSame(2, $result['created']);
        $this->assertSame(1, $result['updated']);
        $this->assertSame([['line' => 5, 'message' => __('field.errors.document')]], $result['errors']);

        $maria = Customer::query()->where('external_code', '100')->sole();
        $this->assertSame(['Maria da Silva', 'person', '52998224725', '5531988887777'], [$maria->name, $maria->kind, $maria->document, $maria->phone]);
        $this->assertSame([-19.91, -43.93, Site::IMPORT, null], [$maria->mainSite->latitude, $maria->mainSite->longitude, $maria->mainSite->location_source, $maria->mainSite->locate_status]);

        // Updated by the code: the same address is not added again, and empty cells erased nothing.
        $existing->refresh();
        $this->assertSame(['Nome Novo', '5531911112222', 'Cliente antigo', 1], [$existing->name, $existing->phone, $existing->notes, $existing->sites()->count()]);

        // The same spreadsheet again: lines with a code or document never duplicate (one without either is new again,
        // as the model explains: there is no safe way to know it is the same customer).
        $again = app(CustomerImporter::class)->import($file, 'csv');
        $this->assertSame([1, 2], [$again['created'], $again['updated']]);
        $this->assertSame(4, Customer::count());
        $this->assertSame(1, Site::query()->where('customer_id', $maria->getKey())->count());
    }

    public function test_the_model_spreadsheet_imports_as_it_is(): void
    {
        app()->setLocale('es');
        $model = app(CustomerImporter::class)->template();

        $reader = new Reader;
        $reader->open($model);
        $header = null;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                /** @var Row $row */
                $header ??= $row->toArray();
            }
        }
        $reader->close();
        $this->assertSame(['Código', 'Nombre', 'Nombre comercial'], array_slice($header, 0, 3));

        // A company in Mexico: the document is free text and the spreadsheet in Spanish works.
        $mexico = $this->tenant('mexico', ['campo' => []], ['country' => 'MX']);
        app(TenantContext::class)->run($mexico, function () use ($model): void {
            $result = app(CustomerImporter::class)->import($model, 'xlsx');
            $this->assertSame([1, []], [$result['created'], $result['errors']]);
            $maria = Customer::query()->sole();
            $this->assertSame(['María González', '525500000000', 'Ciudad Ejemplo'], [$maria->name, $maria->phone, $maria->mainSite->city]);
        });
        @unlink($model);
    }

    public function test_who_sees_and_who_changes(): void
    {
        Customer::create(['name' => 'Cliente']);

        $dispatcher = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($dispatcher, 'campo', FieldRole::Dispatcher->value);
        $this->actingAs($dispatcher);
        $this->coreMember($this->company, $dispatcher);
        $this->assertSame(FieldRole::Dispatcher, app(FieldAccess::class)->role($dispatcher));
        $this->get(CustomerResource::getUrl('index'))->assertOk();
        $this->assertTrue(CustomerResource::canCreate());

        $viewer = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($viewer, 'campo', FieldRole::Viewer->value);
        $this->actingAs($viewer);
        $this->coreMember($this->company, $viewer);
        $this->get(CustomerResource::getUrl('index'))->assertOk();
        $this->assertFalse(CustomerResource::canCreate());
        Livewire::test(ListCustomers::class)->assertActionHidden('create');

        $technician = User::factory()->memberOf($this->company, 'member')->create();
        app(AccessManager::class)->set($technician, 'campo', FieldRole::Technician->value);
        $this->actingAs($technician);
        $this->coreMember($this->company, $technician);
        $this->get(CustomerResource::getUrl('index'))->assertForbidden();

        $nobody = User::factory()->memberOf($this->company, 'member')->create();
        $this->actingAs($nobody);
        $this->coreMember($this->company, $nobody);
        $this->get(CustomerResource::getUrl('index'))->assertForbidden();
    }

    public function test_each_company_sees_only_its_customers(): void
    {
        $mine = Customer::create(['name' => 'Meu cliente']);
        $other = $this->tenant('outra', ['campo' => []]);
        $theirs = app(TenantContext::class)->run($other, fn () => Customer::create(['name' => 'Cliente da outra']));
        app(TenantContext::class)->set($this->company);

        $this->assertSame(['Meu cliente'], Customer::pluck('name')->all());
        $this->get(CustomerResource::getUrl('view', ['record' => $theirs->getKey()]))->assertNotFound();

        // A site can never point to another company's customer (the database refuses).
        $this->expectException(\Illuminate\Database\QueryException::class);
        \Illuminate\Support\Facades\DB::table('fd_sites')->insert(['tenant_id' => $this->company->getKey(), 'customer_id' => $theirs->getKey(), 'is_main' => false]);
        unset($mine);
    }
}
