<?php

namespace App\Modules\Fleet\Filament\Vehicles\RelationManagers;

use App\Modules\Fleet\Services\FleetFormat;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class WashesRelationManager extends RelationManager
{
    protected static string $relationship = 'washes';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.washes.plural_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('washed_on', 'desc')
            ->columns([
                TextColumn::make('washed_on')->label(__('fleet.fields.date'))->date(__('app.date_format')),
                TextColumn::make('kind')->label(__('fleet.fields.wash_kind'))->badge()->color('info'),
                TextColumn::make('place')->label(__('fleet.fields.place'))->placeholder('—'),
                TextColumn::make('cost_cents')->label(__('fleet.fields.cost'))->formatStateUsing(fn (int $state): string => FleetFormat::money($state))->alignEnd(),
            ])
            ->emptyStateHeading(__('fleet.washes.empty'));
    }
}
