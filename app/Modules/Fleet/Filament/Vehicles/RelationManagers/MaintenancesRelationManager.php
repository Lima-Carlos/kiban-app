<?php

namespace App\Modules\Fleet\Filament\Vehicles\RelationManagers;

use App\Modules\Fleet\Enums\MaintenanceItem;
use App\Modules\Fleet\Models\Maintenance;
use App\Modules\Fleet\Services\FleetFormat;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Maintenance history of the vehicle (part of its record).
 */
class MaintenancesRelationManager extends RelationManager
{
    protected static string $relationship = 'maintenances';

    /** Loaded with the page: no empty white box while the tab loads. */
    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.maintenances.plural_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('performed_on', 'desc')
            ->columns([
                TextColumn::make('performed_on')->label(__('fleet.fields.performed_on'))->date(__('app.date_format')),
                TextColumn::make('odometer')->label(__('fleet.fields.odometer'))->formatStateUsing(fn (int $state): string => FleetFormat::distance($state)),
                TextColumn::make('services')->label(__('fleet.fields.services'))
                    ->state(fn (Maintenance $record): array => array_map(fn (MaintenanceItem $item): string => $item->getLabel(), $record->items()))
                    ->badge()
                    ->description(fn (Maintenance $record): ?string => $record->description),
                TextColumn::make('total_cents')->label(__('fleet.fields.total'))->formatStateUsing(fn (int $state): string => FleetFormat::money($state))->alignEnd(),
            ])
            ->emptyStateHeading(__('fleet.maintenances.empty'));
    }
}
