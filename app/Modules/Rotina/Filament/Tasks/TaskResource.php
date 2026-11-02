<?php

namespace App\Modules\Rotina\Filament\Tasks;

use App\Filament\Support\ModuleResource;
use App\Modules\Rotina\Filament\Tasks\Pages\ManageTasks;
use App\Modules\Rotina\Models\Project;
use App\Modules\Rotina\Models\Task;
use App\Modules\Rotina\RotinaModule;
use App\Modules\Rotina\Services\RotinaAccess;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "My day": the person's tasks (the manager sees everyone's).
 */
class TaskResource extends ModuleResource
{
    protected static string $moduleKey = RotinaModule::KEY;

    protected static ?string $model = Task::class;

    protected static ?string $langGroup = 'rotina.tasks';

    protected static ?string $slug = 'rotina/tarefas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('rotina.tasks.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rotina.tasks.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['project', 'user']);

        return app(RotinaAccess::class)->isManager() ? $query : $query->where('user_id', auth()->id());
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('title')->label(__('rotina.fields.task'))->required()->maxLength(255),
                DatePicker::make('due_on')->label(__('rotina.fields.due_on'))->default(now()->toDateString()),
                Select::make('project_id')
                    ->label(__('rotina.fields.project'))
                    ->options(fn (): array => Project::query()->active()->orderBy('name')->get()->mapWithKeys(fn (Project $project): array => [$project->getKey() => $project->label()])->all())
                    ->searchable(),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('due_on', 'desc')
            ->columns([
                IconColumn::make('done_at')->label(__('rotina.fields.done'))->state(fn (Task $record): bool => $record->done_at !== null)->boolean()->falseIcon(Heroicon::OutlinedClock)->falseColor('gray'),
                TextColumn::make('title')->label(__('rotina.fields.task'))->searchable()->weight('bold'),
                TextColumn::make('project.name')->label(__('rotina.fields.project'))->placeholder('—'),
                TextColumn::make('user.name')->label(__('rotina.fields.person'))->visible(fn (): bool => app(RotinaAccess::class)->isManager()),
                TextColumn::make('due_on')->label(__('rotina.fields.due_on'))->date(__('app.date_format'))->sortable(),
            ])
            ->filters([
                TernaryFilter::make('done')->label(__('rotina.fields.done'))->queries(
                    true: fn (Builder $query) => $query->whereNotNull('done_at'),
                    false: fn (Builder $query) => $query->whereNull('done_at'),
                    blank: fn (Builder $query) => $query,
                )->default(false),
            ])
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (Task $record): string => $record->done_at ? __('rotina.actions.reopen') : __('rotina.actions.done'))
                    ->icon(fn (Task $record): Heroicon => $record->done_at ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedCheck)
                    ->color(fn (Task $record): string => $record->done_at ? 'gray' : 'success')
                    ->authorize('update')
                    ->action(fn (Task $record) => $record->update(['done_at' => $record->done_at ? null : now()])),
                Action::make('tomorrow')
                    ->label(__('rotina.actions.tomorrow'))
                    ->icon(Heroicon::OutlinedArrowRight)
                    ->color('gray')
                    ->visible(fn (Task $record): bool => $record->done_at === null)
                    ->authorize('update')
                    ->action(fn (Task $record) => $record->update(['due_on' => ($record->due_on && $record->due_on->isFuture() ? $record->due_on : now())->addDay()->toDateString()])),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('rotina.tasks.empty'))
            ->emptyStateDescription(__('rotina.tasks.empty_help'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageTasks::route('/')];
    }
}
