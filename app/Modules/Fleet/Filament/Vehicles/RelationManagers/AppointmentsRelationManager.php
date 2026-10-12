<?php

namespace App\Modules\Fleet\Filament\Vehicles\RelationManagers;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AppointmentsRelationManager extends RelationManager
{
    protected static string $relationship = 'appointments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.appointments.plural_title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('scheduled_at', 'desc')
            ->columns([
                TextColumn::make('scheduled_at')->label(__('fleet.fields.scheduled_at'))->dateTime(__('app.datetime_format')),
                TextColumn::make('kind')->label(__('fleet.fields.service_kind'))->badge()->color('gray'),
                TextColumn::make('notes')->label(__('fleet.fields.notes'))->limit(60)->placeholder('—'),
                TextColumn::make('status')->label(__('fleet.fields.status'))->badge(),
            ])
            ->emptyStateHeading(__('fleet.appointments.empty'));
    }
}
