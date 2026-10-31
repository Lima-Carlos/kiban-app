<?php

namespace App\Filament\Pages;

use App\Filament\Support\ManagesPersonDocuments;
use App\Models\Team;
use App\People\Documents\DocumentType;
use App\People\Documents\TrainingKind;
use App\People\Documents\TrainingMatrixExporter;
use App\People\Documents\TrainingService;
use App\People\Person;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Livewire\Attributes\Url;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "Courses and trainings" (in the Company group): the matrix people × courses (green valid, yellow expiring,
 * red expired, gray missing when required), the renewals of the next 90 days with one class for everyone,
 * "Lançar turma" (one course for many people), the spreadsheet and the proof in PDF. Same people as "Expiry
 * dates" (owners, administrators, HR). Part of the people's documents, not a module.
 */
class TrainingsPage extends Page implements HasActions, HasSchemas
{
    use InteractsWithActions, InteractsWithSchemas, ManagesPersonDocuments;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAcademicCap;

    protected static ?int $navigationSort = -4;

    protected static ?string $slug = 'empresa/cursos';

    protected string $view = 'filament.pages.trainings';

    #[Url(as: 'equipe')]
    public ?int $team = null;

    public static function getNavigationGroup(): ?string
    {
        return __('app.menu.company');
    }

    public static function canAccess(): bool
    {
        return DocumentsPage::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return __('documents.trainings.title');
    }

    public function getTitle(): string
    {
        return __('documents.trainings.title');
    }

    public function getSubheading(): ?string
    {
        return __('documents.trainings.help');
    }

    protected function trainings(): TrainingService
    {
        return app(TrainingService::class);
    }

    /**
     * @return array{types: Collection<int, DocumentType>, rows: Collection<int, array<string, mixed>>}
     */
    public function matrix(): array
    {
        return $this->trainings()->matrix($this->team ?: null);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function upcoming(): Collection
    {
        return $this->trainings()->upcoming(teamId: $this->team ?: null);
    }

    /**
     * @return array<int, string>
     */
    public function teamOptions(): array
    {
        return Team::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all();
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->launchClassAction(),
            Action::make('requirements')
                ->label(__('documents.trainings.actions.requirements'))
                ->icon(Heroicon::OutlinedUserGroup)
                ->color('gray')
                ->url(fn (): string => TrainingRequirementsPage::getUrl()),
            Action::make('export')
                ->label(__('documents.trainings.actions.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->authorize(fn (): bool => static::canAccess())
                ->action(fn (): BinaryFileResponse => $this->download()),
            Action::make('types')
                ->label(__('documents.types.title'))
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->url(fn (): string => DocumentTypesPage::getUrl()),
        ];
    }

    /**
     * "Lançar turma": one form for a course taken by many people. Arguments (from "Próximas renovações"):
     * type and people, to prefill.
     */
    public function launchClassAction(): Action
    {
        return Action::make('launchClass')
            ->label(__('documents.trainings.actions.launch'))
            ->icon(Heroicon::OutlinedAcademicCap)
            ->authorize(fn (): bool => $this->documents()->canManage(auth()->user()) && $this->tenantCanWrite())
            ->modalHeading(__('documents.trainings.actions.launch'))
            ->modalDescription(__('documents.trainings.actions.launch_help'))
            ->modalWidth('2xl')
            ->fillForm(function (array $arguments): array {
                $type = isset($arguments['type']) ? (int) $arguments['type'] : null;
                $people = array_map('intval', (array) ($arguments['people'] ?? []));

                return [
                    'document_type_id' => $type,
                    'issued_on' => now()->toDateString(),
                    'expires_on' => $this->documents()->expiryFor(DocumentType::query()->find($type), now())?->toDateString(),
                    'person_ids' => $people,
                    'training_kind' => ($type !== null && $this->trainings()->allHaveIt($type, $people) ? TrainingKind::Refresher : TrainingKind::Initial)->value,
                ];
            })
            ->schema(fn (): array => $this->classFields())
            ->action(function (array $data): void {
                $people = (array) ($data['person_ids'] ?? []);
                unset($data['person_ids']);
                $documents = $this->trainings()->launchClass($data, $people, auth()->user());
                Notification::make()->success()
                    ->title(trans_choice('documents.trainings.actions.launched', $documents->count(), ['count' => $documents->count()]))
                    ->send();
            });
    }

    /**
     * @return array<int, mixed>
     */
    protected function classFields(): array
    {
        $service = $this->documents();
        $fillExpiry = function (Get $get, Set $set) use ($service): void {
            $expiry = $service->expiryFor(DocumentType::query()->find($get('document_type_id')), $get('issued_on'));

            if ($expiry !== null) {
                $set('expires_on', $expiry->toDateString());
            }
        };

        return [
            Select::make('document_type_id')
                ->label(__('documents.trainings.fields.course'))
                ->options(fn (): array => $this->trainings()->trainingTypeOptions())
                ->required()
                ->live()
                ->afterStateUpdated($fillExpiry),
            Grid::make(['default' => 1, 'sm' => 2])->components([
                DatePicker::make('issued_on')
                    ->label(__('documents.trainings.fields.taken_on'))
                    ->required()
                    ->maxDate(now()->addDay())
                    ->live(onBlur: true)
                    ->afterStateUpdated($fillExpiry),
                DatePicker::make('expires_on')
                    ->label(__('documents.fields.expires_on'))
                    ->afterOrEqual('issued_on')
                    ->helperText(function (Get $get): string {
                        $months = DocumentType::query()->find($get('document_type_id'))?->validity_months;

                        return $months !== null
                            ? trans_choice('documents.helpers.expiry_from_validity', $months, ['months' => $months])
                            : __('documents.helpers.expiry_typed');
                    }),
            ]),
            Grid::make(['default' => 1, 'sm' => 3])->components($this->trainingDetailFields()),
            Select::make('person_ids')
                ->label(__('documents.trainings.fields.people'))
                ->helperText(__('documents.trainings.helpers.people').' '.__('documents.trainings.helpers.renewal'))
                ->options(fn (): array => Person::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                ->multiple()
                ->searchable()
                ->required(),
            FileUpload::make('file_path')
                ->label(__('documents.trainings.fields.certificate'))
                ->helperText(__('documents.trainings.helpers.certificate'))
                ->disk('local')
                ->visibility('private')
                ->directory(fn (): string => 'tenants/'.$this->tenant()->getKey().'/people/documents')
                ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                ->automaticallyResizeImagesMode('contain')
                ->automaticallyResizeImagesToWidth('1600')
                ->automaticallyResizeImagesToHeight('1600')
                ->maxSize(self::MAX_FILE_KB),
            Textarea::make('notes')
                ->label(__('documents.fields.notes'))
                ->rows(2),
        ];
    }

    /** The matrix as it is on the screen (team filter) in an Excel spreadsheet. */
    public function download(): BinaryFileResponse
    {
        abort_unless(static::canAccess(), 403);

        $path = app(TrainingMatrixExporter::class)->export($this->matrix());

        return response()->download($path, basename(preg_replace('/-[A-Za-z0-9]{6}\.xlsx$/', '.xlsx', $path)))->deleteFileAfterSend();
    }

    public function proofUrl(Person $person): string
    {
        return route('people.trainings.proof', ['person' => $person->getKey()]);
    }

    public function documentsUrl(Person $person): string
    {
        return PersonDocumentsPage::getUrl(['pessoa' => $person->getKey()]);
    }
}
