<?php

namespace App\Modules\Fleet\Filament\FuelUps;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Enums\FuelUpFlag;
use App\Modules\Fleet\Enums\FuelUpStatus;
use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\Filament\FuelUps\Pages\CreateFuelUp;
use App\Modules\Fleet\Filament\FuelUps\Pages\ListFuelUps;
use App\Modules\Fleet\Filament\FuelUps\Pages\RequestFuelCode;
use App\Modules\Fleet\Filament\FuelUps\Pages\ViewFuelUp;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\FuelUp;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetFormat;
use App\Modules\Fleet\Services\FuelService;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class FuelUpResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = FuelUp::class;

    protected static ?string $langGroup = 'fleet.fuel';

    protected static ?string $slug = 'frota/abastecimentos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;

    protected static ?int $navigationSort = 2;

    public static function getModelLabel(): string
    {
        return __('fleet.fuel.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.fuel.plural');
    }

    /** Menu by role: drivers see their vehicles on the start page; mechanics work with maintenance. */
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && app(FleetAccess::class)->fleetRole() !== FleetRole::Mechanic;
    }

    /** Drivers see the fuel-ups of their vehicles and the ones they made. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['vehicle', 'driver', 'station', 'creator']);
        $visible = app(FleetAccess::class)->visibleVehicleIds();

        return $visible === null
            ? $query
            : $query->where(fn (Builder $query) => $query->whereIn('vehicle_id', $visible)->orWhere('created_by', Auth::id()));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(3)
            ->components([
                Section::make(__('fleet.fuel.sections.code'))
                    ->visible(fn (FuelUp $record): bool => $record->isOpen())
                    ->columnSpanFull()
                    ->columns(3)
                    ->components([
                        TextEntry::make('code')
                            ->hiddenLabel()
                            ->formatStateUsing(fn (?string $state): string => FuelService::formatCode($state))
                            ->size('lg')
                            ->weight('bold')
                            ->extraAttributes(['style' => 'font-size:2.6rem;letter-spacing:.2em;font-family:monospace'])
                            ->helperText(fn (FuelUp $record): string => $record->codeIsValid()
                                ? __('fleet.fuel.code_valid_until', ['time' => $record->code_expires_at->timezone(FleetFields::timezone())->format('H:i')])
                                : __('fleet.fuel.code_expired')),
                        TextEntry::make('station_checked_at')
                            ->label(__('fleet.fuel.station_checked'))
                            ->dateTime('H:i')
                            ->placeholder(__('fleet.fuel.not_checked_yet')),
                        TextEntry::make('station_page')
                            ->label(__('fleet.fuel.station_page'))
                            ->state(fn (): string => url('/posto'))
                            ->url(fn (): string => url('/posto'))
                            ->openUrlInNewTab()
                            ->helperText(__('fleet.fuel.station_page_help')),
                    ]),
                Section::make(__('fleet.fuel.sections.fuel_up'))
                    ->columnSpan(2)
                    ->columns(3)
                    ->components([
                        TextEntry::make('status')->label(__('fleet.fields.status'))->badge(),
                        TextEntry::make('vehicle.plate')->label(__('fleet.fields.vehicle'))
                            ->formatStateUsing(fn (FuelUp $record): string => $record->vehicle->displayName()),
                        TextEntry::make('person')->label(__('fleet.fuel.who'))
                            ->state(fn (FuelUp $record): string => $record->driver?->name ?? $record->creator?->name ?? '—'),
                        TextEntry::make('station.name')->label(__('fleet.stations.singular_title'))->placeholder('—'),
                        TextEntry::make('odometer')->label(__('fleet.fields.odometer'))
                            ->formatStateUsing(fn (int $state): string => FleetFormat::distance($state)),
                        TextEntry::make('fueled_at')->label(__('fleet.fields.date'))
                            ->dateTime(__('app.datetime_format'))
                            ->placeholder('—'),
                        TextEntry::make('fuel')->label(__('fleet.fields.fuel'))->placeholder('—'),
                        TextEntry::make('volume')->label(__('fleet.fields.volume'))
                            ->formatStateUsing(fn ($state): string => FleetFormat::volume($state === null ? null : (float) $state))
                            ->placeholder('—'),
                        TextEntry::make('total_cents')->label(__('fleet.fields.total'))
                            ->formatStateUsing(fn (?int $state): string => FleetFormat::money($state))
                            ->placeholder('—'),
                        TextEntry::make('unit_price')->label(__('fleet.fields.unit_price'))
                            ->formatStateUsing(fn ($state): string => $state === null ? '—' : FleetFormat::money((int) round((float) $state * 100)).' / '.FleetSetting::current()->volume_unit)
                            ->placeholder('—'),
                        TextEntry::make('full_tank')->label(__('fleet.fields.full_tank'))
                            ->formatStateUsing(fn (bool $state): string => $state ? __('fleet.yes') : __('fleet.no')),
                        TextEntry::make('consumption')->label(__('fleet.fields.consumption'))
                            ->formatStateUsing(fn ($state): string => FleetFormat::consumption($state === null ? null : (float) $state))
                            ->placeholder(__('fleet.fuel.consumption_pending')),
                        TextEntry::make('flags')->label(__('fleet.fuel.warnings'))
                            ->state(fn (FuelUp $record): array => array_map(fn (FuelUpFlag $flag): string => $flag->getLabel(), $record->flagList()))
                            ->badge()
                            ->color('danger')
                            ->placeholder(__('fleet.fuel.no_warnings'))
                            ->columnSpan(2),
                        TextEntry::make('reason')->label(__('fleet.fuel.other_vehicle_reason'))
                            ->visible(fn (FuelUp $record): bool => filled($record->reason))
                            ->columnSpanFull(),
                        TextEntry::make('cancel_reason')->label(__('fleet.fields.reason'))
                            ->visible(fn (FuelUp $record): bool => filled($record->cancel_reason))
                            ->columnSpanFull(),
                    ]),
                Section::make(__('fleet.fuel.sections.photos'))
                    ->columnSpan(1)
                    ->components([
                        ImageEntry::make('odometer_photo_path')
                            ->label(__('fleet.fuel.odometer_photo'))
                            ->disk('local')
                            ->visibility('private')
                            ->imageHeight(140)
                            ->placeholder('—'),
                        ImageEntry::make('receipt_path')
                            ->label(__('fleet.fuel.receipt_photo'))
                            ->disk('local')
                            ->visibility('private')
                            ->imageHeight(140)
                            ->placeholder('—'),
                    ]),
            ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('fleet.fields.date'))
                    ->state(fn (FuelUp $record) => $record->fueled_at ?? $record->created_at)
                    ->dateTime(__('app.datetime_format'))
                    ->sortable(),
                TextColumn::make('vehicle.plate')
                    ->label(__('fleet.fields.vehicle'))
                    ->weight('bold')
                    ->description(fn (FuelUp $record): string => $record->driver?->name ?? $record->creator?->name ?? '—')
                    ->searchable(),
                TextColumn::make('station.name')
                    ->label(__('fleet.stations.singular_title'))
                    ->visibleFrom('lg')
                    ->placeholder('—'),
                TextColumn::make('odometer')
                    ->label(__('fleet.fields.odometer'))
                    ->formatStateUsing(fn (int $state): string => FleetFormat::distance($state))
                    ->visibleFrom('md')
                    ->alignEnd(),
                TextColumn::make('volume')
                    ->label(__('fleet.fields.volume'))
                    ->formatStateUsing(fn ($state): string => FleetFormat::volume($state === null ? null : (float) $state))
                    ->visibleFrom('md')
                    ->alignEnd(),
                TextColumn::make('total_cents')
                    ->label(__('fleet.fields.total'))
                    ->formatStateUsing(fn (?int $state): string => FleetFormat::money($state))
                    ->placeholder('—')
                    ->alignEnd(),
                TextColumn::make('consumption')
                    ->label(__('fleet.fields.consumption'))
                    ->formatStateUsing(fn ($state): string => FleetFormat::consumption($state === null ? null : (float) $state))
                    ->visibleFrom('md')
                    ->placeholder('—')
                    ->alignEnd(),
                IconColumn::make('warnings')
                    ->label('')
                    ->state(fn (FuelUp $record): bool => $record->flagList() !== [])
                    ->icon(fn (bool $state): ?Heroicon => $state ? Heroicon::OutlinedExclamationTriangle : null)
                    ->color('danger')
                    ->tooltip(fn (FuelUp $record): ?string => implode(' · ', array_map(fn (FuelUpFlag $flag): string => $flag->getLabel(), $record->flagList())) ?: null),
                TextColumn::make('status')
                    ->label(__('fleet.fields.status'))
                    ->badge(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('fleet.fields.status'))
                    ->options(FuelUpStatus::class),
                SelectFilter::make('vehicle_id')
                    ->label(__('fleet.fields.vehicle'))
                    ->relationship('vehicle', 'plate')
                    ->searchable(),
                Filter::make('flagged')
                    ->label(__('fleet.fuel.only_warnings'))
                    ->query(fn (Builder $query) => $query->whereRaw("jsonb_array_length(COALESCE(flags::jsonb, '[]'::jsonb)) > 0")),
            ])
            ->recordUrl(fn (FuelUp $record): string => static::getUrl('view', ['record' => $record]))
            ->recordActions([
                ViewAction::make()->link(),
            ])
            ->emptyStateHeading(__('fleet.fuel.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFuelUps::route('/'),
            'create' => CreateFuelUp::route('/lancar'),
            'request' => RequestFuelCode::route('/gerar-codigo'),
            'view' => ViewFuelUp::route('/{record}'),
        ];
    }

    /**
     * Fuels for the "fuel" select: the ones the vehicle accepts first.
     *
     * @return array<string, string>
     */
    public static function fuelOptions(): array
    {
        return collect(FuelType::pumpFuels())->mapWithKeys(fn (FuelType $fuel): array => [$fuel->value => $fuel->getLabel()])->all();
    }
}
