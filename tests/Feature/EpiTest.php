<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Connections\Bridges\FieldEpi;
use App\Connections\ConnectionCatalog;
use App\Filament\Pages\EpiItemsPage;
use App\Filament\Pages\EpiPage;
use App\Filament\Pages\MyEpiPage;
use App\Filament\Pages\PeoplePage;
use App\Filament\Pages\PersonEpiPage;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Filament\ExecuteOrderPage;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Services\FieldTemplates;
use App\Modules\Field\Services\WorkOrderService;
use App\People\Epi\DeliveryReason;
use App\People\Epi\EpiDelivery;
use App\People\Epi\EpiDeliveryLine;
use App\People\Epi\EpiException;
use App\People\Epi\EpiExporter;
use App\People\Epi\EpiItem;
use App\People\Epi\EpiRecord;
use App\People\Epi\EpiReturn;
use App\People\Epi\EpiService;
use App\People\Epi\EpiSetting;
use App\People\Epi\EpiTemplates;
use App\People\Epi\LineStatus;
use App\People\Epi\ReturnOutcome;
use App\People\Person;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use LogicException;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * PPE and tools of the people (People screen, every system): ready items by country, stock, useful life and the
 * CA, the signature with the finger (frozen after), signing later on the person's phone, returns and replacements,
 * who handles what (HR everything; Frota/Campo managers only tools), separation between companies, the PDF record,
 * the spreadsheet, the bell once per milestone and the warning in the Campo.
 */
class EpiTest extends TestCase
{
    use RefreshDatabase;

    /** A 60×30 PNG with a dark stroke (a "signature"). */
    public const SIGNATURE = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADwAAAAeCAIAAAD/+uoYAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAVklEQVRYhe3XIQ4AMQgAweuljv8/FU0f0NRUbEOyK8FMcIyq+rr1vwbcJJpKNJVoKtFUoqnmaRERpONUZu7DlpcWTSWaqiV6+G5BiaYSTSWaSjRVS/QCmwYJN4x4apsAAAAASUVORK5CYII=';

    /** The same size, all white (an empty pad). */
    public const BLANK = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADwAAAAeCAIAAAD/+uoYAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAPUlEQVRYhe3OQQ0AIRAAseP8e1488BhC0irompnvNf/twAnpinRFuiJdka5IV6Qr0hXpinRFuiJdka5IVzbxvQM5iXr4ggAAAABJRU5ErkJggg==';

    protected Tenant $company;

    protected User $owner;

    protected User $hr;

    protected User $fleetManager;

    protected User $worker;

