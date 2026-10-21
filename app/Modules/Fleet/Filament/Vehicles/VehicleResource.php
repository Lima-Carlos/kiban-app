<?php

namespace App\Modules\Fleet\Filament\Vehicles;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Enums\VehicleStatus;
use App\Modules\Fleet\Enums\VehicleType;
use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\Filament\Vehicles\Pages\CreateVehicle;
use App\Modules\Fleet\Filament\Vehicles\Pages\EditVehicle;
use App\Modules\Fleet\Filament\Vehicles\Pages\ListVehicles;
use App\Modules\Fleet\Filament\Vehicles\Pages\ViewVehicle;
use App\Modules\Fleet\Filament\Vehicles\RelationManagers\AppointmentsRelationManager;
use App\Modules\Fleet\Filament\Vehicles\RelationManagers\DocumentsRelationManager;
use App\Modules\Fleet\Filament\Vehicles\RelationManagers\EventsRelationManager;
use App\Modules\Fleet\Filament\Vehicles\RelationManagers\ExpensesRelationManager;
use App\Modules\Fleet\Filament\Vehicles\RelationManagers\InspectionsRelationManager;
use App\Modules\Fleet\Filament\Vehicles\RelationManagers\MaintenancesRelationManager;
use App\Modules\Fleet\Filament\Vehicles\RelationManagers\OdometerReadingsRelationManager;
use App\Modules\Fleet\Filament\Vehicles\RelationManagers\WashesRelationManager;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Rules\UniquePlate;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetFormat;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class VehicleResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = Vehicle::class;

    protected static ?string $slug = 'frota/veiculos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'plate';

    public static function getModelLabel(): string
    {
        return __('fleet.vehicles.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.vehicles.plural');
    }

    /** Menu by role: drivers see their vehicles on the start page; mechanics work with maintenance. */
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && app(FleetAccess::class)->fleetRole() !== FleetRole::Driver;
    }

    /** Drivers only see the vehicles assigned to them. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();
        $visible = app(FleetAccess::class)->visibleVehicleIds();

        return $visible === null ? $query : $query->whereKey($visible);
    }

    public static function form(Schema $schema): Schema
    {
        $settings = FleetSetting::current();
        $locked = fn (?Vehicle $record): bool => $record?->identity_locked_at !== null;

        return $schema
            ->columns(1)
            ->components([
                Section::make(__('fleet.vehicles.sections.identity'))
                    ->columns(3)
                    ->components([
                        TextInput::make('plate')
                            ->label(__('fleet.fields.plate'))
                            ->required()
                            ->maxLength(12)
                            ->regex('/^[A-Za-z0-9\- ]{2,12}$/')
                            ->rule(fn (?Vehicle $record): UniquePlate => new UniquePlate($record))
                            ->dehydrateStateUsing(fn (?string $state): ?string => $state ? Vehicle::normalizePlate($state) : null)
                            ->disabled($locked)
                            ->helperText(fn (?Vehicle $record): ?string => $locked($record) ? __('fleet.helpers.identity_locked') : null),
                        TextInput::make('vin')
                            ->label(__('fleet.fields.vin'))
                            ->maxLength(30)
                            ->unique(Vehicle::class, 'vin', ignoreRecord: true, modifyRuleUsing: FleetFields::sameTenant())
                            ->disabled($locked),
                        TextInput::make('registration')
                            ->label(__('fleet.fields.registration'))
                            ->maxLength(30),
                        TextInput::make('brand')
                            ->label(__('fleet.fields.brand'))
                            ->required()
                            ->maxLength(60),
                        TextInput::make('model')
                            ->label(__('fleet.fields.model'))
                            ->required()
                            ->maxLength(80),
                        TextInput::make('color')
                            ->label(__('fleet.fields.color'))
                            ->maxLength(40),
                        TextInput::make('manufacture_year')
                            ->label(__('fleet.fields.manufacture_year'))
                            ->integer()
                            ->minValue(1950)
                            ->maxValue((int) now()->year + 1),
                        TextInput::make('model_year')
                            ->label(__('fleet.fields.model_year'))
                            ->integer()
                            ->minValue(1950)
                            ->maxValue((int) now()->year + 2),
                        Select::make('type')
                            ->label(__('fleet.fields.type'))
                            ->options(VehicleType::class)
                            ->default(VehicleType::Car->value)
                            ->required(),
                        Select::make('fuel_type')
                            ->label(__('fleet.fields.fuel_type'))
                            ->options(FuelType::class)
                            ->default(FuelType::Flex->value)
                            ->required(),
                        TextInput::make('tank_capacity')
                            ->label(__('fleet.fields.tank_capacity'))
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(2000)
                            ->suffix($settings->volume_unit)
                            ->helperText(__('fleet.helpers.tank_capacity')),
                        FleetFields::photo('photo_path', 'vehicles')
                            ->label(__('fleet.fields.photo')),
                    ]),
                Section::make(__('fleet.vehicles.sections.odometer'))
                    ->description(__('fleet.helpers.odometer'))
                    ->visibleOn('create')
                    ->components([
                        TextInput::make('odometer')
                            ->label(__('fleet.fields.odometer'))
                            ->integer()
                            ->minValue(0)
                            ->maxValue(9_999_999)
                            ->required()
                            ->suffix($settings->distance_unit),
                    ]),
                Section::make(__('fleet.vehicles.sections.oil'))
                    ->description(__('fleet.helpers.oil'))
                    ->columns(4)
                    ->components(FleetFields::maintenancePlan('oil_change', $settings)),
                Section::make(__('fleet.vehicles.sections.revision'))
                    ->description(__('fleet.helpers.revision'))
                    ->columns(4)
                    ->components(FleetFields::maintenancePlan('revision', $settings)),
                Section::make(__('fleet.vehicles.sections.drivers'))
                    ->components([
                        Select::make('drivers')
                            ->label(__('fleet.fields.drivers'))
                            ->relationship('drivers', 'name', fn (Builder $query) => $query->where('is_active', true))
                            ->multiple()
                            ->preload()
                            ->helperText(__('fleet.helpers.vehicle_drivers')),
                        Textarea::make('notes')
                            ->label(__('fleet.fields.notes'))
                            ->rows(2),
                    ]),
            ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(__('fleet.vehicles.sections.situation'))
                    ->columnSpan(2)
                    ->columns(2)
                    ->components([
                        TextEntry::make('status')
                            ->label(__('fleet.fields.status'))
                            ->badge(),
                        TextEntry::make('odometer')
                            ->label(__('fleet.fields.odometer'))
                            ->formatStateUsing(fn (int $state): string => FleetFormat::distance($state))
                            ->helperText(fn (Vehicle $record): ?string => $record->odometer_updated_at
                                ? __('fleet.helpers.odometer_updated', ['date' => $record->odometer_updated_at->timezone(FleetFields::timezone())->translatedFormat(__('app.datetime_format'))])
                                : null)
                            ->weight('bold')
                            ->size('lg'),
                        TextEntry::make('oil_status')
                            ->label(__('fleet.vehicles.sections.oil'))
                            ->state(fn (Vehicle $record) => $record->oilChangeStatus())
                            ->badge()
                            ->helperText(fn (Vehicle $record): string => FleetFields::dueText($record->next_oil_change_odometer, $record->next_oil_change_on)),
                        TextEntry::make('revision_status')
                            ->label(__('fleet.vehicles.sections.revision'))
                            ->state(fn (Vehicle $record) => $record->revisionStatus())
                            ->badge()
                            ->helperText(fn (Vehicle $record): string => FleetFields::dueText($record->next_revision_odometer, $record->next_revision_on)),
                    ]),
                Section::make()
                    ->columnSpan(1)
                    ->components([
                        ImageEntry::make('photo_path')
                            ->hiddenLabel()
                            ->disk('local')
                            ->visibility('private')
                            ->imageHeight(160)
                            ->placeholder(__('fleet.helpers.no_photo')),
                    ]),
                Section::make(__('fleet.vehicles.sections.identity'))
                    ->columnSpanFull()
                    ->columns(4)
                    ->components([
                        TextEntry::make('plate')->label(__('fleet.fields.plate'))->weight('bold'),
                        TextEntry::make('brand')->label(__('fleet.fields.brand'))
                            ->formatStateUsing(fn (Vehicle $record): string => "{$record->brand} {$record->model}"),
                        TextEntry::make('type')->label(__('fleet.fields.type')),
                        TextEntry::make('fuel_type')->label(__('fleet.fields.fuel_type')),
                        TextEntry::make('manufacture_year')->label(__('fleet.fields.year'))
                            ->state(fn (Vehicle $record): string => trim(($record->manufacture_year ?? '').'/'.($record->model_year ?? ''), '/') ?: '—'),
                        TextEntry::make('color')->label(__('fleet.fields.color'))->placeholder('—'),
                        TextEntry::make('vin')->label(__('fleet.fields.vin'))->placeholder('—'),
                        TextEntry::make('registration')->label(__('fleet.fields.registration'))->placeholder('—'),
                        TextEntry::make('drivers.name')->label(__('fleet.fields.drivers'))->badge()->placeholder('—'),
                        TextEntry::make('notes')->label(__('fleet.fields.notes'))->placeholder('—')->columnSpan(3),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('plate')
            ->columns([
                ImageColumn::make('photo_path')
                    ->label('')
                    ->visibleFrom('sm')
                    ->disk('local')
                    ->visibility('private')
                    ->imageHeight(40)
                    ->square(),
                TextColumn::make('plate')
                    ->label(__('fleet.fields.plate'))
                    ->weight('bold')
                    ->description(fn (Vehicle $record): string => "{$record->brand} {$record->model}")
                    ->searchable(['plate', 'brand', 'model']),
                TextColumn::make('odometer')
                    ->label(__('fleet.fields.odometer'))
                    ->formatStateUsing(fn (int $state): string => FleetFormat::distance($state))
                    ->sortable()
                    ->alignEnd(),
                TextColumn::make('oil_status')
                    ->label(__('fleet.fields.oil_short'))
                    ->visibleFrom('md')
                    ->state(fn (Vehicle $record) => $record->oilChangeStatus())
                    ->badge()
                    ->tooltip(fn (Vehicle $record): string => FleetFields::dueText($record->next_oil_change_odometer, $record->next_oil_change_on)),
                TextColumn::make('revision_status')
                    ->label(__('fleet.fields.revision_short'))
                    ->visibleFrom('md')
                    ->state(fn (Vehicle $record) => $record->revisionStatus())
                    ->badge()
                    ->tooltip(fn (Vehicle $record): string => FleetFields::dueText($record->next_revision_odometer, $record->next_revision_on)),
                TextColumn::make('drivers.name')
                    ->label(__('fleet.fields.drivers'))
                    ->visibleFrom('md')
                    ->listWithLineBreaks()
                    ->limitList(2)
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('fleet.fields.status'))
                    ->visibleFrom('md')
                    ->badge(),
            ])
            ->filters([
                TernaryFilter::make('inactive')
                    ->label(__('fleet.filters.inactive'))
                    ->placeholder(__('fleet.filters.without_inactive'))
                    ->trueLabel(__('fleet.filters.with_inactive'))
                    ->falseLabel(__('fleet.filters.only_inactive'))
                    ->queries(
                        true: fn (Builder $query) => $query,
                        false: fn (Builder $query) => $query->where('status', VehicleStatus::Inactive->value),
                        blank: fn (Builder $query) => $query->where('status', '!=', VehicleStatus::Inactive->value),
                    ),
                SelectFilter::make('type')
                    ->label(__('fleet.fields.type'))
                    ->options(VehicleType::class),
            ])
            ->emptyStateHeading(__('fleet.vehicles.empty'))
            ->recordUrl(fn (Vehicle $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->iconButton(),
                EditAction::make()->iconButton(),
            ]);
    }

    public static function getRelations(): array
    {
        return [
            MaintenancesRelationManager::class,
            AppointmentsRelationManager::class,
            InspectionsRelationManager::class,
            OdometerReadingsRelationManager::class,
            WashesRelationManager::class,
            ExpensesRelationManager::class,
            DocumentsRelationManager::class,
            EventsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVehicles::route('/'),
            'create' => CreateVehicle::route('/novo'),
            'view' => ViewVehicle::route('/{record}'),
            'edit' => EditVehicle::route('/{record}/editar'),
        ];
    }
}
