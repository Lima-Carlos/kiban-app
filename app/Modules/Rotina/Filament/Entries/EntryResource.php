<?php

namespace App\Modules\Rotina\Filament\Entries;

use App\Filament\Support\ModuleResource;
use App\Modules\Rotina\Filament\Entries\Pages\ManageEntries;
use App\Modules\Rotina\Models\Entry;
use App\Modules\Rotina\Models\Project;
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
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Hours per project: typed, or from the stopwatch (start / stop). Everyone sees only their own hours; the
 * manager sees everyone's.
 */
class EntryResource extends ModuleResource
{
    protected static string $moduleKey = RotinaModule::KEY;

    protected static ?string $model = Entry::class;

    protected static ?string $langGroup = 'rotina.entries';

    protected static ?string $slug = 'rotina/horas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('rotina.entries.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rotina.entries.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['project', 'user']);

        return app(RotinaAccess::class)->isManager() ? $query : $query->where('user_id', auth()->id());
    }

    /**
     * @return array<int, string>
     */
    public static function projectOptions(): array
    {
        return Project::query()->active()->orderBy('name')->get()->mapWithKeys(fn (Project $project): array => [$project->getKey() => $project->label()])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'sm' => 2])
            ->components([
                DatePicker::make('worked_on')->label(__('rotina.fields.worked_on'))->default(now()->toDateString())->required()->maxDate(now()),
                Select::make('project_id')->label(__('rotina.fields.project'))->options(fn (): array => static::projectOptions())->searchable(),
                TextInput::make('hours')->label(__('rotina.fields.hours'))->numeric()->integer()->minValue(0)->maxValue(24)->default(0)->suffix('h')->required(),
                TextInput::make('minutes_part')->label(__('rotina.fields.minutes'))->numeric()->integer()->minValue(0)->maxValue(59)->default(0)->suffix('min')->required(),
                TextInput::make('note')->label(__('rotina.fields.note'))->maxLength(255)->columnSpanFull(),
            ]);
    }

    /**
     * The form has hours and minutes; the table keeps minutes.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function withMinutes(array $data): array
    {
        $data['minutes'] = max(0, (int) ($data['hours'] ?? 0)) * 60 + max(0, min(59, (int) ($data['minutes_part'] ?? 0)));
        unset($data['hours'], $data['minutes_part']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('worked_on', 'desc')
            ->columns([
                TextColumn::make('worked_on')->label(__('rotina.fields.worked_on'))->date(__('app.date_format'))->sortable(),
                TextColumn::make('project.name')->label(__('rotina.fields.project'))->placeholder('—'),
                TextColumn::make('time')->label(__('rotina.fields.time'))
                    ->state(fn (Entry $record): string => $record->started_at ? __('rotina.entries.running') : Entry::format($record->minutes))
                    ->weight('bold'),
                TextColumn::make('note')->label(__('rotina.fields.note'))->limit(40)->placeholder('—'),
                TextColumn::make('user.name')->label(__('rotina.fields.person'))->visible(fn (): bool => app(RotinaAccess::class)->isManager()),
            ])
            ->filters([
                SelectFilter::make('project_id')->label(__('rotina.fields.project'))->options(fn (): array => static::projectOptions()),
            ])
            ->recordActions([
                Action::make('stop')
                    ->label(__('rotina.actions.stop'))
                    ->icon(Heroicon::OutlinedStop)
                    ->color('danger')
                    ->visible(fn (Entry $record): bool => $record->started_at !== null)
                    ->authorize('update')
                    ->action(fn (Entry $record) => $record->stop()),
                EditAction::make()
                    ->visible(fn (Entry $record): bool => $record->started_at === null)
                    ->mutateRecordDataUsing(fn (array $data): array => $data + ['hours' => intdiv((int) $data['minutes'], 60), 'minutes_part' => (int) $data['minutes'] % 60])
                    ->mutateDataUsing(fn (array $data): array => static::withMinutes($data)),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('rotina.entries.empty'))
            ->emptyStateDescription(__('rotina.entries.empty_help'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageEntries::route('/')];
    }
}
