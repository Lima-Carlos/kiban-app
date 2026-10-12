<?php

namespace App\Modules\Fleet\Filament\Drivers;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\FleetSetting;
use BackedEnum;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DriverResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = Driver::class;

    protected static ?string $slug = 'frota/motoristas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('fleet.drivers.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.drivers.plural');
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
                TextInput::make('email')
                    ->label(__('fleet.fields.email'))
                    ->email()
                    ->maxLength(255)
                    ->unique(Driver::class, 'email', ignoreRecord: true, modifyRuleUsing: FleetFields::sameTenant())
                    ->helperText(__('fleet.helpers.driver_email')),
                TextInput::make('phone')
                    ->label(__('fleet.fields.phone'))
                    ->tel()
                    ->maxLength(30),
                TextInput::make('document')
                    ->label(__('fleet.fields.document'))
                    ->maxLength(30),
                TextInput::make('license_number')
                    ->label(__('fleet.fields.license_number'))
                    ->maxLength(30),
                TextInput::make('license_category')
                    ->label(__('fleet.fields.license_category'))
                    ->maxLength(10),
                DatePicker::make('license_expires_on')
                    ->label(__('fleet.fields.license_expires_on')),
                Toggle::make('is_active')
                    ->label(__('fleet.fields.is_active'))
                    ->default(true)
                    ->inline(false),
                Toggle::make('any_vehicle')
                    ->label(__('fleet.fields.any_vehicle'))
                    ->helperText(__('fleet.helpers.any_vehicle'))
                    ->inline(false),
                Select::make('vehicles')
                    ->label(__('fleet.fields.vehicles'))
                    ->relationship('vehicles', 'plate', fn (Builder $query) => $query->where('status', '!=', 'inactive'))
                    ->getOptionLabelFromRecordUsing(fn ($record): string => $record->displayName())
                    ->multiple()
                    ->preload()
                    ->columnSpanFull()
                    ->helperText(__('fleet.helpers.driver_vehicles')),
                Textarea::make('notes')
                    ->label(__('fleet.fields.notes'))
                    ->rows(2)
                    ->columnSpanFull(),
            ]);
    }

    public static function table(Table $table): Table
    {
        $settings = FleetSetting::current();

        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('fleet.fields.name'))
                    ->description(fn (Driver $record): ?string => $record->email)
                    ->searchable(['name', 'email']),
                IconColumn::make('user_id')
                    ->label(__('fleet.fields.app_access'))
                    ->state(fn (Driver $record): bool => $record->user_id !== null)
                    ->boolean()
                    ->falseIcon(Heroicon::OutlinedClock)
                    ->falseColor('gray')
                    ->tooltip(fn (Driver $record): string => $record->user_id ? __('fleet.helpers.access_linked') : __('fleet.helpers.access_pending')),
                TextColumn::make('vehicles.plate')
                    ->label(__('fleet.fields.vehicles'))
                    ->badge()
                    ->placeholder('—'),
                TextColumn::make('license_expires_on')
                    ->label(__('fleet.fields.license_expires_on'))
                    ->date(__('app.date_format'))
                    ->color(fn (Driver $record): ?string => match (true) {
                        $record->license_expires_on === null => null,
                        $record->license_expires_on->isPast() => 'danger',
                        $record->license_expires_on->lte(now()->addDays($settings->alert_days)) => 'warning',
                        default => null,
                    })
                    ->placeholder('—'),
                IconColumn::make('is_active')
                    ->label(__('fleet.fields.is_active'))
                    ->boolean(),
            ])
            ->emptyStateHeading(__('fleet.drivers.empty'))
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageDrivers::route('/'),
        ];
    }
}
