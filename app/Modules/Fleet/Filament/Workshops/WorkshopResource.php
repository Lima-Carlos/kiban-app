<?php

namespace App\Modules\Fleet\Filament\Workshops;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Workshop;
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
 * Outside workshops the company uses (the company's own mechanic is a person in "People").
 */
class WorkshopResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = Workshop::class;

    protected static ?string $slug = 'frota/oficinas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingStorefront;

    protected static ?int $navigationSort = 12;

    public static function getModelLabel(): string
    {
        return __('fleet.workshops.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.workshops.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')->label(__('fleet.fields.name'))->required()->maxLength(255),
                TextInput::make('document')->label(__('fleet.fields.station_document'))->maxLength(30),
                TextInput::make('phone')->label(__('fleet.fields.phone'))->tel()->maxLength(30),
                TextInput::make('city')->label(__('fleet.fields.city'))->maxLength(80),
                TextInput::make('address')->label(__('fleet.fields.address'))->maxLength(255)->columnSpanFull(),
                Toggle::make('is_active')->label(__('fleet.fields.is_active'))->default(true),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('fleet.fields.name'))->weight('bold')
                    ->description(fn (Workshop $record): ?string => collect([$record->address, $record->city])->filter()->implode(' · ') ?: null)
                    ->searchable(),
                TextColumn::make('phone')->label(__('fleet.fields.phone'))->placeholder('—'),
                IconColumn::make('is_active')->label(__('fleet.fields.is_active'))->boolean(),
            ])
            ->recordActions([EditAction::make()->iconButton(), DeleteAction::make()->iconButton()])
            ->emptyStateHeading(__('fleet.workshops.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageWorkshops::route('/'),
        ];
    }
}
