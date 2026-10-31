<?php

namespace App\People\Documents;

use App\Access\AccessManager;
use App\Filament\Pages\TrainingsPage;
use App\Models\Team;
use App\Models\User;
use App\Modules\ModuleRegistry;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\People\Person;
use App\Tenancy\TenantContext;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Courses and trainings: the part of the people's documents for the document types marked as a course
 * (NR-10, NR-35...). Who needs which course (requirements), who is missing one ("Falta fazer"), the matrix
 * people × courses, the renewals of the next days, one class for many people ("Lançar turma") and the bell for
 * who is missing a required course. The documents themselves still go through PersonDocumentService.
 */
class TrainingService
{
    /** "Próximas renovações": how far ahead the block looks. */
    public const UPCOMING_DAYS = 90;

    /** @var array<int, array<int, true>>|null required type ids by person id (this request) */
    protected ?array $required = null;

    public function __construct(
        protected TenantContext $context,
        protected Notifier $notifier,
        protected PersonDocumentService $documents,
        protected AccessManager $access,
        protected ModuleRegistry $modules,
    ) {}

    // ---------------------------------------------------------------- types

    /**
     * Active document types marked as a course or training.
     *
     * @return EloquentCollection<int, DocumentType>
     */
    public function trainingTypes(): EloquentCollection
    {
        return $this->documents->types()->filter(fn (DocumentType $type): bool => $type->is_training)->values();
    }

    /**
     * @return array<int, string>
     */
    public function trainingTypeOptions(): array
    {
        return $this->trainingTypes()->mapWithKeys(fn (DocumentType $type): array => [$type->getKey() => $type->label()])->all();
    }

    // ---------------------------------------------------------------- requirements

    /**
     * @return EloquentCollection<int, DocumentRequirement>
     */
    public function requirements(): EloquentCollection
    {
        if (! Schema::hasTable('person_document_requirements')) {
            return new EloquentCollection;
        }

        return DocumentRequirement::query()->with(['type', 'team'])
            ->whereHas('type', fn (Builder $query) => $query->where('is_active', true)->where('is_training', true))
            ->orderBy('document_type_id')->orderBy('id')->get();
    }

    /**
     * Roles of the company's systems a requirement can point to: "campo:technician" => "Campo: Técnico".
     *
     * @return array<string, string>
     */
    public function roleOptions(): array
    {
        return collect($this->modules->enabledFor($this->context->get()))
            ->flatMap(fn ($module): array => collect($module->roles())
                ->mapWithKeys(fn (string $label, string $role): array => [$module->key().':'.$role => DocumentRequirement::roleLabel($module->key(), $role)])
                ->all())
            ->all();
    }

    /**
     * "This course is required for ..." (the same requirement twice is kept once).
     *
     * @param  array{document_type_id: int|string, scope: string, team_id?: int|string|null, role?: ?string}  $data
     */
    public function addRequirement(array $data, ?User $by = null): DocumentRequirement
    {
        $type = DocumentType::query()->trainings()->findOrFail($data['document_type_id']);
        $values = ['document_type_id' => $type->getKey(), 'scope' => $data['scope'], 'team_id' => null, 'module' => null, 'role' => null];

        if ($data['scope'] === DocumentRequirement::TEAM) {
            $values['team_id'] = Team::query()->findOrFail($data['team_id'] ?? null)->getKey();
        } elseif ($data['scope'] === DocumentRequirement::ROLE) {
            [$values['module'], $values['role']] = $this->parseRole((string) ($data['role'] ?? ''));
        } elseif ($data['scope'] !== DocumentRequirement::ALL) {
            throw new InvalidArgumentException('Unknown requirement scope.');
        }

        $this->required = null;

        $existing = DocumentRequirement::query()->where($values)->first();

        if ($existing !== null) {
            return $existing;
        }

        $requirement = new DocumentRequirement($values);
        $requirement->forceFill(['created_by' => $by?->getKey()])->save();

        return $requirement;
    }

