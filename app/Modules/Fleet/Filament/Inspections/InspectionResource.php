<?php

namespace App\Modules\Fleet\Filament\Inspections;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Inspection;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetFormat;
use BackedEnum;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Inspections: history with photos and the answer of each item. "Do inspection" is a phone-first page.
 */
class InspectionResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = Inspection::class;

    protected static ?string $langGroup = 'fleet.inspections';

    protected static ?string $slug = 'frota/vistorias';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 3;

    public static function getModelLabel(): string
    {
        return __('fleet.inspections.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.inspections.plural');
    }

    /** Drivers and managers do inspections; mechanics see the problems as requests. */
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && app(FleetAccess::class)->fleetRole() !== FleetRole::Mechanic;
    }

    /** Drivers see the inspections of their vehicles. */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['vehicle', 'inspector']);
        $visible = app(FleetAccess::class)->visibleVehicleIds();

        return $visible === null ? $query : $query->whereIn('vehicle_id', $visible);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('performed_at', 'desc')
            ->columns(static::columns(true))
            ->filters([
                SelectFilter::make('vehicle_id')
                    ->label(__('fleet.fields.vehicle'))
                    ->options(fn (): array => Vehicle::query()
                        ->when(($visible = app(FleetAccess::class)->visibleVehicleIds()) !== null, fn (Builder $query) => $query->whereKey($visible))
                        ->orderBy('plate')
                        ->pluck('plate', 'id')
                        ->all())
                    ->searchable(),
                Filter::make('with_problems')
                    ->label(__('fleet.inspections.with_problems'))
                    ->query(fn (Builder $query) => $query->where('problems', '>', 0)),
            ])
            ->recordActions([ViewAction::make()])
            ->emptyStateHeading(__('fleet.inspections.empty'));
    }

    /**
     * Columns shared with the vehicle's tab.
     *
     * @return array<int, mixed>
     */
    public static function columns(bool $withVehicle): array
    {
        return array_values(array_filter([
            TextColumn::make('performed_at')
                ->label(__('fleet.fields.date'))
                ->dateTime(__('app.datetime_format'))
                ->sortable(),
            $withVehicle ? TextColumn::make('vehicle.plate')
                ->label(__('fleet.fields.vehicle'))
                ->weight('bold')
                ->description(fn (Inspection $record): ?string => $record->inspector?->name) : null,
            $withVehicle ? null : TextColumn::make('inspector.name')->label(__('fleet.fields.who_did'))->placeholder('—'),
            TextColumn::make('odometer')
                ->label(__('fleet.fields.odometer'))
                ->formatStateUsing(fn (int $state): string => FleetFormat::distance($state))
                ->alignEnd()
                ->visibleFrom('md'),
            TextColumn::make('problems')
                ->label(__('fleet.inspections.result'))
                ->badge()
                ->color(fn (Inspection $record): string => $record->problems === 0 ? 'success' : ($record->has_critical ? 'danger' : 'warning'))
                ->formatStateUsing(fn (Inspection $record): string => $record->problems === 0
                    ? __('fleet.inspections.all_ok')
                    : trans_choice('fleet.inspections.problems_count', $record->problems, ['count' => $record->problems])),
            IconColumn::make('has_critical')
                ->label('')
                ->icon(fn (bool $state): ?Heroicon => $state ? Heroicon::OutlinedExclamationTriangle : null)
                ->color('danger')
                ->tooltip(fn (Inspection $record): ?string => $record->has_critical ? __('fleet.inspections.critical_hint') : null),
        ]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make()
                    ->columns(['default' => 2, 'md' => 4])
                    ->components([
                        TextEntry::make('vehicle.plate')->label(__('fleet.fields.vehicle'))->weight('bold'),
                        TextEntry::make('performed_at')->label(__('fleet.fields.date'))->dateTime(__('app.datetime_format')),
                        TextEntry::make('inspector.name')->label(__('fleet.fields.who_did'))->placeholder('—'),
                        TextEntry::make('odometer')->label(__('fleet.fields.odometer'))->formatStateUsing(fn (int $state): string => FleetFormat::distance($state)),
                        TextEntry::make('notes')->label(__('fleet.fields.notes'))->placeholder('—')->columnSpanFull(),
                    ]),
                Section::make(__('fleet.inspections.items'))
                    ->components([
                        RepeatableEntry::make('answers')
                            ->hiddenLabel()
                            ->columns(['default' => 1, 'md' => 3])
                            ->schema([
                                TextEntry::make('label')
                                    ->hiddenLabel()
                                    ->weight('bold'),
                                IconEntry::make('ok')
                                    ->hiddenLabel()
                                    ->boolean(),
                                TextEntry::make('note')->hiddenLabel()->placeholder(''),
                                ImageEntry::make('photo')
                                    ->hiddenLabel()
                                    ->disk('local')
                                    ->visibility('private')
                                    ->imageHeight(120)
                                    ->placeholder(''),
                            ]),
                    ]),
                Section::make(__('fleet.inspections.photos'))
                    ->description(fn (Inspection $record): ?string => $record->photos === [] ? __('fleet.cleanup.removed') : null)
                    ->components([
                        Grid::make(['default' => 2, 'md' => 5])->components(
                            collect(Inspection::PHOTOS)->map(fn (string $photo) => ImageEntry::make('photos.'.$photo)
                                ->label(__('fleet.inspections.photo.'.$photo))
                                ->disk('local')
                                ->visibility('private')
                                ->imageHeight(130)
                                ->placeholder('—'))->all(),
                        ),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListInspections::route('/'),
            'do' => Pages\DoInspection::route('/fazer'),
            'view' => Pages\ViewInspection::route('/{record}'),
        ];
    }
}
