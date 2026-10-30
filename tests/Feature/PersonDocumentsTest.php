<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Filament\Pages\DocumentsPage;
use App\Filament\Pages\DocumentTypesPage;
use App\Filament\Pages\MyDocumentsPage;
use App\Filament\Pages\PeoplePage;
use App\Filament\Pages\PersonDocumentsPage;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Fleet\Models\Driver;
use App\People\Documents\DocumentExporter;
use App\People\Documents\DocumentStatus;
use App\People\Documents\DocumentTemplates;
use App\People\Documents\DocumentType;
use App\People\Documents\PersonDocument;
use App\People\Documents\PersonDocumentService;
use App\People\Person;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

/**
 * Documents and expiry dates of the people (People screen, every system): ready types by country, the expiry from
 * the validity and the situation, renewing, the bell once per milestone, who sees the documents and the files
 * (never the Frota/Campo managers), separation between companies, the spreadsheet and the links with Frota and Campo.
 */
class PersonDocumentsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected User $hr;

    protected User $fleetManager;

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
    }

    protected function type(string $key): DocumentType
    {
        app(PersonDocumentService::class)->types();

        return DocumentType::query()->where('key', $key)->sole();
    }

    /** @param  array<string, mixed>  $data */
    protected function document(Person $person, string $key, array $data = []): PersonDocument
    {
        return app(PersonDocumentService::class)->add($person, ['document_type_id' => $this->type($key)->getKey()] + $data, $this->owner);
    }

    /** @return array<int, string> */
    protected function titles(User $user): array
    {
        return $user->notifications()->get()->map(fn (DatabaseNotification $notice): string => $notice->data['title'])->all();
    }

    public function test_ready_types_follow_the_company_country(): void
    {
        $types = app(PersonDocumentService::class)->types();

        $this->assertSame(array_keys(DocumentTemplates::BRAZIL), $types->pluck('key')->all());
        $aso = $types->firstWhere('key', 'aso');
        $this->assertTrue($aso->is_health);
        $this->assertSame(12, $aso->validity_months);
        $this->assertSame(30, $aso->alert_days);
        $this->assertSame(24, $types->firstWhere('key', 'nr10')->validity_months);
        $this->assertSame('campo', $types->firstWhere('key', 'nr35')->module);
        $this->assertSame('frota', $types->firstWhere('key', 'cnh')->module);
        $this->assertNull($types->firstWhere('key', 'cnh')->validity_months);
        $this->assertSame('NR-10 (eletricidade)', $types->firstWhere('key', 'nr10')->label());

        // Created once: opening again changes nothing.
        $this->assertSame(0, DocumentTemplates::ensure());

        // Another country: generic types, nothing of the Brazilian law.
        $other = $this->tenant('lisboa', ['ponto' => []], ['country' => 'PT']);
        $this->signIn($other);
        $keys = app(PersonDocumentService::class)->types()->pluck('key')->all();
        $this->assertSame(['driver_license', 'medical_exam', 'safety_training', 'other'], $keys);
        $this->assertTrue(DocumentType::query()->where('key', 'medical_exam')->sole()->is_health);
    }

    public function test_expiry_from_the_validity_and_the_situation(): void
    {
        $ana = Person::query()->create(['name' => 'Ana Souza']);

        // NR-10: 24 months from the training.
        $nr10 = $this->document($ana, 'nr10', ['issued_on' => '2024-11-20', 'number' => 'T-123']);
        $this->assertSame('2026-11-20', $nr10->expires_on->toDateString());
        $this->assertSame(19, $nr10->daysLeft());
        $this->assertSame(DocumentStatus::Soon, $nr10->status());
        $this->assertSame('Vence em 19 dias', $nr10->statusText());

        // Typed date, no date (does not expire), expired, expires today / tomorrow.
        $this->assertSame(DocumentStatus::Ok, $this->document($ana, 'cnh', ['expires_on' => '2030-05-01'])->status());
        $this->assertSame(DocumentStatus::NoExpiry, $this->document($ana, 'other', ['issued_on' => '2026-01-01'])->status());
        $aso = $this->document($ana, 'aso', ['issued_on' => '2025-10-01']);
        $this->assertSame('2026-10-01', $aso->expires_on->toDateString());
        $this->assertSame(DocumentStatus::Overdue, $aso->status());
        $this->assertSame('Vencido', $aso->statusText());
        $this->assertSame('Vence hoje', $this->document($ana, 'nr35', ['expires_on' => '2026-11-01'])->statusText());
        $this->assertSame('Vence amanhã', $this->document($ana, 'nr33', ['expires_on' => '2026-11-02'])->statusText());

        // The database filter gives the same situations.
        $service = app(PersonDocumentService::class);
        $this->assertSame(['ASO (exame periódico)'], $service->whereStatus($service->currentQuery(), 'overdue')->get()->map(fn ($document) => $document->type->label())->all());
        $this->assertSame(3, $service->whereStatus($service->currentQuery(), 'soon')->count());
        $this->assertSame(1, $service->whereStatus($service->currentQuery(), 'ok')->count());
        $this->assertSame(1, $service->whereStatus($service->currentQuery(), 'no_expiry')->count());
        $this->assertSame(['overdue' => 1, 'soon' => 3], $service->counts());

        // Renewing: the new one is current, the old one stays as history.
        $renewed = $this->document($ana, 'aso', ['issued_on' => '2026-10-28']);
        $this->assertTrue($renewed->isCurrent());
        $this->assertFalse($aso->fresh()->isCurrent());
        $this->assertSame(2, PersonDocument::query()->where('document_type_id', $aso->document_type_id)->count());
        $this->assertSame(['overdue' => 0, 'soon' => 3], $service->counts());

        // Deleting a mistake: the previous one is current again.
        $service->delete($renewed);
        $this->assertTrue($aso->fresh()->isCurrent());
    }

    public function test_the_form_fills_the_expiry_and_keeps_the_history(): void
    {
        $ana = Person::query()->create(['name' => 'Ana Souza']);
        $nr35 = $this->type('nr35');

        Livewire::withQueryParams(['pessoa' => $ana->getKey()])
            ->test(PersonDocumentsPage::class)
            ->assertSee(__('documents.person.empty'))
            ->mountAction('addDocument')
            ->fillForm(['document_type_id' => $nr35->getKey(), 'issued_on' => '2026-03-10'])
            ->assertActionDataSet(['expires_on' => '2028-03-10'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $document = PersonDocument::query()->sole();
        $this->assertSame('2028-03-10', $document->expires_on->toDateString());
        $this->assertSame($this->owner->getKey(), $document->created_by);

        Livewire::withQueryParams(['pessoa' => $ana->getKey()])
            ->test(PersonDocumentsPage::class)
            ->callTableAction('renew', $document, ['issued_on' => '2026-10-30', 'expires_on' => '2028-10-30'])
            ->assertHasNoTableActionErrors()
            ->assertSee(__('documents.status.replaced'));

        $this->assertSame(1, PersonDocument::query()->current()->count());
        $this->assertSame('2028-10-30', PersonDocument::query()->current()->sole()->expires_on->toDateString());

        // The list of the company and the types screen open for the owner.
        Livewire::test(DocumentsPage::class)->assertCanSeeTableRecords(PersonDocument::query()->current()->get())->assertOk();
        Livewire::test(DocumentTypesPage::class)->assertSee('NR-35 (trabalho em altura)');
    }

    public function test_the_bell_warns_once_per_milestone(): void
    {
        $joao = Person::query()->create(['name' => 'João Lima']);
        $this->document($joao, 'nr10', ['expires_on' => '2026-12-01']);
        $service = app(PersonDocumentService::class);

        // 30 days before (the type's warning days): owner and HR; never the Frota manager.
        $this->assertSame(1, $service->scan());
        $this->assertSame(0, $service->scan());
        $title = 'NR-10 (eletricidade) de João Lima vence em 30 dias';
        $this->assertSame([$title], $this->titles($this->owner));
        $this->assertSame([$title], $this->titles($this->hr));
        $this->assertSame([], $this->titles($this->fleetManager));

        // Nothing new in between; then 7 days before; then on the day.
        $this->travelTo(CarbonImmutable::parse('2026-11-20 12:00:00', 'America/Sao_Paulo'));
        $this->assertSame(0, $service->scan());
        $this->travelTo(CarbonImmutable::parse('2026-11-24 12:00:00', 'America/Sao_Paulo'));
        $this->assertSame(1, $service->scan());
        $this->travelTo(CarbonImmutable::parse('2026-11-26 12:00:00', 'America/Sao_Paulo'));
        $this->assertSame(0, $service->scan());
        $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00', 'America/Sao_Paulo'));
        $this->assertSame(1, $service->scan());
        $this->travelTo(CarbonImmutable::parse('2026-12-05 12:00:00', 'America/Sao_Paulo'));
        $this->assertSame(0, $service->scan());

        $this->assertSame([
            'NR-10 (eletricidade) de João Lima vence hoje',
            'NR-10 (eletricidade) de João Lima vence em 7 dias',
            $title,
        ], $this->titles($this->owner));
    }

    public function test_the_daily_routine_and_the_attention_box(): void
    {
        $joao = Person::query()->create(['name' => 'João Lima']);
        $this->document($joao, 'nr10', ['expires_on' => '2026-10-31']);
        // Added close to the expiry: only the most advanced warning (7 days), not the 30 days one.
        $this->document($joao, 'aso', ['expires_on' => '2026-11-04']);

        $this->artisan('kiban:documents')->assertSuccessful();
        $this->artisan('kiban:documents')->assertSuccessful();

        $this->assertEqualsCanonicalizing([
            'NR-10 (eletricidade) de João Lima está vencido',
            'ASO (exame periódico) de João Lima vence em 3 dias',
        ], $this->titles($this->hr));

        // In the "Attention" box of the start page (every system) for who takes care of the documents.
        $this->get($this->company->url('/'))->assertOk()->assertSee('Documentos da equipe: 1 vencido · 1 vencendo');
        $this->actingAs($this->fleetManager);
        $this->get($this->company->url('/'))->assertOk()->assertDontSee('Documentos da equipe');
    }

    public function test_only_owner_admin_and_hr_see_documents_and_files(): void
    {
        $worker = User::factory()->memberOf($this->company)->create(['name' => 'Caio Técnico']);
        $person = Person::forUser($worker);
        Storage::disk('local')->put('tenants/'.$this->company->id.'/people/documents/aso.pdf', '%PDF-1.4 test');
        $aso = $this->document($person, 'aso', ['issued_on' => '2026-06-01', 'file_path' => 'tenants/'.$this->company->id.'/people/documents/aso.pdf']);
        $url = $this->company->url('/documentos/'.$aso->getKey().'/arquivo');
        $service = app(PersonDocumentService::class);

        // Owner and HR open it.
        $this->get($url)->assertOk();
        $this->actingAs($this->hr);
        $this->get($url)->assertOk();
        $this->get($this->company->url('/empresa/vencimentos'))->assertOk();

        // The Frota manager manages people of the Frota, but never sees the documents nor the medical exam's file.
        $this->actingAs($this->fleetManager);
        $this->assertFalse($service->canManage($this->fleetManager));
        $this->assertFalse($service->canSeeHealth($this->fleetManager));
        $this->get($url)->assertForbidden();
        $this->get($this->company->url('/empresa/vencimentos'))->assertForbidden();
        $this->get($this->company->url('/pessoas/documentos?pessoa='.$person->getKey()))->assertForbidden();
        $this->get($this->company->url('/pessoas'))->assertOk();
        Livewire::test(PeoplePage::class)
            ->assertTableActionHidden('documents', $person)
            ->assertTableColumnHidden('documents_alert');

        // The person sees their own documents (read only) and opens the file.
        $this->actingAs($worker);
        $this->get($url)->assertOk();
        $this->get($this->company->url('/meus-documentos'))->assertOk()->assertSee('ASO (exame periódico)');
        $this->assertTrue(MyDocumentsPage::hasDocuments());
        $this->get($this->company->url('/empresa/vencimentos'))->assertForbidden();

        // LGPD copy: health data only for who may see it; no files.
        $this->assertSame([], $service->exportPerson($person, $this->fleetManager));
        $copy = $service->exportPerson($person, $this->owner);
        $this->assertSame('2027-06-01', $copy[0]['expires_on']);
        $this->assertTrue($copy[0]['with_file']);

        // Deleting the person's data deletes the documents and the files.
        $this->actingAs($this->owner);
        $person->delete();
        $this->assertSame(0, PersonDocument::query()->count());
        Storage::disk('local')->assertMissing('tenants/'.$this->company->id.'/people/documents/aso.pdf');
    }

    public function test_companies_never_see_each_others_documents(): void
    {
        $ana = Person::query()->create(['name' => 'Ana Souza']);
        Storage::disk('local')->put('tenants/'.$this->company->id.'/people/documents/cnh.pdf', '%PDF-1.4 test');
        $cnh = $this->document($ana, 'cnh', ['expires_on' => '2026-11-10', 'file_path' => 'tenants/'.$this->company->id.'/people/documents/cnh.pdf']);

        $other = $this->tenant('padaria', ['ponto' => []]);
        $this->signIn($other);

        $this->assertSame(0, PersonDocument::query()->count());
        $this->assertNull(DocumentType::query()->find($cnh->document_type_id));
        $this->get($other->url('/documentos/'.$cnh->getKey().'/arquivo'))->assertNotFound();
        Livewire::test(DocumentsPage::class)->assertCanNotSeeTableRecords([$cnh])->assertSee(__('documents.expiry.empty'));
        $this->assertSame(['overdue' => 0, 'soon' => 0], app(PersonDocumentService::class)->counts());
        $this->assertSame(0, app(PersonDocumentService::class)->scan());
    }

    public function test_spreadsheet_of_the_expiry_dates(): void
    {
        $team = Team::query()->create(['name' => 'Instalação']);
        $ana = Person::query()->create(['name' => 'Ana Souza', 'team_id' => $team->getKey()]);
        $this->document($ana, 'nr10', ['issued_on' => '2024-11-20', 'number' => 'T-123']);
        $this->document($ana, 'aso', ['issued_on' => '2025-10-01']);

        $path = app(DocumentExporter::class)->export(app(PersonDocumentService::class)->currentQuery()->with(['person.team', 'type'])->orderBy('expires_on')->get());
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
        $this->assertSame(['Ana Souza', 'Instalação', 'ASO (exame periódico)'], array_slice($rows[1], 0, 3));
        $this->assertSame('2026-10-01', $rows[1][5]->format('Y-m-d'));
        $this->assertSame('Vencido', $rows[1][6]);
        $this->assertSame(-31, $rows[1][7]);
        $this->assertSame('Sim', $rows[1][8]);
        $this->assertSame('T-123', $rows[2][3]);
        $this->assertSame('Vence em 19 dias', $rows[2][6]);

        // The button downloads the list as it is on the screen.
        Livewire::test(DocumentsPage::class)
            ->callAction('export')
            ->assertFileDownloaded('vencimentos-2026-11-01.xlsx');
    }

    public function test_frota_and_campo_see_who_is_expired(): void
    {
        // Frota: a driver whose license (People) expired; the record of the driver follows the newest license.
        $driverUser = User::factory()->memberOf($this->company)->create(['name' => 'Davi Motorista']);
        $driver = Driver::query()->create(['name' => 'Davi Motorista', 'email' => $driverUser->email]);
        $driver->forceFill(['user_id' => $driverUser->getKey()])->save();
        $this->document(Person::forUser($driverUser), 'cnh', ['number' => '998877', 'expires_on' => '2026-10-15']);
        $this->assertSame('2026-10-15', $driver->fresh()->license_expires_on->toDateString());
        $this->assertSame('998877', $driver->fresh()->license_number);

        // A driver without the license in People: the date on the driver record counts.
        Driver::query()->create(['name' => 'Beto Antigo', 'license_expires_on' => '2026-09-30']);

        $this->actingAs($this->fleetManager);
        $this->get($this->company->url('/'))->assertOk()
            ->assertSee('Motorista Davi Motorista — CNH: venceu em 15/10/2026')
            ->assertSee('Motorista Beto Antigo — CNH: venceu em 30/09/2026');

        // Campo: a technician with NR-10 expired shows in the office's box and when scheduling (never blocks).
        $technician = User::factory()->memberOf($this->company)->create(['name' => 'Tito Técnico']);
        app(AccessManager::class)->set($technician, 'campo', 'technician');
        $this->document(Person::forUser($technician), 'nr10', ['expires_on' => '2026-10-20']);
        $fieldManager = User::factory()->memberOf($this->company)->create(['name' => 'Lia Campo']);
        app(AccessManager::class)->set($fieldManager, 'campo', 'manager');

        $this->actingAs($fieldManager);
        $this->get($this->company->url('/'))->assertOk()->assertSee('Técnico Tito Técnico — NR-10 (eletricidade): venceu em 20/10/2026');
        $this->assertSame('Atenção: NR-10 (eletricidade): venceu em 20/10/2026. O agendamento continua permitido.', OrderResource::technicianWarning($technician->getKey()));
        $this->assertStringContainsString('⚠ NR-10', OrderResource::technicianChoices()[$technician->getKey()]);
        $this->assertNull(OrderResource::technicianWarning($fieldManager->getKey()));

        // The Campo manager does not take care of the documents themselves.
        $this->get($this->company->url('/empresa/vencimentos'))->assertForbidden();
    }
}
