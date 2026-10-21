<?php

namespace App\Modules\Fleet\Filament\Washes;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\Enums\WashKind;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Wash;
use App\Modules\Fleet\Services\FleetFormat;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class WashResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = Wash::class;

    protected static ?string $slug = 'frota/lavagens';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 7;

    public static function getModelLabel(): string
    {
        return __('fleet.washes.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.washes.plural');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('washed_on', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('vehicle'))
            ->columns([
                TextColumn::make('washed_on')->label(__('fleet.fields.date'))->date(__('app.date_format'))->sortable(),
                TextColumn::make('vehicle.plate')->label(__('fleet.fields.vehicle'))->weight('bold'),
                TextColumn::make('kind')->label(__('fleet.fields.wash_kind'))->badge()->color('info'),
                TextColumn::make('place')->label(__('fleet.fields.place'))->placeholder('—')->visibleFrom('md'),
                TextColumn::make('cost_cents')->label(__('fleet.fields.cost'))->formatStateUsing(fn (int $state): string => FleetFormat::money($state))->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('vehicle_id')->label(__('fleet.fields.vehicle'))->relationship('vehicle', 'plate')->searchable(),
                SelectFilter::make('kind')->label(__('fleet.fields.wash_kind'))->options(WashKind::class),
            ])
            ->emptyStateHeading(__('fleet.washes.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageWashes::route('/'),
        ];
    }
}