    public function removeRequirement(DocumentRequirement $requirement): void
    {
        $requirement->delete();
        $this->required = null;
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function parseRole(string $value): array
    {
        if (! array_key_exists($value, $this->roleOptions())) {
            throw new InvalidArgumentException('Unknown role.');
        }

        return explode(':', $value, 2);
    }

    /**
     * Required course types by person (active people only), from every requirement.
     *
     * @return array<int, array<int, true>>
     */
    public function requiredMap(): array
    {
        if ($this->required !== null) {
            return $this->required;
        }

        $requirements = $this->requirements();

        if ($requirements->isEmpty()) {
            return $this->required = [];
        }

        $people = Person::query()->active()->with('user')->get();
        $map = [];

        foreach ($requirements as $requirement) {
            foreach ($people as $person) {
                if ($this->covers($requirement, $person)) {
                    $map[$person->getKey()][$requirement->document_type_id] = true;
                }
            }
        }

        return $this->required = $map;
    }

    public function covers(DocumentRequirement $requirement, Person $person): bool
    {
        return match ($requirement->scope) {
            DocumentRequirement::ALL => true,
            DocumentRequirement::TEAM => $person->team_id !== null && (int) $person->team_id === (int) $requirement->team_id,
            DocumentRequirement::ROLE => $person->user !== null
                && isset($this->modules->enabledFor($this->context->get())[$requirement->module])
                && $this->access->role($person->user, (string) $requirement->module) === $requirement->role,
            default => false,
        };
    }

    public function isRequired(Person $person, DocumentType|int $type): bool
    {
        return isset($this->requiredMap()[$person->getKey()][$type instanceof DocumentType ? $type->getKey() : $type]);
    }

    /** Forgets what was read in this request (after a person, a team or a role changed). */
    public function forget(): void
    {
        $this->required = null;
        $this->access->forget();
    }

    // ---------------------------------------------------------------- the situation of each person

    /**
     * Current course documents of the active people, by person id and type id.
     *
     * @return array<int, array<int, PersonDocument>>
     */
    protected function currentByPerson(?array $personIds = null): array
    {
        $map = [];

        $this->documents->currentQuery()->with('type')
            ->whereHas('type', fn (Builder $query) => $query->where('is_training', true))
            ->when($personIds !== null, fn (Builder $query) => $query->whereIn('person_id', $personIds))
            ->get()
            ->each(function (PersonDocument $document) use (&$map): void {
                $map[$document->person_id][$document->document_type_id] = $document;
            });

        return $map;
    }

    /**
     * Everyone in the scope of a requirement without a current document of that course.
     *
     * @return Collection<int, array{person: Person, type: DocumentType}>
     */
    public function missing(): Collection
    {
        $required = $this->requiredMap();

        if ($required === []) {
            return collect();
        }

        $current = $this->currentByPerson(array_keys($required));
        $people = Person::query()->whereKey(array_keys($required))->orderBy('name')->get();
        $types = $this->trainingTypes()->keyBy(fn (DocumentType $type): int => $type->getKey());

        return $people->flatMap(fn (Person $person): array => collect($required[$person->getKey()])
            ->keys()
            ->reject(fn (int $typeId): bool => isset($current[$person->getKey()][$typeId]) || ! $types->has($typeId))
            ->map(fn (int $typeId): array => ['person' => $person, 'type' => $types->get($typeId)])
            ->values()
            ->all())->values()->toBase();
    }

    /**
     * The matrix people × courses: the course types required or in use (columns) and, for each active person
     * (optionally of one team), the cell of each course: ok (green, with the expiry), soon (yellow), overdue (red),
     * missing (gray, only when required) or none (blank).
     *
     * @return array{types: Collection<int, DocumentType>, rows: Collection<int, array{person: Person, cells: array<int, array{status: string, document: ?PersonDocument, required: bool}>}>}
     */
    public function matrix(?int $teamId = null): array
    {
        $required = $this->requiredMap();
        $current = $this->currentByPerson();
        $today = PersonDocument::today();

        $usedTypes = collect($current)->flatMap(fn (array $documents): array => array_keys($documents));
        $requiredTypes = collect($required)->flatMap(fn (array $types): array => array_keys($types));
        $wanted = $usedTypes->merge($requiredTypes)->unique()->all();
        $types = $this->trainingTypes()->filter(fn (DocumentType $type): bool => in_array($type->getKey(), $wanted, true))->values()->toBase();

        $people = Person::query()->active()->with('team')
            ->when($teamId !== null, fn (Builder $query) => $query->where('team_id', $teamId))
            ->orderBy('name')->get();

        $rows = $people->map(function (Person $person) use ($types, $required, $current, $today): array {
            $cells = [];

            foreach ($types as $type) {
                $document = $current[$person->getKey()][$type->getKey()] ?? null;
                $isRequired = isset($required[$person->getKey()][$type->getKey()]);
                $document?->setRelation('type', $type);

                $cells[$type->getKey()] = [
                    'status' => $document !== null ? $this->cellStatus($document, $today) : ($isRequired ? 'missing' : 'none'),
                    'document' => $document,
                    'required' => $isRequired,
                ];
            }

            return ['person' => $person, 'cells' => $cells];
        })
            // Only the people the courses concern (in a requirement or with a course).
            ->filter(fn (array $row): bool => collect($row['cells'])->contains(fn (array $cell): bool => $cell['status'] !== 'none'))
            ->values()
            ->toBase();

        return ['types' => $types, 'rows' => $rows];
    }

    /** ok, soon or overdue (a course without an expiry is "ok"). */
    public function cellStatus(PersonDocument $document, ?CarbonInterface $today = null): string
    {
        return match ($document->status($today)) {
            DocumentStatus::Overdue => 'overdue',
            DocumentStatus::Soon => 'soon',
            default => 'ok',
        };
    }

    /**
     * "Próximas renovações": for each course, who expires in the next days (or already expired) and who is missing
     * it (required), so the company books one class for everyone. Soonest first. Optionally of one team.
     *
     * @return Collection<int, array{type: DocumentType, due: Collection<int, PersonDocument>, overdue: Collection<int, PersonDocument>, missing: Collection<int, Person>, people: array<int, int>, first: ?string}>
     */
    public function upcoming(int $days = self::UPCOMING_DAYS, ?int $teamId = null): Collection
    {
        $today = PersonDocument::today();
        $limit = $today->addDays($days)->toDateString();

        $documents = $this->documents->currentQuery()->with(['person', 'type'])
            ->whereHas('type', fn (Builder $query) => $query->where('is_training', true))
            ->when($teamId !== null, fn (Builder $query) => $query->whereHas('person', fn (Builder $people) => $people->where('team_id', $teamId)))
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '<=', $limit)
            ->orderBy('expires_on')
            ->get();

        $missing = $this->missing()
            ->filter(fn (array $item): bool => $teamId === null || (int) $item['person']->team_id === $teamId)
            ->groupBy(fn (array $item): int => $item['type']->getKey());

        return $this->trainingTypes()
            ->map(function (DocumentType $type) use ($documents, $missing, $today): ?array {
                $ofType = $documents->where('document_type_id', $type->getKey())->values();
                $overdue = $ofType->filter(fn (PersonDocument $document): bool => $document->expires_on->lt($today))->values()->toBase();
                $due = $ofType->filter(fn (PersonDocument $document): bool => $document->expires_on->gte($today))->values()->toBase();
                $people = collect($missing->get($type->getKey(), []))->map(fn (array $item): Person => $item['person'])->values();

                if ($overdue->isEmpty() && $due->isEmpty() && $people->isEmpty()) {
                    return null;
                }

                return [
                    'type' => $type,
                    'due' => $due,
                    'overdue' => $overdue,
                    'missing' => $people,
                    'people' => $overdue->merge($due)->map(fn (PersonDocument $document): int => (int) $document->person_id)
                        ->merge($people->map(fn (Person $person): int => (int) $person->getKey()))
                        ->unique()->values()->all(),
                    // Missing and expired first (they are already late), then the soonest expiry.
                    'first' => $people->isNotEmpty() || $overdue->isNotEmpty() ? '0000-00-00' : $due->first()?->expires_on?->toDateString(),
                ];
            })
            ->filter()
            ->sortBy('first')
            ->values()
            ->toBase();
    }

