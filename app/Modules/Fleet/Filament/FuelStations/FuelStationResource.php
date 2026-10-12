<?php

namespace App\Modules\Fleet\Filament\FuelStations;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\FuelStation;
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

class FuelStationResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = FuelStation::class;

    protected static ?string $slug = 'frota/postos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMapPin;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('fleet.stations.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.stations.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                TextInput::make('name')
                    ->label(__('fleet.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('document')
                    ->label(__('fleet.fields.station_document'))
                    ->maxLength(30),
                TextInput::make('address')
                    ->label(__('fleet.fields.address'))
                    ->maxLength(255),
                TextInput::make('city')
                    ->label(__('fleet.fields.city'))
                    ->maxLength(80),
                TextInput::make('phone')
                    ->label(__('fleet.fields.phone'))
                    ->tel()
                    ->maxLength(30),
                Toggle::make('is_active')
                    ->label(__('fleet.fields.is_active'))
                    ->default(true)
                    ->inline(false),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('fleet.fields.name'))
                    ->description(fn (FuelStation $record): ?string => collect([$record->address, $record->city])->filter()->implode(' · ') ?: null)
                    ->searchable(['name', 'city']),
                TextColumn::make('document')
                    ->label(__('fleet.fields.station_document'))
                    ->placeholder('—'),
                TextColumn::make('phone')
                    ->label(__('fleet.fields.phone'))
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label(__('fleet.fields.is_active'))
                    ->boolean(),
            ])
            ->emptyStateHeading(__('fleet.stations.empty'))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageFuelStations::route('/'),
        ];
    }
}
