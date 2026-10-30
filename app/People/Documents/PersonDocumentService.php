<?php

namespace App\People\Documents;

use App\Filament\Pages\DocumentsPage;
use App\Filament\Pages\PersonDocumentsPage;
use App\Models\User;
use App\Modules\Fleet\Models\Driver;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\People\PeopleService;
use App\People\Person;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Every rule of the people's documents (driver's license, medical exam, NR trainings...): who sees them, the
 * expiry from the type's validity, renewing (the old one stays as history), the bell warnings (once per
 * milestone) and what the systems need (drivers with an expired license, technicians with an expired NR).
 *
 * Who sees the documents: owners, administrators and who may see personal documents in People (HR). Health
 * data (e.g. the medical exam) and the files follow the same rule; the Frota and Campo managers only see
 * "expired" in their "Attention" box. A person always sees their own documents (read only).
 */
class PersonDocumentService
{
    /** Second warning before the expiry (the first one is at the type's warning days). */
    public const WEEK_DAYS = 7;

    public function __construct(
        protected TenantContext $context,
        protected Notifier $notifier,
    ) {}

    // ---------------------------------------------------------------- who sees what

    /** Adds, changes and sees the documents of the company (owners, administrators, HR). */
    public function canManage(?User $user): bool
    {
        $tenant = $this->context->current();

        return $user instanceof User && $tenant !== null && $user->belongsToTenant($tenant)
            && app(PeopleService::class)->canSeeDocuments($user, $tenant);
    }

    /** Health data (LGPD, art. 11: sensitive): never for the Frota or Campo managers. */
    public function canSeeHealth(?User $user): bool
    {
        return $this->canManage($user);
    }

    public function canSee(?User $user, PersonDocument $document): bool
    {
        if ($user instanceof User && $document->person?->user_id === $user->getKey()) {
            return true;
        }

        return $this->canManage($user) && (! $document->type->is_health || $this->canSeeHealth($user));
    }

    /** The attachment: same as seeing the document (the person themselves included). */
    public function canOpenFile(?User $user, PersonDocument $document): bool
    {
        return filled($document->file_path) && $this->canSee($user, $document);
    }

    // ---------------------------------------------------------------- types

    /**
     * Active types of the company (the ready ones are created the first time).
     *
     * @return EloquentCollection<int, DocumentType>
     */
    public function types(bool $activeOnly = true): EloquentCollection
    {
        DocumentTemplates::ensure();

        return DocumentType::query()->when($activeOnly, fn (Builder $query) => $query->active())->ordered()->get();
    }

    /**
     * @return array<int, string>
     */
    public function typeOptions(bool $activeOnly = true): array
    {
        return $this->types($activeOnly)->mapWithKeys(fn (DocumentType $type): array => [$type->getKey() => $type->label()])->all();
    }

    /** Expiry from the issue date and the type's validity (null when the type has none). */
    public function expiryFor(?DocumentType $type, CarbonInterface|string|null $issuedOn): ?CarbonImmutable
    {
        if ($type?->validity_months === null || blank($issuedOn)) {
            return null;
        }

        return CarbonImmutable::parse($issuedOn instanceof CarbonInterface ? $issuedOn->toDateString() : $issuedOn)->addMonthsNoOverflow($type->validity_months);
    }

    // ---------------------------------------------------------------- documents

    /**
     * A new document. Without "expires_on" in the data, it comes from the type's validity. The previous document
     * of the same type becomes history (renewal).
     *
     * @param  array{document_type_id: int|string, number?: ?string, issued_on?: mixed, expires_on?: mixed, file_path?: ?string, notes?: ?string}  $data
     */
    public function add(Person $person, array $data, ?User $by = null): PersonDocument
    {
        $type = DocumentType::query()->findOrFail($data['document_type_id']);

        if (! array_key_exists('expires_on', $data)) {
            $data['expires_on'] = $this->expiryFor($type, $data['issued_on'] ?? null)?->toDateString();
        }

        return DB::transaction(function () use ($person, $type, $data, $by): PersonDocument {
            $document = new PersonDocument($this->clean($data));
            $document->person_id = $person->getKey();
            $document->document_type_id = $type->getKey();
            $document->forceFill(['created_by' => $by?->getKey(), 'updated_by' => $by?->getKey()]);
            $document->save();

            PersonDocument::query()->current()
                ->where('person_id', $person->getKey())
                ->where('document_type_id', $type->getKey())
                ->whereKeyNot($document->getKey())
                ->update(['replaced_at' => now()]);

            $this->syncDriverLicense($document);

            return $document;
        });
    }