    // ---------------------------------------------------------------- one class for many people

    /**
     * "Lançar turma": one course taken by many people on the same day. Each person gets their own document
     * through PersonDocumentService (a current one of the same course becomes history: renewal) and their own
     * copy of the certificate, so deleting one never breaks another.
     *
     * @param  array<string, mixed>  $data  document_type_id, issued_on, expires_on?, provider?, workload_hours?, training_kind?, file_path?, notes?
     * @param  array<int, int|string>  $personIds
     * @return Collection<int, PersonDocument>
     */
    public function launchClass(array $data, array $personIds, ?User $by = null): Collection
    {
        $type = DocumentType::query()->trainings()->findOrFail($data['document_type_id']);
        $people = Person::query()->active()->whereKey($personIds)->orderBy('name')->get();

        if ($people->isEmpty()) {
            throw new InvalidArgumentException('No people for the class.');
        }

        $file = filled($data['file_path'] ?? null) ? (string) $data['file_path'] : null;
        $copies = [];

        try {
            return DB::transaction(function () use ($people, $type, $data, $file, $by, &$copies): Collection {
                return $people->map(function (Person $person, int $index) use ($type, $data, $file, $by, &$copies): PersonDocument {
                    $path = $file;

                    if ($file !== null && $index > 0) {
                        $path = dirname($file).'/'.Str::random(40).'.'.pathinfo($file, PATHINFO_EXTENSION);
                        Storage::disk('local')->copy($file, $path);
                        $copies[] = $path;
                    }

                    return $this->documents->add($person, array_merge($data, [
                        'document_type_id' => $type->getKey(),
                        'file_path' => $path,
                    ]), $by);
                })->toBase();
            });
        } catch (\Throwable $exception) {
            // Nothing was saved: the copies of the certificate go too.
            Storage::disk('local')->delete($copies);

            throw $exception;
        }
    }

