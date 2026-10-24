<?php

namespace App\Modules\TimeClock\Filament\Workplaces;

use App\Filament\Support\MapPoint;
use App\Filament\Support\ModuleResource;
use App\Modules\TimeClock\Models\Workplace;
use App\Modules\TimeClock\TimeClockModule;
use App\Support\Geo;
use BackedEnum;
use DateTimeZone;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Workplaces (managers): address, time zone and, optionally, the point on the map and the radius
 * where punches are expected (outside it they are flagged, never blocked).
 */
class WorkplaceResource extends ModuleResource
{
    protected static string $moduleKey = TimeClockModule::KEY;

    protected static ?string $model = Workplace::class;

    protected static ?string $slug = 'ponto/locais';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('timeclock.workplaces.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('timeclock.workplaces.plural');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('timeclock.workplaces.plural_title');
    }

    public static function getTitleCaseModelLabel(): string
    {
        return __('timeclock.workplaces.singular_title');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Grid::make(['default' => 1, 'sm' => 2])->components([
                    TextInput::make('name')
                        ->label(__('timeclock.fields.name'))
                        ->placeholder(__('timeclock.helpers.workplace_name'))
                        ->required()
                        ->maxLength(255),
                    Select::make('timezone')
                        ->label(__('timeclock.fields.timezone'))
                        ->helperText(__('timeclock.helpers.timezone'))
                        ->options(fn (): array => collect(DateTimeZone::listIdentifiers())->mapWithKeys(fn (string $zone): array => [$zone => str_replace('_', ' ', $zone)])->all())
                        ->default(fn (): string => FilamentTimezone::get())
                        ->searchable()
                        ->required(),
                    TextInput::make('address')
                        ->label(__('timeclock.fields.address'))
                        ->maxLength(255)
                        ->columnSpanFull(),
                    TextInput::make('city')
                        ->label(__('timeclock.fields.city'))
                        ->maxLength(80),
                    TextInput::make('state')
                        ->label(__('timeclock.fields.state'))
                        ->maxLength(40),
                ]),
                Section::make(__('timeclock.workplaces.map'))
                    ->description(__('timeclock.workplaces.map_help'))
                    ->components([
                        MapPoint::make(),
                        Select::make('radius_meters')
                            ->label(__('timeclock.fields.radius'))
                            ->placeholder(__('timeclock.helpers.no_radius'))
                            ->helperText(__('timeclock.helpers.radius'))
                            ->options(collect(Workplace::RADII)->mapWithKeys(fn (int $meters): array => [$meters => __('timeclock.helpers.meters', ['meters' => number_format($meters, 0, ',', '.')])])->all())
                            ->visible(fn (Get $get): bool => filled($get('latitude')) && filled($get('longitude'))),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('timeclock.fields.name'))
                    ->weight('bold')
                    ->description(fn (Workplace $record): ?string => collect([$record->address, $record->city, $record->state])->filter()->implode(' · ') ?: null)
                    ->searchable(),
                TextColumn::make('radius_meters')
                    ->label(__('timeclock.fields.radius'))
                    ->state(fn (Workplace $record): string => ! $record->hasPoint()
                        ? __('timeclock.helpers.no_point')
                        : ($record->radius_meters ? __('timeclock.helpers.meters', ['meters' => number_format($record->radius_meters, 0, ',', '.')]) : __('timeclock.helpers.no_radius')))
                    ->url(fn (Workplace $record): ?string => $record->hasPoint() ? Geo::mapUrl($record->latitude, $record->longitude) : null, shouldOpenInNewTab: true)
                    ->color(fn (Workplace $record): ?string => $record->hasPoint() ? 'primary' : 'gray'),
                TextColumn::make('employees_count')
                    ->label(__('timeclock.employees.plural_title'))
                    ->counts(['employees' => fn ($query) => $query->where('is_active', true)])
                    ->alignEnd(),
                ToggleColumn::make('is_active')
                    ->label(__('timeclock.fields.active')),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->emptyStateHeading(__('timeclock.workplaces.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageWorkplaces::route('/'),
        ];
    }
}
