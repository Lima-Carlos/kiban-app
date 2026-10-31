<?php

namespace App\Filament\Pages;

use App\Models\Team;
use App\People\Documents\DocumentRequirement;
use App\People\Documents\TrainingService;
use App\People\Person;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Who needs each course": the required courses and for whom (everyone, a team, a role in a system such as the
 * Campo technicians). Whoever is in the scope without the course is "missing" in "Courses and trainings", in the
 * bell and in the "Attention" box. Same people as "Expiry dates" (owners, administrators, HR).
 */
class TrainingRequirementsPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $slug = 'empresa/cursos/obrigatorios';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return DocumentsPage::canAccess();
    }

    public function getTitle(): string
    {
        return __('documents.trainings.requirements.title');
    }

    public function getSubheading(): ?string
    {
        return __('documents.trainings.requirements.help');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function trainings(): TrainingService
    {
        return app(TrainingService::class);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('documents.trainings.actions.back'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => TrainingsPage::getUrl()),
            Action::make('create')
                ->label(__('documents.trainings.requirements.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize(fn (): bool => static::canAccess())
                ->schema(fn (): array => [
                    Select::make('document_type_id')
                        ->label(__('documents.trainings.requirements.course'))
                        ->options(fn (): array => $this->trainings()->trainingTypeOptions())
                        ->helperText(__('documents.types.is_training_help'))
                        ->required(),
                    Radio::make('scope')
                        ->label(__('documents.trainings.requirements.scope'))
                        ->options([
                            DocumentRequirement::ROLE => __('documents.trainings.requirements.scope_role'),
                            DocumentRequirement::TEAM => __('documents.trainings.requirements.scope_team'),
                            DocumentRequirement::ALL => __('documents.trainings.requirements.scope_all'),
                        ])
                        ->default(DocumentRequirement::ROLE)
                        ->required()
                        ->live(),
                    Select::make('role')
                        ->label(__('documents.trainings.requirements.role'))
                        ->options(fn (): array => $this->trainings()->roleOptions())
                        ->visible(fn (Get $get): bool => $get('scope') === DocumentRequirement::ROLE)
                        ->required(),
                    Select::make('team_id')
                        ->label(__('documents.trainings.requirements.team'))
                        ->options(fn (): array => Team::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => $get('scope') === DocumentRequirement::TEAM)
                        ->required(),
                ])
                ->action(function (array $data): void {
                    $this->trainings()->addRequirement($data, auth()->user());
                    Notification::make()->success()->title(__('documents.trainings.requirements.saved'))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $service = $this->trainings();
        // Read once per screen: the active people and who is missing each course.
        $people = null;
        $activePeople = function () use (&$people) {
            return $people ??= Person::query()->active()->with('user')->get();
        };
        $missingByType = null;
        $missing = function () use (&$missingByType, $service): array {
            return $missingByType ??= $service->missing()
                ->groupBy(fn (array $item): int => $item['type']->getKey())
                ->map(fn ($items): int => count($items))
                ->all();
        };

        return $table
            ->query(fn (): Builder => DocumentRequirement::query()->with(['type', 'team'])
                ->whereHas('type', fn (Builder $query) => $query->where('is_active', true)->where('is_training', true)))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('document_type_id')->orderBy('id'))
            ->columns([
                TextColumn::make('course')
                    ->label(__('documents.trainings.requirements.course'))
                    ->state(fn (DocumentRequirement $record): string => $record->type->label())
                    ->weight('bold'),
                TextColumn::make('target')
                    ->label(__('documents.trainings.requirements.scope'))
                    ->state(fn (DocumentRequirement $record): string => $record->targetLabel()),
                TextColumn::make('people')
                    ->label(__('documents.trainings.requirements.people'))
                    ->state(fn (DocumentRequirement $record): int => $activePeople()
                        ->filter(fn (Person $person): bool => $service->covers($record, $person))->count())
                    ->alignEnd(),
                TextColumn::make('missing')
                    ->label(__('documents.trainings.requirements.missing'))
                    ->state(fn (DocumentRequirement $record): int => $missing()[$record->document_type_id] ?? 0)
                    ->badge()
                    ->color(fn (int $state): string => $state > 0 ? 'gray' : 'success')
                    ->alignEnd(),
            ])
            ->recordActions([
                Action::make('delete')
                    ->label(__('documents.trainings.requirements.delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->color('danger')
                    ->link()
                    ->authorize(fn (): bool => static::canAccess())
                    ->requiresConfirmation()
                    ->modalDescription(__('documents.trainings.requirements.delete_help'))
                    ->action(function (DocumentRequirement $record) use ($service): void {
                        $service->removeRequirement($record);
                        Notification::make()->success()->title(__('documents.trainings.requirements.deleted'))->send();
                    }),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('documents.trainings.requirements.empty'))
            ->emptyStateDescription(__('documents.trainings.requirements.empty_help'))
            ->emptyStateIcon(Heroicon::OutlinedAcademicCap);
    }
}
