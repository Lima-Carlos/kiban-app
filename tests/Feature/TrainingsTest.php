<?php

namespace Tests\Feature;

use App\Access\AccessManager;
use App\Filament\Pages\DocumentTypesPage;
use App\Filament\Pages\PersonDocumentsPage;
use App\Filament\Pages\TrainingRequirementsPage;
use App\Filament\Pages\TrainingsPage;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\People\Documents\DocumentRequirement;
use App\People\Documents\DocumentType;
use App\People\Documents\PersonDocument;
use App\People\Documents\PersonDocumentService;
use App\People\Documents\TrainingKind;
use App\People\Documents\TrainingRecord;
use App\People\Documents\TrainingService;
use App\People\Person;
use Carbon\CarbonImmutable;
use Filament\Support\Facades\FilamentTimezone;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Courses and trainings (part of the people's documents): the 60 days warning of the courses, who needs which
 * course and who is missing it, the matrix, one class for many people (renewal included), the renewals of the
 * next 90 days, the bell / "Attention" / Campo for the missing ones, the proof in PDF (who may get it) and the
 * separation between companies.
 */
class TrainingsTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $company;

    protected User $owner;

    protected User $hr;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-01 12:00:00', 'America/Sao_Paulo'));
        FilamentTimezone::set('America/Sao_Paulo');

        $this->company = $this->tenant('instaladora', ['frota' => [], 'ponto' => [], 'campo' => []]);
        $this->owner = $this->signIn($this->company);

        $this->hr = User::factory()->memberOf($this->company)->create(['name' => 'Rita RH']);
        app(AccessManager::class)->set($this->hr, 'ponto', 'manager');
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

    protected function technician(string $name): User
    {
        $user = User::factory()->memberOf($this->company)->create(['name' => $name]);
        app(AccessManager::class)->set($user, 'campo', 'technician');

        return $user;
    }

    protected function trainings(): TrainingService
    {
        return app(TrainingService::class);
    }

    /** @return array<int, string> */
    protected function titles(User $user): array
    {
        return $user->notifications()->get()->map(fn (DatabaseNotification $notice): string => $notice->data['title'])->all();
    }

    public function test_courses_are_marked_and_warn_60_days_ahead(): void
    {
        $types = app(PersonDocumentService::class)->types()->keyBy('key');

        foreach (['nr10', 'nr35', 'nr33', 'nr11', 'nr12', 'nr06', 'training'] as $key) {
            $this->assertTrue($types[$key]->is_training, $key);
            $this->assertSame(60, $types[$key]->alert_days, $key);
        }

        // The driver's license and the medical exam stay as they were.
        $this->assertFalse($types['cnh']->is_training);
        $this->assertSame(30, $types['cnh']->alert_days);
        $this->assertSame(30, $types['aso']->alert_days);

        // The warning starts 60 days before (the existing milestones).
        $ana = Person::query()->create(['name' => 'Ana Souza']);
        $nr10 = $this->document($ana, 'nr10', ['expires_on' => '2026-12-20']);
        $this->assertSame('start', app(PersonDocumentService::class)->milestone($nr10));

        // Other countries: the generic safety training too.
        $other = $this->tenant('lisboa', ['ponto' => []], ['country' => 'PT']);
        $this->signIn($other);
        $training = app(PersonDocumentService::class)->types()->firstWhere('key', 'safety_training');
        $this->assertTrue($training->is_training);
        $this->assertSame(60, $training->alert_days);
    }

    public function test_existing_companies_get_60_days_only_when_they_kept_the_old_default(): void
    {
        $now = now();
        $row = fn (string $key, int $alert): array => ['tenant_id' => $this->company->id, 'key' => $key, 'validity_months' => null, 'alert_days' => $alert, 'is_health' => false, 'is_training' => false, 'is_active' => true, 'sort' => 0, 'created_at' => $now, 'updated_at' => $now];
        DB::table('person_document_types')->insert([$row('nr10', 30), $row('nr35', 45), $row('cnh', 30), $row('aso', 30)]);

        $migration = require database_path('migrations/2026_11_04_100000_add_trainings_to_person_documents.php');
        $migration->markTrainings();

        $types = DocumentType::query()->get()->keyBy('key');
        $this->assertSame(60, $types['nr10']->alert_days);
        $this->assertTrue($types['nr10']->is_training);
        // The company had chosen 45 days: it stays.
        $this->assertSame(45, $types['nr35']->alert_days);
        $this->assertTrue($types['nr35']->is_training);
        $this->assertSame(30, $types['cnh']->alert_days);
        $this->assertFalse($types['cnh']->is_training);
        $this->assertSame(30, $types['aso']->alert_days);

        // The company can still change it and turn a type into a course.
        Livewire::test(DocumentTypesPage::class)
            ->callTableAction('edit', $types['cnh'], ['name' => 'CNH', 'alert_days' => 30, 'is_training' => true])
            ->assertHasNoTableActionErrors();
        $this->assertTrue($types['cnh']->fresh()->is_training);
    }

    public function test_who_needs_which_course_and_who_is_missing_it(): void
    {
        $installation = Team::query()->create(['name' => 'Instalação']);
        $office = Team::query()->create(['name' => 'Escritório']);
        $ana = Person::query()->create(['name' => 'Ana Souza', 'team_id' => $installation->getKey()]);
        $bruno = Person::query()->create(['name' => 'Bruno Dias', 'team_id' => $installation->getKey()]);
        $carla = Person::query()->create(['name' => 'Carla Mendes', 'team_id' => $office->getKey()]);
        $gone = Person::query()->create(['name' => 'Davi Saiu', 'team_id' => $installation->getKey(), 'is_active' => false]);
        $tito = Person::forUser($this->technician('Tito Técnico'));

        $service = $this->trainings();
        $service->addRequirement(['document_type_id' => $this->type('nr35')->getKey(), 'scope' => DocumentRequirement::TEAM, 'team_id' => $installation->getKey()], $this->owner);
        $service->addRequirement(['document_type_id' => $this->type('nr10')->getKey(), 'scope' => DocumentRequirement::ROLE, 'role' => 'campo:technician']);
        $service->addRequirement(['document_type_id' => $this->type('nr06')->getKey(), 'scope' => DocumentRequirement::ALL]);
        // The same requirement twice is kept once.
        $service->addRequirement(['document_type_id' => $this->type('nr06')->getKey(), 'scope' => DocumentRequirement::ALL]);
        $this->assertSame(3, DocumentRequirement::query()->count());

        // Only courses can be required; a role must exist in the company's systems.
        $this->assertThrows(fn () => $service->addRequirement(['document_type_id' => $this->type('aso')->getKey(), 'scope' => DocumentRequirement::ALL]), ModelNotFoundException::class);
        $this->assertThrows(fn () => $service->addRequirement(['document_type_id' => $this->type('nr10')->getKey(), 'scope' => DocumentRequirement::ROLE, 'role' => 'campo:boss']), \InvalidArgumentException::class);

        // Ana has the NR-35 (expired is not "missing": it is "expired"); Bruno has nothing.
        $this->document($ana, 'nr35', ['expires_on' => '2026-10-01']);
        $this->document($ana, 'nr06', ['issued_on' => '2026-01-10']);

        $missing = app(TrainingService::class)->missing()
            ->map(fn (array $item): string => $item['person']->name.' · '.$item['type']->key)->sort()->values()->all();

        $this->assertSame([
            'Bruno Dias · nr06',
            'Bruno Dias · nr35',
            'Carla Mendes · nr06',
            'Tito Técnico · nr06',
            'Tito Técnico · nr10',
        ], array_values(array_filter($missing, fn (string $line): bool => ! str_starts_with($line, 'Rita') && ! str_contains($line, $this->owner->name))));

        // Inactive people never count; the owner (Campo manager) is not a technician.
        $this->assertNotContains('Davi Saiu · nr35', $missing);
        $service = app(TrainingService::class);
        $this->assertFalse($service->isRequired($gone, $this->type('nr35')));
        $this->assertFalse($service->isRequired(Person::forUser($this->owner), $this->type('nr10')));
        $this->assertTrue($service->isRequired($tito, $this->type('nr10')));

        // The screen of the requirements lists them with the people and who is missing.
        Livewire::test(TrainingRequirementsPage::class)
            ->assertSee('Equipe Instalação')
            ->assertSee('Campo: Técnico')
            ->assertSee('Todas as pessoas')
            ->callAction('create', ['document_type_id' => $this->type('nr33')->getKey(), 'scope' => DocumentRequirement::TEAM, 'team_id' => $office->getKey()])
            ->assertHasNoActionErrors();
        $this->assertTrue(app(TrainingService::class)->isRequired($carla, $this->type('nr33')));

        Livewire::test(TrainingRequirementsPage::class)
            ->callTableAction('delete', DocumentRequirement::query()->where('scope', 'all')->sole());
        $this->assertSame(3, DocumentRequirement::query()->count());
    }

    public function test_the_matrix_shows_each_situation(): void
    {
        $installation = Team::query()->create(['name' => 'Instalação']);
        $ana = Person::query()->create(['name' => 'Ana Souza', 'team_id' => $installation->getKey()]);
        $bruno = Person::query()->create(['name' => 'Bruno Dias', 'team_id' => $installation->getKey()]);
        $carla = Person::query()->create(['name' => 'Carla Mendes']);
        Person::query()->create(['name' => 'Nina Sem Curso']);

        $this->trainings()->addRequirement(['document_type_id' => $this->type('nr35')->getKey(), 'scope' => DocumentRequirement::TEAM, 'team_id' => $installation->getKey()]);
        $this->document($ana, 'nr35', ['issued_on' => '2026-01-10']);           // until 10/01/2028: green
        $this->document($ana, 'nr10', ['expires_on' => '2026-12-01']);          // within 60 days: yellow
        $this->document($carla, 'nr10', ['expires_on' => '2026-10-01']);        // red
        $this->document($carla, 'aso', ['issued_on' => '2026-06-01']);          // not a course: not in the matrix

        $matrix = $this->trainings()->matrix();
        $this->assertSame(['nr10', 'nr35'], $matrix['types']->pluck('key')->sort()->values()->all());
        $rows = $matrix['rows']->keyBy(fn (array $row): string => $row['person']->name);
        // Only who the courses concern.
        $this->assertSame(['Ana Souza', 'Bruno Dias', 'Carla Mendes'], $rows->keys()->all());

        $nr10 = $this->type('nr10')->getKey();
        $nr35 = $this->type('nr35')->getKey();
        $this->assertSame('ok', $rows['Ana Souza']['cells'][$nr35]['status']);
        $this->assertSame('soon', $rows['Ana Souza']['cells'][$nr10]['status']);
        $this->assertSame('missing', $rows['Bruno Dias']['cells'][$nr35]['status']);
        $this->assertSame('none', $rows['Bruno Dias']['cells'][$nr10]['status']);
        $this->assertSame('overdue', $rows['Carla Mendes']['cells'][$nr10]['status']);
        $this->assertSame('none', $rows['Carla Mendes']['cells'][$nr35]['status']);

        // Filter by team.
        $this->assertSame(['Ana Souza', 'Bruno Dias'], $this->trainings()->matrix($installation->getKey())['rows']->map(fn (array $row): string => $row['person']->name)->all());

        // The page (computer and phone markup) and the spreadsheet.
        Livewire::test(TrainingsPage::class)
            ->assertOk()
            ->assertSee('Bruno Dias')
            ->assertSee('até 10/01/2028')
            ->assertSee('venceu 01/10/2026')
            ->assertSee('Falta fazer')
            ->assertDontSee('Nina Sem Curso')
            ->set('team', $installation->getKey())
            ->assertDontSee('Carla Mendes')
            ->callAction('export')
            ->assertFileDownloaded('cursos-2026-11-01.xlsx');
        $this->get($this->company->url('/empresa/cursos'))->assertOk()->assertSee('Cursos e treinamentos');
    }

    public function test_one_class_for_many_people_creates_documents_and_renews(): void
    {
        $ana = Person::query()->create(['name' => 'Ana Souza']);
        $bruno = Person::query()->create(['name' => 'Bruno Dias']);
        $old = $this->document($ana, 'nr35', ['issued_on' => '2024-11-20']);
        $nr35 = $this->type('nr35');
        $file = 'tenants/'.$this->company->id.'/people/documents/certificado.pdf';
        Storage::disk('local')->put($file, '%PDF-1.4 test');

        Livewire::test(TrainingsPage::class)
            ->mountAction('launchClass', ['type' => $nr35->getKey(), 'people' => [$ana->getKey(), $bruno->getKey()]])
            ->assertActionDataSet(['document_type_id' => $nr35->getKey(), 'person_ids' => [$ana->getKey(), $bruno->getKey()], 'expires_on' => '2028-11-01', 'training_kind' => TrainingKind::Initial])
            ->setActionData(['issued_on' => '2026-10-28', 'expires_on' => '2028-10-28', 'provider' => 'Senai', 'workload_hours' => 8, 'training_kind' => 'refresher', 'file_path' => null, 'notes' => 'Turma de outubro'])
            ->callMountedAction()
            ->assertHasNoActionErrors();

        $current = PersonDocument::query()->current()->where('document_type_id', $nr35->getKey())->orderBy('person_id')->get();
        $this->assertCount(2, $current);
        $this->assertSame(['2028-10-28', '2028-10-28'], $current->map(fn ($document) => $document->expires_on->toDateString())->all());
        $this->assertSame('Senai', $current[0]->provider);
        $this->assertSame(8, $current[1]->workload_hours);
        $this->assertSame(TrainingKind::Refresher, $current[0]->training_kind);
        $this->assertSame('Reciclagem · Senai · 8 h', $current[0]->trainingDetails());
        // Ana's old NR-35 became history (renewal).
        $this->assertFalse($old->fresh()->isCurrent());

        // With the certificate: each person keeps their own copy.
        $documents = $this->trainings()->launchClass([
            'document_type_id' => $this->type('nr10')->getKey(), 'issued_on' => '2026-10-30', 'file_path' => $file, 'training_kind' => 'initial',
        ], [$ana->getKey(), $bruno->getKey()], $this->owner);
        $paths = $documents->pluck('file_path')->all();
        $this->assertCount(2, array_unique($paths));
        $this->assertSame('2028-10-30', $documents[0]->expires_on->toDateString());
        Storage::disk('local')->assertExists($paths);
        app(PersonDocumentService::class)->delete($documents[0]);
        Storage::disk('local')->assertMissing($paths[0]);
        Storage::disk('local')->assertExists($paths[1]);

        // The course details show in the person's list and in "My documents".
        Livewire::withQueryParams(['pessoa' => $ana->getKey()])->test(PersonDocumentsPage::class)
            ->assertSee('Reciclagem · Senai · 8 h')
            ->assertSee(__('documents.status.replaced'));

        // The prefill says "refresher" when everyone already has the course.
        Livewire::test(TrainingsPage::class)
            ->mountAction('launchClass', ['type' => $nr35->getKey(), 'people' => [$ana->getKey(), $bruno->getKey()]])
            ->assertActionDataSet(['training_kind' => TrainingKind::Refresher]);
    }

    public function test_upcoming_renewals_group_people_by_course(): void
    {
        $team = Team::query()->create(['name' => 'Instalação']);
        $ana = Person::query()->create(['name' => 'Ana Souza', 'team_id' => $team->getKey()]);
        $bruno = Person::query()->create(['name' => 'Bruno Dias', 'team_id' => $team->getKey()]);
        $carla = Person::query()->create(['name' => 'Carla Mendes', 'team_id' => $team->getKey()]);
        $this->trainings()->addRequirement(['document_type_id' => $this->type('nr35')->getKey(), 'scope' => DocumentRequirement::TEAM, 'team_id' => $team->getKey()]);

        $this->document($ana, 'nr35', ['expires_on' => '2027-01-20']);   // within 90 days
        $this->document($carla, 'nr35', ['expires_on' => '2026-10-15']); // expired
        $this->document($ana, 'nr10', ['expires_on' => '2027-06-01']);   // later: not in the block

        $upcoming = $this->trainings()->upcoming();
        $this->assertCount(1, $upcoming);
        $item = $upcoming->first();
        $this->assertSame('nr35', $item['type']->key);
        $this->assertSame(['Ana Souza'], $item['due']->map(fn ($document) => $document->person->name)->all());
        $this->assertSame(['Carla Mendes'], $item['overdue']->map(fn ($document) => $document->person->name)->all());
        $this->assertSame(['Bruno Dias'], $item['missing']->map(fn (Person $person) => $person->name)->all());
        $this->assertEqualsCanonicalizing([$ana->getKey(), $bruno->getKey(), $carla->getKey()], $item['people']);

        Livewire::test(TrainingsPage::class)->assertSee('Lançar turma (3 pessoas)');
    }

    public function test_missing_courses_in_the_bell_the_attention_box_and_campo(): void
    {
        $tito = $this->technician('Tito Técnico');
        $lia = User::factory()->memberOf($this->company)->create(['name' => 'Lia Campo']);
        app(AccessManager::class)->set($lia, 'campo', 'manager');
        $this->trainings()->addRequirement(['document_type_id' => $this->type('nr35')->getKey(), 'scope' => DocumentRequirement::ROLE, 'role' => 'campo:technician']);
        $this->document(Person::forUser($tito), 'nr10', ['expires_on' => '2026-10-20']);

        // Once per person and course, in one warning per course.
        $this->artisan('kiban:documents')->assertSuccessful();
        $this->artisan('kiban:documents')->assertSuccessful();
        $titles = $this->titles($this->hr);
        $this->assertContains('NR-35 (trabalho em altura): 1 pessoa sem o curso', $titles);
        $this->assertSame(1, collect($titles)->filter(fn (string $title): bool => str_contains($title, 'sem o curso'))->count());
        $this->assertSame([], $this->titles($lia));

        // A new technician missing it gets a new warning; the old one is not repeated.
        Person::forUser($this->technician('Zeca Técnico'));
        $this->assertSame(1, app(TrainingService::class)->scanMissing());
        $this->assertSame(0, app(TrainingService::class)->scanMissing());

        // "Attention" for who takes care of the documents.
        $this->get($this->company->url('/'))->assertOk()->assertSee('Cursos obrigatórios: 2 pessoas com curso para fazer');

        // Campo: the existing technician warning also says what is missing.
        $this->actingAs($lia);
        $this->get($this->company->url('/'))->assertOk()
            ->assertSee('Técnico Tito Técnico — NR-10 (eletricidade): venceu em 20/10/2026, NR-35 (trabalho em altura): falta fazer')
            ->assertDontSee('Cursos obrigatórios');
        $this->assertSame('Atenção: NR-35 (trabalho em altura): falta fazer. O agendamento continua permitido.', OrderResource::technicianWarning(User::query()->where('name', 'Zeca Técnico')->value('id')));
    }

    public function test_the_proof_of_courses_in_pdf_only_for_who_may_get_it(): void
    {
        $workerUser = User::factory()->memberOf($this->company)->create(['name' => 'Caio Técnico']);
        $worker = Person::forUser($workerUser);
        $other = Person::query()->create(['name' => 'Ana Souza']);
        $this->document($worker, 'nr35', ['issued_on' => '2026-03-10', 'provider' => 'Senai', 'workload_hours' => 8]);
        $this->document($worker, 'nr10', ['expires_on' => '2026-10-01']); // expired: not on the proof
        $this->document($worker, 'aso', ['issued_on' => '2026-06-01']);   // health data: never on the proof

        $courses = $this->trainings()->validCourses($worker);
        $this->assertSame(['nr35'], $courses->map(fn ($document) => $document->type->key)->all());
        $data = app(TrainingRecord::class)->data($worker);
        $this->assertSame('Instaladora', $data['company']);
        $this->assertSame('cursos-caio-tecnico.pdf', app(TrainingRecord::class)->fileName($worker));

        $url = $this->company->url('/cursos/'.$worker->getKey().'/comprovante');
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($this->hr);
        $this->get($url)->assertOk();

        // The person gets their own; never someone else's.
        $this->actingAs($workerUser);
        $this->get($url)->assertOk();
        $this->get($this->company->url('/cursos/'.$other->getKey().'/comprovante'))->assertForbidden();

        // A Campo manager (no HR) does not get it either.
        $lia = User::factory()->memberOf($this->company)->create(['name' => 'Lia Campo']);
        app(AccessManager::class)->set($lia, 'campo', 'manager');
        $this->actingAs($lia);
        $this->get($url)->assertForbidden();
        $this->get($this->company->url('/empresa/cursos'))->assertForbidden();
    }

    public function test_companies_never_see_each_others_courses(): void
    {
        $team = Team::query()->create(['name' => 'Instalação']);
        $ana = Person::query()->create(['name' => 'Ana Souza', 'team_id' => $team->getKey()]);
        $requirement = $this->trainings()->addRequirement(['document_type_id' => $this->type('nr35')->getKey(), 'scope' => DocumentRequirement::TEAM, 'team_id' => $team->getKey()]);
        $nr35 = $this->type('nr35');

        $other = $this->tenant('padaria', ['ponto' => []]);
        $this->signIn($other);

        $this->assertSame(0, DocumentRequirement::query()->count());
        $this->assertNull(DocumentRequirement::query()->find($requirement->getKey()));
        $this->assertSame([], app(TrainingService::class)->requiredMap());
        $this->assertTrue(app(TrainingService::class)->missing()->isEmpty());
        $this->assertSame(0, app(TrainingService::class)->scanMissing());
        $this->get($other->url('/cursos/'.$ana->getKey().'/comprovante'))->assertNotFound();
        Livewire::test(TrainingsPage::class)->assertDontSee('Ana Souza')->assertSee(__('documents.trainings.matrix.empty'));

        // Another company's course or team can never be used.
        $this->assertThrows(fn () => app(TrainingService::class)->addRequirement(['document_type_id' => $nr35->getKey(), 'scope' => DocumentRequirement::ALL]), ModelNotFoundException::class);
        app(PersonDocumentService::class)->types();
        $mine = DocumentType::query()->where('key', 'nr35')->value('id');
        $this->assertThrows(fn () => app(TrainingService::class)->addRequirement(['document_type_id' => $mine, 'scope' => DocumentRequirement::TEAM, 'team_id' => $team->getKey()]), ModelNotFoundException::class);

        // The database itself refuses a requirement pointing to another company's type.
        $this->assertThrows(fn () => DB::table('person_document_requirements')->insert([
            'tenant_id' => $other->id, 'document_type_id' => $nr35->getKey(), 'scope' => 'all', 'created_at' => now(), 'updated_at' => now(),
        ]), QueryException::class);
    }
}
