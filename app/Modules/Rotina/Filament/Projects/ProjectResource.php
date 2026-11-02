<?php

namespace App\Modules\Rotina\Filament\Projects;

use App\Filament\Support\ModuleResource;
use App\Modules\Rotina\Filament\Projects\Pages\ManageProjects;
use App\Modules\Rotina\Models\Project;
use App\Modules\Rotina\RotinaModule;
use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Projects and clients the hours are counted on (the manager keeps them).
 */
class ProjectResource extends ModuleResource
{
    protected static string $moduleKey = RotinaModule::KEY;

    protected static ?string $model = Project::class;

    protected static ?string $langGroup = 'rotina.projects';

    protected static ?string $slug = 'rotina/projetos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolder;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('rotina.projects.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('rotina.projects.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')->label(__('rotina.fields.project'))->required()->maxLength(160),
                TextInput::make('client_name')->label(__('rotina.fields.client'))->helperText(__('rotina.helpers.client'))->maxLength(160),
                Toggle::make('is_active')->label(__('rotina.fields.active'))->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('rotina.fields.project'))->weight('bold')->searchable(),
                TextColumn::make('client_name')->label(__('rotina.fields.client'))->searchable()->placeholder('—'),
                IconColumn::make('is_active')->label(__('rotina.fields.active'))->boolean(),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->emptyStateHeading(__('rotina.projects.empty'))
            ->emptyStateDescription(__('rotina.projects.empty_help'));
    }

    public static function getPages(): array
    {
        return ['index' => ManageProjects::route('/')];
    }
}
