<?php

namespace App\Modules\Demo\Filament;

use App\Filament\Support\ModuleResource;
use App\Modules\Demo\Filament\Pages\ManageTaskLists;
use App\Modules\Demo\Models\TaskList;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class TaskListResource extends ModuleResource
{
    protected static string $moduleKey = 'demo';

    protected static ?string $model = TaskList::class;

    protected static ?string $slug = 'demo/lists';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?int $navigationSort = 1;

    public static function getModelLabel(): string
    {
        return __('demo.lists.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('demo.lists.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->label(__('demo.fields.name'))
                ->required()
                ->maxLength(255),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('demo.fields.name'))
                    ->searchable(),
                TextColumn::make('tasks_count')
                    ->label(__('demo.fields.tasks'))
                    ->counts('tasks')
                    ->alignEnd(),
            ])
            ->emptyStateHeading(__('demo.lists.empty'))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ManageTaskLists::route('/'),
        ];
    }
}