    /**
     * Corrects a document (number, dates, file, notes). The type does not change.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(PersonDocument $document, array $data, ?User $by = null): PersonDocument
    {
        $old = $document->file_path;
        unset($data['document_type_id'], $data['person_id']);
        $document->fill($this->clean($data));
        $document->forceFill(['updated_by' => $by?->getKey()])->save();

        if (filled($old) && $old !== $document->file_path) {
            Storage::disk('local')->delete($old);
        }

        $this->syncDriverLicense($document);

        return $document;
    }

    /**
     * Removes a document added by mistake (and its file). If it was the current one, the previous one of the same
     * type is current again.
     */
    public function delete(PersonDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $wasCurrent = $document->isCurrent();
            $document->delete();

            if ($wasCurrent) {
                PersonDocument::query()
                    ->where('person_id', $document->person_id)
                    ->where('document_type_id', $document->document_type_id)
                    ->whereNotNull('replaced_at')
                    ->orderByDesc('replaced_at')->orderByDesc('id')
                    ->first()?->forceFill(['replaced_at' => null])->save();
            }
        });
    }

    /**
     * Deletes every document of the person and their files (used when the system deletes the person's data).
     */
    public function purgePerson(Person $person): int
    {
        $documents = PersonDocument::query()->where('person_id', $person->getKey())->get();
        $documents->each(fn (PersonDocument $document) => $document->delete());

        return $documents->count();
    }

    /**
     * The driver's license is also on the Frota driver record: the newest one keeps it up to date.
     */
    protected function syncDriverLicense(PersonDocument $document): void
    {
        $type = $document->type()->first();

        if (! $document->isCurrent() || ! in_array($type?->key, DocumentTemplates::DRIVER_LICENSE, true) || ! Schema::hasTable('fleet_drivers')) {
            return;
        }

        $userId = $document->person()->value('user_id');

        if ($userId === null) {
            return;
        }

        Driver::query()->where('user_id', $userId)->get()->each(fn (Driver $driver) => $driver->forceFill(array_filter([
            'license_number' => $document->number,
            'license_expires_on' => $document->expires_on?->toDateString(),
        ], fn ($value): bool => $value !== null))->save());
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function clean(array $data): array
    {
        $values = array_intersect_key($data, array_flip(['number', 'issued_on', 'expires_on', 'file_path', 'notes']));

        foreach ($values as $key => $value) {
            $values[$key] = is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);
        }

        return $values;
    }

    // ---------------------------------------------------------------- lists

    /**
     * Current documents (not replaced) of the active people.
     *
     * @return Builder<PersonDocument>
     */
    public function currentQuery(): Builder
    {
        return PersonDocument::query()->current()
            ->whereHas('person', fn (Builder $query) => $query->where('is_active', true))
            ->whereHas('type', fn (Builder $query) => $query->where('is_active', true));
    }

    /**
     * Limits a query of documents to one situation (in the database, using each type's warning days).
     *
     * @param  Builder<PersonDocument>  $query
     * @return Builder<PersonDocument>
     */
    public function whereStatus(Builder $query, DocumentStatus|string $status, ?CarbonInterface $today = null): Builder
    {
        $status = $status instanceof DocumentStatus ? $status : DocumentStatus::from($status);
        $today = ($today ?? PersonDocument::today())->toDateString();
        $table = (new PersonDocument)->getTable();
        $warning = fn (Builder $types) => $types->whereRaw("{$table}.expires_on <= CAST(? AS date) + person_document_types.alert_days", [$today]);

        return match ($status) {
            DocumentStatus::NoExpiry => $query->whereNull("{$table}.expires_on"),
            DocumentStatus::Overdue => $query->whereDate("{$table}.expires_on", '<', $today),
            DocumentStatus::Soon => $query->whereDate("{$table}.expires_on", '>=', $today)->whereHas('type', $warning),
            DocumentStatus::Ok => $query->whereDate("{$table}.expires_on", '>=', $today)->whereDoesntHave('type', $warning),
        };
    }

    /**
     * How many current documents are expired and expiring.
     *
     * @return array{overdue: int, soon: int}
     */
    public function counts(): array
    {
        return [
            'overdue' => $this->whereStatus($this->currentQuery(), DocumentStatus::Overdue)->count(),
            'soon' => $this->whereStatus($this->currentQuery(), DocumentStatus::Soon)->count(),
        ];
    }

    /**
     * Current expired documents of a system's types (e.g. "frota": driver's license), by the person's login.
     *
     * @return Collection<int, Collection<int, PersonDocument>>
     */
    public function expiredFor(string $module, ?array $userIds = null): Collection
    {
        if (! Schema::hasTable('person_documents')) {
            return collect();
        }

        return $this->whereStatus($this->currentQuery(), DocumentStatus::Overdue)
            ->with(['type', 'person'])
            ->whereHas('type', fn (Builder $query) => $query->where('module', $module))
            ->whereHas('person', fn (Builder $query) => $query->whereNotNull('user_id')
                ->when($userIds !== null, fn (Builder $query) => $query->whereIn('user_id', $userIds)))
            ->orderBy('expires_on')
            ->get()
            ->groupBy(fn (PersonDocument $document): int => (int) $document->person->user_id)
            ->toBase();
    }

    /**
     * Logins with a current document of a system's types (e.g. a driver's license in People).
     *
     * @return array<int, int>
     */
    public function usersWithDocuments(string $module): array
    {
        if (! Schema::hasTable('person_documents')) {
            return [];
        }

        return Person::query()->whereNotNull('user_id')
            ->whereHas('documents', fn (Builder $query) => $query->current()->whereHas('type', fn (Builder $types) => $types->where('module', $module)->where('is_active', true)))
            ->pluck('user_id')->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * "NR-10 vencida em 10/03/2026, NR-35 vencida em ..." for a person's expired documents of a system.
     *
     * @param  iterable<PersonDocument>  $documents
     */
    public static function expiredText(iterable $documents): string
    {
        return collect($documents)->map(fn (PersonDocument $document): string => __('documents.attention.expired_item', [
            'document' => $document->type->label(),
            'date' => $document->expires_on->translatedFormat(__('app.date_format')),
        ]))->implode(', ');
    }

    /**
     * "NR-10 vencida em 10/03/2026" by login, for a system's screens (e.g. choosing the technician of a work order).
     *
     * @return array<int, string>
     */
    public function expiredTexts(string $module, ?array $userIds = null): array
    {
        return $this->expiredFor($module, $userIds)
            ->map(fn (Collection $documents): string => static::expiredText($documents))
            ->all();
    }

    /**
     * The start page's "Attention" box (in every system), for who takes care of the documents.
     *
     * @return array<int, array{text: string, url: ?string, color: string}>
     */
    public function attention(?User $user): array
    {
        if (! Schema::hasTable('person_documents') || ! $this->canManage($user)) {
            return [];
        }

        $counts = $this->counts();

        if ($counts['overdue'] + $counts['soon'] === 0) {
            return [];
        }

        $parts = array_filter([
            $counts['overdue'] > 0 ? trans_choice('documents.attention.overdue', $counts['overdue'], ['count' => $counts['overdue']]) : null,
            $counts['soon'] > 0 ? trans_choice('documents.attention.soon', $counts['soon'], ['count' => $counts['soon']]) : null,
        ]);

        return [[
            'text' => __('documents.attention.summary', ['parts' => implode(' · ', $parts)]),
            'url' => DocumentsPage::getUrl(),
            'color' => $counts['overdue'] > 0 ? 'danger' : 'warning',
        ]];
    }

    // ---------------------------------------------------------------- warnings

    /**
     * Which warning a document is at: "start" (the type's warning days), "week" (7 days before) or "day"
     * (the expiry day, or already expired). Null when nothing is due yet.
     */
    public function milestone(PersonDocument $document, ?CarbonInterface $today = null): ?string
    {
        $days = $document->daysLeft($today);

        if ($days === null) {
            return null;
        }

        $alert = $document->type->alert_days;

        return match (true) {
            $days <= 0 => 'day',
            $days <= min(self::WEEK_DAYS, $alert) => 'week',
            $days <= $alert => 'start',
            default => null,
        };
    }

    /**
     * The daily routine (inside the company): each document is warned once per milestone to who takes care of
     * the documents (bell, never e-mail). A milestone already passed (e.g. a document added a few days before
     * expiring) gives only the most advanced warning. Returns how many warnings were sent.
     */
    public function scan(?CarbonInterface $today = null): int
    {
        $today ??= PersonDocument::today();
        $managers = $this->managers();

        if ($managers->isEmpty()) {
            return 0;
        }

        $documents = $this->currentQuery()->with(['type', 'person'])
            ->whereNotNull('expires_on')
            ->whereHas('type', fn (Builder $query) => $query->whereRaw('person_documents.expires_on <= CAST(? AS date) + person_document_types.alert_days', [$today->toDateString()]))
            ->get();

        $sent = 0;

        foreach ($documents as $document) {
            $milestone = $this->milestone($document, $today);

            if ($milestone === null) {
                continue;
            }

            $once = "person-document:{$document->getKey()}:{$document->expires_on->toDateString()}:{$milestone}";

            if (! $this->notifier->firstTime($this->context->get()->getKey(), $once)) {
                continue;
            }

            $days = (int) $document->daysLeft($today);
            $type = $document->type;
            $title = match (true) {
                $days < 0 => 'documents.notices.expired',
                $days === 0 => 'documents.notices.today',
                default => 'documents.notices.soon',
            };

            $this->notifier->send($managers, new Notice(
                $this->context->get()->getKey(),
                $title,
                [
                    'document' => fn (): string => $type->label(),
                    'name' => $document->person->name,
                    'when' => fn (): string => trans_choice('documents.notices.in_days', max($days, 1), ['days' => $days]),
                ],
                'documents.notices.body',
                ['date' => fn (): string => $document->expires_on->translatedFormat(__('app.date_format'))],
                PersonDocumentsPage::getUrl(['pessoa' => $document->person_id]),
                'heroicon-o-identification',
                $days <= 0 ? 'danger' : 'warning',
            ));
            $sent++;
        }

        return $sent;
    }

    /**
     * Who takes care of the documents in the current company.
     *
     * @return Collection<int, User>
     */
    public function managers(): Collection
    {
        return $this->context->get()->users()->get()
            ->filter(fn (User $user): bool => $this->canManage($user))
            ->values()
            ->toBase();
    }

    // ---------------------------------------------------------------- LGPD

    /**
     * The person's documents for the LGPD copy (no files; health data only for who may see it).
     *
     * @return array<int, array<string, mixed>>
     */
    public function exportPerson(Person $person, ?User $viewer): array
    {
        if (! Schema::hasTable('person_documents')) {
            return [];
        }

        $health = $this->canSeeHealth($viewer) || ($viewer !== null && $person->user_id === $viewer->getKey());

        return PersonDocument::query()->with('type')->where('person_id', $person->getKey())->orderBy('id')->get()
            ->filter(fn (PersonDocument $document): bool => $health || ! $document->type->is_health)
            ->map(fn (PersonDocument $document): array => [
                'type' => $document->type->label(),
                'number' => $document->number,
                'issued_on' => $document->issued_on?->toDateString(),
                'expires_on' => $document->expires_on?->toDateString(),
                'notes' => $document->notes,
                'with_file' => filled($document->file_path),
                'current' => $document->isCurrent(),
                'created_at_utc' => $document->created_at?->toIso8601ZuluString(),
            ])
            ->values()
            ->all();
    }
}