    protected Person $ana;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'America/Sao_Paulo'));
        FilamentTimezone::set('America/Sao_Paulo');

        $this->company = $this->tenant('instaladora', ['frota' => [], 'ponto' => [], 'campo' => []]);
        $this->owner = $this->signIn($this->company);

        $this->hr = User::factory()->memberOf($this->company)->create(['name' => 'Rita RH']);
        app(AccessManager::class)->set($this->hr, 'ponto', 'manager');

        $this->fleetManager = User::factory()->memberOf($this->company)->create(['name' => 'Gil Frota']);
        app(AccessManager::class)->set($this->fleetManager, 'frota', 'manager');

        $this->worker = User::factory()->memberOf($this->company)->create(['name' => 'Ana Souza', 'phone' => '5531988887777']);
        $this->ana = Person::forUser($this->worker);
    }

    protected function item(string $key): EpiItem
    {
        app(EpiService::class)->items();

        return EpiItem::query()->where('key', $key)->sole();
    }

    /** @param  array<int, array{0: string|EpiItem, 1?: int}>  $items */
    protected function deliver(Person $person, array $items, array $data = [], ?User $by = null): EpiDelivery
    {
        return app(EpiService::class)->deliver($person, array_map(fn (array $line): array => [
            'item_id' => ($line[0] instanceof EpiItem ? $line[0] : $this->item($line[0]))->getKey(),
            'quantity' => $line[1] ?? 1,
        ], $items), $data, $by ?? $this->owner);
    }

    protected function sign(EpiDelivery $delivery, bool $own = false): EpiDelivery
    {
        return app(EpiService::class)->sign($delivery, self::SIGNATURE, $delivery->person->name, $own ? $this->worker : $this->owner, $own, '203.0.113.7', 'Mozilla/5.0 (Android 14) Chrome/130');
    }

    /** @return array<int, string> */
    protected function titles(User $user): array
    {
        return $user->notifications()->get()->map(fn (DatabaseNotification $notice): string => $notice->data['title'])->all();
    }

    public function test_ready_items_follow_the_company_country(): void
    {
        $items = app(EpiService::class)->items();

        $this->assertSame(array_keys(EpiTemplates::BRAZIL), $items->pluck('key')->all());
        $gloves = $items->firstWhere('key', 'leather_gloves');
        $this->assertSame('Luva de vaqueta', $gloves->label());
        $this->assertSame('30 dias', $gloves->lifeText());
        $this->assertSame('epi', $gloves->kind->value);
        $this->assertFalse($gloves->reusable);
        $ladder = $items->firstWhere('key', 'ladder');
        $this->assertSame('tool', $ladder->kind->value);
        $this->assertTrue($ladder->reusable);
        $this->assertNull($ladder->lifeText());
        $this->assertSame('Cinto paraquedista com talabarte (NR-35)', $items->firstWhere('key', 'harness')->label());
        $this->assertSame(0, EpiTemplates::ensure());

        // Another country: a generic list, nothing of the Brazilian law; the standard text has no NR-6.
        $other = $this->tenant('lisboa', ['ponto' => []], ['country' => 'PT']);
        $this->signIn($other);
        $this->assertSame(array_keys(EpiTemplates::OTHER_COUNTRIES), app(EpiService::class)->items()->pluck('key')->all());
        $this->assertStringNotContainsString('NR-6', app(EpiService::class)->terms());
    }

    public function test_stock_goes_down_and_up_and_may_go_negative(): void
    {
        $gloves = $this->item('leather_gloves');
        $ladder = $this->item('ladder');
        $service = app(EpiService::class);

        // Without stock control nothing moves; the first entry starts it.
        $delivery = $this->deliver($this->ana, [[$gloves, 2]]);
        $this->assertNull($gloves->fresh()->stock);
        $service->addStock($gloves->fresh(), 10);
        $this->assertSame(10, $gloves->fresh()->stock);

        // Deliveries take from the stock; it may go below zero (never blocks), with a warning.
        $service->addStock($ladder, 1);
        $second = $this->deliver($this->ana, [[$gloves, 3], [$ladder, 2]]);
        $this->assertSame(7, $gloves->fresh()->stock);
        $this->assertSame(-1, $ladder->fresh()->stock);
        $this->assertSame(['O estoque de Escada ficou em -1.'], $service->negativeStock($second));

        // Returns: a reusable item goes back; a worn glove is discarded.
        $ladderLine = $second->lines->firstWhere('item_id', $ladder->getKey());
        $service->returnLine($ladderLine, ['quantity' => 1, 'reason' => 'finished']);
        $this->assertSame(0, $ladder->fresh()->stock);
        $this->assertSame(ReturnOutcome::Stock, EpiReturn::query()->latest('id')->first()->outcome);
        $glovesLine = $second->lines->firstWhere('item_id', $gloves->getKey());
        $service->returnLine($glovesLine, ['quantity' => 3, 'reason' => 'wear']);
        $this->assertSame(7, $gloves->fresh()->stock);
        $this->assertSame(ReturnOutcome::Discard, EpiReturn::query()->latest('id')->first()->outcome);
        $this->assertSame(0, $glovesLine->fresh()->openQuantity());

        // Never more than what is with the person.
        $this->expectException(EpiException::class);
        $service->returnLine($ladderLine->fresh(), ['quantity' => 5]);
    }

    public function test_canceling_an_unsigned_delivery_gives_the_stock_back(): void
    {
        $helmet = $this->item('helmet');
        app(EpiService::class)->addStock($helmet, 5);
        $delivery = $this->deliver($this->ana, [[$helmet, 2]]);
        $this->assertSame(3, $helmet->fresh()->stock);

        app(EpiService::class)->cancel($delivery);
        $this->assertSame(5, $helmet->fresh()->stock);
        $this->assertSame(0, EpiDelivery::query()->count());
        $this->assertSame(0, EpiDeliveryLine::query()->count());

        // A signed one is never canceled.
        $signed = $this->sign($this->deliver($this->ana, [[$helmet, 1]]));
        $this->expectException(EpiException::class);
        app(EpiService::class)->cancel($signed);
    }

    public function test_useful_life_and_replacement_situation(): void
    {
        $service = app(EpiService::class);
        // Gloves: 30 days; helmet: 24 months; multimeter: no automatic replacement.
        $old = $this->deliver($this->ana, [['leather_gloves'], ['helmet']], ['delivered_on' => '2026-10-05']);
        $this->assertSame('2026-11-04', $old->lines[0]->replace_on->toDateString());
        $this->assertSame('2028-10-05', $old->lines[1]->replace_on->toDateString());
        $this->assertSame(LineStatus::Soon, $old->lines[0]->status());
        $this->assertSame('Trocar em 3 dias', $old->lines[0]->statusText());
        $this->assertSame(LineStatus::Ok, $old->lines[1]->status());

        $older = $this->deliver($this->ana, [['ear_protection'], ['multimeter']], ['delivered_on' => '2026-09-20']);
        $this->assertSame(LineStatus::Due, $older->lines[0]->status());
        $this->assertSame('Troca atrasada 12 dias', $older->lines[0]->statusText());
        $this->assertSame(LineStatus::NoLife, $older->lines[1]->status());

        // The database filters give the same answers.
        $query = fn () => $service->openQuery(['epi', 'tool']);
        $this->assertSame(1, $service->whereStatus($query(), 'due')->count());
        $this->assertSame(1, $service->whereStatus($query(), 'soon')->count());
        $this->assertSame(1, $service->whereStatus($query(), 'ok')->count());
        $this->assertSame(1, $service->whereStatus($query(), 'no_life')->count());
        $counts = $service->counts(['epi', 'tool']);
        $this->assertSame([1, 1, 2], [$counts['due'], $counts['soon'], $counts['unsigned']]);

        // The future is refused; the CA of the item goes with the delivery.
        $harness = $this->item('harness');
        $harness->update(['ca_number' => '12345', 'ca_expires_on' => '2026-11-20']);
        $this->assertSame('CA vence em 19 dias', $harness->caWarning());
        $line = $this->deliver($this->ana, [[$harness]])->lines[0];
        $this->assertSame('12345', $line->ca_number);
        $this->assertSame('Cinto paraquedista com talabarte (NR-35)', $line->item_name);
        $this->expectException(EpiException::class);
        $this->deliver($this->ana, [['helmet']], ['delivered_on' => '2026-11-05']);
    }

    public function test_a_signed_delivery_never_changes(): void
    {
        Storage::fake('local');
        $service = app(EpiService::class);
        $delivery = $this->deliver($this->ana, [['helmet'], ['leather_gloves', 2]]);

        // An empty pad is refused.
        try {
            $service->sign($delivery, self::BLANK, 'Ana Souza', $this->owner);
            $this->fail('An empty signature was accepted.');
        } catch (EpiException) {
            $this->assertFalse($delivery->fresh()->isSigned());
        }

        $this->sign($delivery);
        $delivery->refresh();
        $this->assertTrue($delivery->isSigned());
        $this->assertSame('203.0.113.7', $delivery->signed_ip);
        $this->assertSame('Mozilla/5.0 (Android 14) Chrome/130', $delivery->signed_user_agent);
        $this->assertFalse($delivery->signed_on_own_device);
        $this->assertStringContainsString('NR-6', $delivery->terms);
        $this->assertStringContainsString('Instaladora', $delivery->terms);
        Storage::disk('local')->assertExists($delivery->signature_path);
        $this->assertTrue($service->verify($delivery));
        $this->assertMatchesRegularExpression('/^[0-9A-F]{4}(-[0-9A-F]{4}){3}$/', $service->code($delivery));

        // Nothing of it changes through the system...
        foreach ([
            fn () => $delivery->update(['notes' => 'mudado']),
            fn () => $delivery->delete(),
            fn () => $delivery->lines[0]->update(['quantity' => 5]),
            fn () => $delivery->lines[0]->delete(),
            fn () => $service->sign($delivery, self::SIGNATURE, 'Outro', $this->owner),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('A signed delivery changed.');
            } catch (LogicException|EpiException) {
            }
        }

        // ...a return is its own record (the delivery stays the same)...
        $service->returnLine($delivery->fresh()->lines[0], ['reason' => 'damage']);
        $this->assertTrue($service->verify($delivery->fresh()));

        // ...and a change made behind the system's back shows up.
        DB::table('epi_delivery_lines')->where('id', $delivery->lines[1]->getKey())->update(['quantity' => 9]);
        $this->assertFalse($service->verify($delivery->fresh()));
    }

    public function test_the_manager_delivers_and_the_person_signs_on_the_spot(): void
    {
        Storage::fake('local');
        $helmet = $this->item('helmet');

        $page = Livewire::withQueryParams(['pessoa' => $this->ana->getKey()])
            ->test(PersonEpiPage::class)
            ->assertSee(__('epi.person.empty'))
            ->callAction('newDelivery', [
                'lines' => [['item_id' => $helmet->getKey(), 'quantity' => 1]],
                'delivered_on' => '2026-11-01',
                'reason' => 'first',
                'sign_mode' => 'now',
            ])
            ->assertHasNoActionErrors()
            ->assertActionMounted('signDelivery');

        $delivery = EpiDelivery::query()->sole();
        $this->assertFalse($delivery->isSigned());

        // Without drawing, the form asks for the signature.
        $page->setActionData(['signer_name' => 'Ana Souza', 'signature' => null])
            ->callMountedAction()
            ->assertHasActionErrors(['signature' => 'required']);

        $page->setActionData(['signer_name' => 'Ana Souza', 'signature' => self::SIGNATURE])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $delivery->refresh();
        $this->assertTrue($delivery->isSigned());
        $this->assertSame($this->owner->getKey(), $delivery->signed_by);
        $this->assertFalse($delivery->signed_on_own_device);

        Livewire::withQueryParams(['pessoa' => $this->ana->getKey()])
            ->test(PersonEpiPage::class)
            ->assertSee('Capacete com jugular')
            ->assertSee(__('epi.sign.signed'))
            ->assertTableActionHidden('sign', $delivery->lines[0])
            ->assertActionVisible('record');
    }

    public function test_the_person_signs_later_on_their_own_phone(): void
    {
        Storage::fake('local');

        Livewire::withQueryParams(['pessoa' => $this->ana->getKey()])
            ->test(PersonEpiPage::class)
            ->callAction('newDelivery', [
                'lines' => [['item_id' => $this->item('leather_gloves')->getKey(), 'quantity' => 2]],
                'delivered_on' => '2026-11-01',
                'reason' => 'first',
                'sign_mode' => 'later',
            ])
            ->assertHasNoActionErrors()
            ->assertActionNotMounted('signDelivery');

        // The person gets a warning in the bell, sees it on the start page and in "My deliveries".
        $this->assertSame(['Assine a entrega de EPI e ferramentas'], $this->titles($this->worker));
        $line = EpiDeliveryLine::query()->sole();

        $this->actingAs($this->worker);
        $this->assertTrue(MyEpiPage::hasDeliveries());
        $this->get($this->company->url('/'))->assertOk()->assertSee('Você tem 1 entrega de EPI e ferramentas para assinar');
        $this->get($this->company->url('/minhas-entregas'))->assertOk()->assertSee('Luva de vaqueta');
        $this->get($this->company->url('/empresa/epi'))->assertForbidden();

        Livewire::test(MyEpiPage::class)
            ->assertSee('Você tem 1 entrega para assinar')
            ->assertTableActionVisible('sign', $line)
            ->assertTableActionDoesNotExist('return')
            ->callTableAction('sign', $line, ['signer_name' => 'Ana Souza', 'signature' => self::SIGNATURE])
            ->assertHasNoTableActionErrors();

        $delivery = $line->delivery->fresh();
        $this->assertTrue($delivery->isSigned());
        $this->assertTrue($delivery->signed_on_own_device);
        $this->assertSame($this->worker->getKey(), $delivery->signed_by);
        $this->assertNotNull($delivery->signed_ip);

        // The person never signs someone else's delivery.
        $other = Person::query()->create(['name' => 'Beto Lima']);
        $foreign = $this->deliver($other, [['helmet']]);
        Livewire::test(MyEpiPage::class)->assertCanNotSeeTableRecords($foreign->lines);
        $this->assertFalse(app(EpiService::class)->canSeeDelivery($this->worker, $foreign));
    }

    public function test_a_person_without_login_signs_only_on_the_spot(): void
    {
        $carlos = Person::query()->create(['name' => 'Carlos Sem Login']);
        $this->assertFalse(app(EpiService::class)->requestSignature($this->deliver($carlos, [['helmet']])));

        // "Later" cannot be chosen without a login (the signature is collected on the spot).
        Livewire::withQueryParams(['pessoa' => $carlos->getKey()])
            ->test(PersonEpiPage::class)
            ->callAction('newDelivery', [
                'lines' => [['item_id' => $this->item('helmet')->getKey(), 'quantity' => 1]],
                'delivered_on' => '2026-11-01',
                'reason' => 'first',
                'sign_mode' => 'later',
            ])
            ->assertHasActionErrors(['sign_mode']);
    }

    public function test_replacement_returns_the_old_one_and_delivers_a_new_one(): void
    {
        Storage::fake('local');
        $boots40 = $this->item('safety_boots');
        $boots40->update(['size' => '40', 'stock' => 3]);
        $boots42 = EpiItem::query()->create(['name' => 'Botina de segurança', 'kind' => 'epi', 'size' => '42', 'stock' => 2, 'life_amount' => 6, 'life_unit' => 'months']);
        $first = $this->sign($this->deliver($this->ana, [[$boots40]], ['delivered_on' => '2026-05-01']));

        Livewire::withQueryParams(['pessoa' => $this->ana->getKey()])
            ->test(PersonEpiPage::class)
            ->callTableAction('exchange', $first->lines[0], [
                'item_id' => $boots42->getKey(), 'quantity' => 1, 'delivered_on' => '2026-11-01',
                'reason' => 'wear', 'outcome' => 'discard', 'sign_mode' => 'now',
            ])
            ->assertHasNoTableActionErrors()
            ->assertActionMounted('signDelivery');

        $this->assertSame(0, $first->lines[0]->fresh()->openQuantity());
        $new = EpiDelivery::query()->latest('id')->first();
        $this->assertSame(DeliveryReason::Wear, $new->reason);
        $this->assertSame('42', $new->lines[0]->size);
        $this->assertSame('2027-05-01', $new->lines[0]->replace_on->toDateString());
        $this->assertSame(2, $boots40->fresh()->stock);
        $this->assertSame(1, $boots42->fresh()->stock);
    }

    public function test_frota_and_campo_managers_handle_only_tools(): void
    {
        $service = app(EpiService::class);
        $technician = User::factory()->memberOf($this->company)->create(['name' => 'Tito Técnico']);
        app(AccessManager::class)->set($technician, 'campo', 'technician');

        $this->assertSame(['epi', 'tool'], $service->kindsFor($this->owner));
        $this->assertSame(['epi', 'tool'], $service->kindsFor($this->hr));
        $this->assertSame(['tool'], $service->kindsFor($this->fleetManager));
        $this->assertSame([], $service->kindsFor($technician));

        $helmet = $this->deliver($this->ana, [['helmet']]);
        $drill = $this->deliver($this->ana, [['drill']]);

        // The fleet manager sees and hands out tools only; never PPE, never the NR-6 record.
        $this->actingAs($this->fleetManager);
        $this->get($this->company->url('/empresa/epi'))->assertOk();
        Livewire::test(EpiPage::class)
            ->assertCanSeeTableRecords($drill->lines)
            ->assertCanNotSeeTableRecords($helmet->lines)
            ->assertActionHidden('terms');
        Livewire::test(EpiItemsPage::class)
            ->assertSee('Furadeira')
            ->assertDontSee('Capacete com jugular');
        Livewire::withQueryParams(['pessoa' => $this->ana->getKey()])
            ->test(PersonEpiPage::class)
            ->assertActionHidden('record');
        $this->assertFalse($service->canSeeDelivery($this->fleetManager, $helmet));

        try {
            $service->deliver($this->ana, [['item_id' => $this->item('helmet')->getKey(), 'quantity' => 1]], [], $this->fleetManager, $service->kindsFor($this->fleetManager));
            $this->fail('A tool manager delivered PPE.');
        } catch (EpiException $exception) {
            $this->assertSame(__('epi.errors.kind_not_allowed'), $exception->getMessage());
        }

        Livewire::test(PeoplePage::class)->assertTableActionVisible('epi', $this->ana);

        // HR sees everything; a technician nothing (only their own deliveries).
        $this->actingAs($this->hr);
        Livewire::test(EpiPage::class)->assertCanSeeTableRecords($helmet->lines->merge($drill->lines));
        $this->actingAs($technician);
        $this->get($this->company->url('/empresa/epi'))->assertForbidden();
        $this->get($this->company->url('/empresa/epi/itens'))->assertForbidden();
        $this->get($this->company->url('/pessoas/epi?pessoa='.$this->ana->getKey()))->assertForbidden();
    }

    public function test_companies_never_see_each_others_ppe(): void
    {
        Storage::fake('local');
        $delivery = $this->sign($this->deliver($this->ana, [['helmet']]));

        $other = $this->tenant('padaria', ['ponto' => []]);
        $this->signIn($other);

        $this->assertSame(0, EpiDelivery::query()->count());
        $this->assertSame(0, EpiDeliveryLine::query()->count());
        $this->assertNull(EpiItem::query()->find($delivery->lines[0]->item_id));
        $this->get($other->url('/pessoas/epi?pessoa='.$this->ana->getKey()))->assertNotFound();
        Livewire::test(EpiPage::class)->assertCanNotSeeTableRecords($delivery->lines)->assertSee(__('epi.empty'));
        $this->assertSame(0, app(EpiService::class)->scan());
        $this->assertSame(['due' => 0, 'soon' => 0, 'unsigned' => 0, 'late' => 0, 'ca' => 0, 'ca_expired' => 0, 'low' => 0], app(EpiService::class)->counts(['epi', 'tool']));
    }

    public function test_the_record_in_pdf(): void
    {
        Storage::fake('local');
        $harness = $this->item('harness');
        $harness->update(['ca_number' => '12345', 'ca_expires_on' => '2027-03-01']);
        $delivery = $this->sign($this->deliver($this->ana, [[$harness], ['leather_gloves', 2]]));
        app(EpiService::class)->returnLine($delivery->lines[1], ['quantity' => 1, 'reason' => 'loss']);
        $this->deliver($this->ana, [['helmet']]);

        $record = app(EpiRecord::class);
        $data = $record->data($this->ana);
        $this->assertCount(2, $data['deliveries']);
        $this->assertTrue($data['brazil']);
        $this->assertNotNull($data['signatures'][$delivery->getKey()]);
        $html = view('epi.record-pdf', $data)->render();
        $this->assertStringContainsString('Ficha de controle de EPI (NR-6)', $html);
        $this->assertStringContainsString('12345', $html);
        $this->assertStringContainsString('Perdido', $html);
        $this->assertStringContainsString(__('epi.sign.pending'), $html);
        $this->assertStringStartsWith('%PDF', $record->pdf($this->ana));
        $this->assertSame('ficha-epi-ana-souza.pdf', $record->fileName($this->ana));

        Livewire::withQueryParams(['pessoa' => $this->ana->getKey()])
            ->test(PersonEpiPage::class)
            ->callAction('record')
            ->assertFileDownloaded('ficha-epi-ana-souza.pdf');

        // The person downloads their own record too.
        $this->actingAs($this->worker);
        Livewire::test(MyEpiPage::class)->callAction('record')->assertFileDownloaded('ficha-epi-ana-souza.pdf');
    }

    public function test_spreadsheet_of_what_is_with_whom(): void
    {
        $team = Team::query()->create(['name' => 'Instalação']);
        $this->ana->update(['team_id' => $team->getKey()]);
        $this->item('leather_gloves')->update(['unit_cost_cents' => 1890]);
        $this->deliver($this->ana, [['leather_gloves', 2]], ['delivered_on' => '2026-09-20']);

        $path = app(EpiExporter::class)->export(app(EpiService::class)->openQuery(['epi', 'tool'])->with('delivery.person.team')->get());
        $reader = new Reader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }

        $reader->close();

        $this->assertSame('Pessoa', $rows[0][0]);
        $this->assertSame(['Ana Souza', 'Instalação', 'Luva de vaqueta', 'EPI'], array_slice($rows[1], 0, 4));
        $this->assertSame(2, $rows[1][7]);
        $this->assertSame('2026-10-20', $rows[1][10]->format('Y-m-d'));
        $this->assertSame('Troca atrasada 12 dias', $rows[1][11]);
        $this->assertSame('Esperando assinatura', $rows[1][12]);
        $this->assertEquals(18.9, $rows[1][13]);

        Livewire::test(EpiPage::class)
            ->callAction('export')
            ->assertFileDownloaded('epi-e-ferramentas-2026-11-01.xlsx');
    }

    public function test_the_bell_warns_once_per_milestone(): void
    {
        $service = app(EpiService::class);
        // Gloves (30 days) delivered on 08/10: replace on 07/11, warned 7 days before (31/10) and on the day.
        $this->sign($this->deliver($this->ana, [['leather_gloves']], ['delivered_on' => '2026-10-08']));

        $this->assertSame(1, $service->scan());
        $this->assertSame(0, $service->scan());
        $this->assertSame(['Ana Souza: hora de trocar 1 item(ns)'], $this->titles($this->owner));
        $this->assertSame(['Ana Souza: hora de trocar 1 item(ns)'], $this->titles($this->hr));
        $this->assertSame([], $this->titles($this->fleetManager));
        $this->assertSame([], $this->titles($this->worker));

        $this->travelTo(CarbonImmutable::parse('2026-11-07 12:00:00', 'America/Sao_Paulo'));
        $this->assertSame(1, $service->scan());
        $this->travelTo(CarbonImmutable::parse('2026-11-10 12:00:00', 'America/Sao_Paulo'));
        $this->assertSame(0, $service->scan());
        $this->assertCount(2, $this->titles($this->owner));
    }

    public function test_ca_signatures_and_low_stock_warnings(): void
    {
        $service = app(EpiService::class);
        $harness = $this->item('harness');
        $harness->update(['ca_number' => '12345', 'ca_expires_on' => '2026-11-25']);
        $drill = $this->item('drill');
        $drill->update(['stock' => 3, 'min_stock' => 2]);

        // CA: 30 days before (start); a delivery waiting since today is not late yet.
        $this->deliver($this->ana, [[$drill, 1]]);
        $this->assertSame(2, $service->scan());
        $this->assertSame(['CA 12345 (Cinto paraquedista com talabarte (NR-35)) vence em 24 dias', 'Estoque baixo: Furadeira'], $this->titles($this->hr));
        // (the low stock above: the delivery took the drill to 2 = the minimum.)
        $this->assertSame(['Estoque baixo: Furadeira'], $this->titles($this->fleetManager));
        $this->assertSame(0, $service->scan());

        // Three days later: the delivery is late for the signature (managers and the person).
        $this->travelTo(CarbonImmutable::parse('2026-11-04 12:00:00', 'America/Sao_Paulo'));
        $this->assertSame(1, $service->scan());
        $this->assertContains('Ana Souza ainda não assinou a entrega de 01/11/2026', $this->titles($this->fleetManager));
        $this->assertSame(['Assine a entrega de EPI e ferramentas'], $this->titles($this->worker));

        // Low stock warns once; after the restock and a new drop, again.
        $this->assertSame(0, $service->scan());
        $service->addStock($drill->fresh(), 5);
        $this->assertNull($drill->fresh()->low_stock_notified_at);
        $this->deliver($this->ana, [[$drill, 5]]);
        $this->assertSame(1, $service->scan());

        // CA 7 days before and on the day.
        $this->travelTo(CarbonImmutable::parse('2026-11-19 12:00:00', 'America/Sao_Paulo'));
        $this->assertSame(2, $service->scan()); // the CA (7 days) and the second delivery, late for the signature
        $this->travelTo(CarbonImmutable::parse('2026-11-26 12:00:00', 'America/Sao_Paulo'));
        $this->assertSame(1, $service->scan());
        $this->assertContains('CA 12345 (Cinto paraquedista com talabarte (NR-35)) está vencido', $this->titles($this->owner));
        $this->assertSame(0, $service->scan());
    }

    public function test_the_daily_routine_and_the_attention_box(): void
    {
        $this->deliver($this->ana, [['ear_protection']], ['delivered_on' => '2026-09-01']);
        $this->item('helmet')->update(['ca_number' => '999', 'ca_expires_on' => '2026-10-01']);

        $this->artisan('kiban:epi')->assertSuccessful();
        $this->artisan('kiban:epi')->assertSuccessful();
        $this->assertCount(2, $this->titles($this->hr));

        $this->get($this->company->url('/'))->assertOk()->assertSee('EPI e ferramentas: 1 troca vencida · 1 CA vencido');
        $this->actingAs($this->fleetManager);
        $this->get($this->company->url('/'))->assertOk()->assertDontSee('1 troca vencida');
    }

    public function test_the_campo_warns_about_the_ppe_the_service_needs(): void
    {
        FieldSetting::current()->update(['business' => 'isp', 'configured_at' => now()]);
        app(FieldTemplates::class)->apply('isp');
        $technician = User::factory()->memberOf($this->company)->create(['name' => 'Tito Técnico']);
        app(AccessManager::class)->set($technician, 'campo', FieldRole::Technician->value);
        $this->coreMember($this->company, $technician);
        $tito = Person::forUser($technician);

        $harness40 = $this->item('harness');
        $type = ServiceType::query()->where('name', 'Visita técnica')->sole();
        $type->update(['required_epi' => [(string) $harness40->getKey()]]);
        $bridge = app(FieldEpi::class);

        // Without the harness: a warning when scheduling (never blocks) and on the phone.
        $this->assertSame(['Cinto paraquedista com talabarte (NR-35)'], $bridge->missing($technician->getKey(), $type));
        $this->assertSame('Atenção: sem EPI registrado — Cinto paraquedista com talabarte (NR-35). O agendamento continua permitido.', $bridge->warning($technician->getKey(), $type->getKey()));

        $customer = Customer::create(['name' => 'Maria da Silva', 'phone' => '5531988887777']);
        $customer->sites()->create(['street' => 'Rua A', 'number' => '1', 'city' => 'Belo Horizonte', 'latitude' => -19.92, 'longitude' => -43.94, 'is_main' => true]);
        $order = app(WorkOrderService::class)->open([
            'customer_id' => $customer->getKey(), 'service_type_id' => $type->getKey(),
            'technician_id' => $technician->getKey(), 'scheduled_at' => '2026-11-01 15:00:00',
        ], $this->owner);
        $this->assertSame($technician->getKey(), $order->technician_id);

        $this->actingAs($technician);
        $this->get(ExecuteOrderPage::getUrl(['order' => $order->getKey()]))->assertOk()
            ->assertSee('Este serviço pede EPI que não está registrado com você: Cinto paraquedista com talabarte (NR-35).');

        // With a harness of another size (same name), nothing to warn.
        $this->actingAs($this->owner);
        $other = EpiItem::query()->create(['name' => 'Cinto paraquedista com talabarte (NR-35)', 'kind' => 'epi', 'size' => 'G']);
        $this->deliver($tito, [[$other]]);
        $this->assertSame([], $bridge->missing($technician->getKey(), $type));
        $this->assertNull($bridge->warning($technician->getKey(), $type));

        // Returned: missing again. A type without needs never warns.
        app(EpiService::class)->returnLine(EpiDeliveryLine::query()->latest('id')->first(), ['reason' => 'finished']);
        $this->assertNotNull($bridge->warning($technician->getKey(), $type));
        $this->assertNull($bridge->warning($technician->getKey(), ServiceType::query()->where('name', '!=', 'Visita técnica')->first()));

        // The new connection is in the catalog for the Campo office and HR, with its texts.
        $catalog = app(ConnectionCatalog::class);
        $this->assertArrayHasKey('epi_field', $catalog->active());
        $this->assertArrayHasKey('epi_field', $catalog->relevant($this->hr));
        $this->assertArrayNotHasKey('epi_field', $catalog->relevant($technician));
        $this->assertSame('O EPI entregue avisa o Campo', $catalog->get('epi_field')->title());
    }

    public function test_lgpd_copy_and_deleting_the_person(): void
    {
        Storage::fake('local');
        $delivery = $this->sign($this->deliver($this->ana, [['helmet'], ['ladder']]));
        app(EpiService::class)->returnLine($delivery->lines[1], ['reason' => 'finished']);
        $path = $delivery->signature_path;

        $copy = app(EpiService::class)->exportPerson($this->ana);
        $this->assertCount(1, $copy);
        $this->assertSame('Capacete com jugular', $copy[0]['items'][0]['item']);
        $this->assertSame('stock', $copy[0]['items'][1]['returns'][0]['outcome']);
        $this->assertSame('203.0.113.7', $copy[0]['signed_ip']);
        $this->assertTrue($copy[0]['with_signature_image']);
        $this->assertArrayNotHasKey('signature_path', $copy[0]);

        // "Download data (LGPD)" in People brings the PPE part.
        Livewire::test(PeoplePage::class)
            ->callTableAction('exportData', $this->ana)
            ->assertFileDownloaded();

        // When the system deletes the person's data, deliveries, returns and signatures go with it.
        $this->ana->delete();
        $this->assertSame(0, EpiDelivery::query()->count());
        $this->assertSame(0, EpiDeliveryLine::query()->count());
        $this->assertSame(0, EpiReturn::query()->count());
        Storage::disk('local')->assertMissing($path);
        $this->assertFalse(EpiDelivery::$purging);
    }

    public function test_the_company_text_and_the_items_screen(): void
    {
        Livewire::test(EpiPage::class)
            ->assertSee(__('epi.empty'))
            ->callAction('terms', ['terms' => 'Recebi os itens e vou cuidar bem deles.'])
            ->assertHasNoActionErrors();
        $this->assertSame('Recebi os itens e vou cuidar bem deles.', EpiSetting::current()->terms);
        $this->assertSame('Recebi os itens e vou cuidar bem deles.', app(EpiService::class)->terms());

        // Back to the standard one: kept as "standard" (follows the language of who signs).
        Livewire::test(EpiPage::class)->callAction('terms', ['terms' => app(EpiService::class)->standardTerms()]);
        $this->assertNull(EpiSetting::current()->terms);

        // A new item, a stock entry and the CA warning on the list.
        Livewire::test(EpiItemsPage::class)
            ->callAction('create', ['name' => 'Luva nitrílica', 'kind' => 'epi', 'size' => 'M', 'ca_number' => '55555', 'ca_expires_on' => '2026-11-10', 'life_amount' => 15, 'life_unit' => 'days', 'unit_cost' => '7.50', 'min_stock' => 4])
            ->assertHasNoActionErrors();
        $item = EpiItem::query()->where('name', 'Luva nitrílica')->sole();
        $this->assertSame(750, $item->unit_cost_cents);
        $this->assertNull($item->stock);

        Livewire::test(EpiItemsPage::class)
            ->callTableAction('addStock', $item, ['quantity' => 3])
            ->assertHasNoTableActionErrors()
            ->assertSee('CA vence em 9 dias');
        $this->assertSame(3, $item->fresh()->stock);
        $this->assertTrue($item->fresh()->isLowStock());

        Livewire::withQueryParams(['filtro' => 'low'])->test(EpiItemsPage::class)
            ->assertCanSeeTableRecords([$item])
            ->assertCanNotSeeTableRecords([$this->item('helmet')]);
    }
}