    /**
     * Who already has a current document of this course (the class is a refresher for them).
     *
     * @param  array<int, int|string>  $personIds
     */
    public function allHaveIt(int $typeId, array $personIds): bool
    {
        if ($personIds === []) {
            return false;
        }

        return PersonDocument::query()->current()->where('document_type_id', $typeId)->whereIn('person_id', $personIds)->distinct()->count('person_id') === count(array_unique($personIds));
    }

    // ---------------------------------------------------------------- warnings and the systems

    /**
     * The daily routine: who takes care of the documents is warned in the bell, once per person and course, when
     * someone is missing a required course. One warning per course with the new names.
     */
    public function scanMissing(): int
    {
        $managers = $this->documents->managers();

        if ($managers->isEmpty()) {
            return 0;
        }

        $tenantId = $this->context->get()->getKey();
        $sent = 0;

        foreach ($this->missing()->groupBy(fn (array $item): int => $item['type']->getKey()) as $items) {
            $new = collect($items)->filter(fn (array $item): bool => $this->notifier->firstTime(
                $tenantId,
                "person-training-missing:{$item['person']->getKey()}:{$item['type']->getKey()}",
            ))->values();

            if ($new->isEmpty()) {
                continue;
            }

            $type = $new->first()['type'];
            $names = $new->map(fn (array $item): string => $item['person']->name)->implode(', ');
            $count = $new->count();

            $this->notifier->send($managers, new Notice(
                $tenantId,
                'documents.trainings.notices.missing',
                ['document' => fn (): string => $type->label(), 'people' => fn (): string => trans_choice('documents.trainings.people_count', $count, ['count' => $count])],
                'documents.trainings.notices.missing_body',
                ['names' => $names],
                TrainingsPage::getUrl(),
                'heroicon-o-academic-cap',
                'warning',
            ));
            $sent++;
        }

        return $sent;
    }

    /**
     * "NR-35 (trabalho em altura): falta fazer" by login, for the required courses of a system's types that a
     * person is missing (e.g. the Campo technicians).
     *
     * @param  array<int, int>|null  $userIds
     * @return array<int, string>
     */
    public function missingTexts(string $module, ?array $userIds = null): array
    {
        return $this->missing()
            ->filter(fn (array $item): bool => $item['type']->module === $module && $item['person']->user_id !== null
                && ($userIds === null || in_array((int) $item['person']->user_id, $userIds, true)))
            ->groupBy(fn (array $item): int => (int) $item['person']->user_id)
            ->map(fn (Collection $items): string => $items->map(fn (array $item): string => __('documents.trainings.missing_item', ['document' => $item['type']->label()]))->implode(', '))
            ->all();
    }

    /**
     * Expired documents and missing required courses of a system, by login: "NR-10: venceu em ..., NR-35: falta fazer".
     *
     * @param  array<int, int>|null  $userIds
     * @return array<int, string>
     */
    public function warningTexts(string $module, ?array $userIds = null): array
    {
        $texts = $this->documents->expiredTexts($module, $userIds);

        foreach ($this->missingTexts($module, $userIds) as $userId => $text) {
            $texts[$userId] = isset($texts[$userId]) ? $texts[$userId].', '.$text : $text;
        }

        return $texts;
    }

    /**
     * The start page's "Attention" box, for who takes care of the documents: people missing a required course.
     *
     * @return array<int, array{text: string, url: ?string, color: string}>
     */
    public function attention(?User $user): array
    {
        if (! Schema::hasTable('person_document_requirements') || ! $this->documents->canManage($user)) {
            return [];
        }

        $people = $this->missing()->map(fn (array $item): int => $item['person']->getKey())->unique()->count();

        if ($people === 0) {
            return [];
        }

        return [[
            'text' => trans_choice('documents.trainings.attention', $people, ['count' => $people]),
            'url' => TrainingsPage::getUrl(),
            'color' => 'warning',
        ]];
    }

    // ---------------------------------------------------------------- proof of the courses (PDF)

    /** The PDF with a person's courses: who takes care of the documents, or the person themselves. */
    public function canGetProof(?User $user, Person $person): bool
    {
        return ($user instanceof User && $person->user_id === $user->getKey()) || $this->documents->canManage($user);
    }

    /**
     * The person's valid courses (current, not expired), for the proof.
     *
     * @return EloquentCollection<int, PersonDocument>
     */
    public function validCourses(Person $person): EloquentCollection
    {
        $today = PersonDocument::today()->toDateString();

        return PersonDocument::query()->current()->with('type')
            ->where('person_id', $person->getKey())
            ->whereHas('type', fn (Builder $query) => $query->where('is_training', true)->where('is_health', false)->where('is_active', true))
            ->where(fn (Builder $query) => $query->whereNull('expires_on')->orWhereDate('expires_on', '>=', $today))
            ->orderBy('issued_on')->orderBy('id')
            ->get();
    }
}
