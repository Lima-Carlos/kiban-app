<?php

namespace App\Modules\Demo\Filament;

use App\Filament\Support\ModuleResource;
use App\Modules\Demo\Filament\Pages\ManageTasks;
use App\Modules\Demo\Models\Task;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class TaskResource extends ModuleResource
{
    protected static string $moduleKey = 'demo';

    protected static ?string $model = Task::class;

    protected static ?string $slug = 'demo/tasks';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('demo.tasks.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('demo.tasks.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('task_list_id')
                    ->label(__('demo.fields.list'))
                    ->relationship('list', 'name')
                    ->required()
                    ->preload(),
                DatePicker::make('due_on')
                    ->label(__('demo.fields.due_on')),
                TextInput::make('title')
                    ->label(__('demo.fields.title'))
                    ->required()
                    ->maxLength(255)
                    ->columnSpanFull(),
                Textarea::make('notes')
                    ->label(__('demo.fields.notes'))
                    ->rows(3)
                    ->columnSpanFull(),
                Toggle::make('completed')
                    ->label(__('demo.fields.completed')),
            ]);
    }

    /**
     * The "completed" switch becomes the completion date.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepareData(array $data, ?Task $record = null): array
    {
        $completed = (bool) ($data['completed'] ?? false);
        unset($data['completed']);
        $data['completed_at'] = $completed ? ($record?->completed_at ?? now()) : null;

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                IconColumn::make('completed_at')
                    ->label(__('demo.fields.completed'))
                    ->state(fn (Task $record): bool => $record->completed_at !== null)
                    ->boolean()
                    ->falseIcon(Heroicon::OutlinedClock)
                    ->falseColor('gray'),
                TextColumn::make('title')
                    ->label(__('demo.fields.title'))
                    ->description(fn (Task $record): ?string => $record->notes)
                    ->searchable(),
                TextColumn::make('list.name')
                    ->label(__('demo.fields.list')),
                TextColumn::make('due_on')
                    ->label(__('demo.fields.due_on'))
                    ->date(__('app.date_format'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('task_list_id')
                    ->label(__('demo.fields.list'))
                    ->relationship('list', 'name'),
                TernaryFilter::make('completed')
                    ->label(__('demo.fields.completed'))
                    ->queries(
                        true: fn (Builder $query) => $query->whereNotNull('completed_at'),
                        false: fn (Builder $query) => $query->whereNull('completed_at'),
                    ),
            ])
            ->emptyStateHeading(__('demo.tasks.empty'))
            ->recordActions([
                Action::make('toggle')
                    ->label(fn (Task $record): string => $record->completed_at ? __('demo.actions.reopen') : __('demo.actions.complete'))
                    ->icon(fn (Task $record): Heroicon => $record->completed_at ? Heroicon::OutlinedArrowUturnLeft : Heroicon::OutlinedCheck)
                    ->color(fn (Task $record): string => $record->completed_at ? 'gray' : 'success')
                    ->authorize('update')
                    ->action(fn (Task $record) => $record->update(['completed_at' => $record->completed_at ? null : now()])),
                EditAction::make()
                    ->mutateRecordDataUsing(fn (array $data): array => $data + ['completed' => filled($data['completed_at'] ?? null)])
                    ->mutateDataUsing(fn (array $data, Task $record): array => static::prepareData($data, $record)),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTasks::route('/'),
        ];
    }
}
