<?php

namespace App\Modules\Fleet\Filament\Vehicles\RelationManagers;

use App\Modules\Fleet\Enums\VehicleEventType;
use App\Modules\Fleet\Models\VehicleEvent;
use App\Modules\Fleet\Services\FleetFormat;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Important changes of the vehicle (registration, plate/chassis, deactivation, odometer corrections).
 * Nobody can edit or remove them.
 */
class EventsRelationManager extends RelationManager
{
    protected static string $relationship = 'events';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.events.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('type')
                    ->label(__('fleet.fields.event'))
                    ->badge()
                    ->color('gray')
                    ->description(fn (VehicleEvent $record): ?string => static::details($record)),
                TextColumn::make('reason')
                    ->label(__('fleet.fields.reason'))
                    ->placeholder('—')
                    ->wrap(),
                TextColumn::make('user.name')
                    ->label(__('fleet.fields.by'))
                    ->placeholder('—'),
                TextColumn::make('created_at')
                    ->label(__('fleet.fields.date'))
                    ->dateTime(__('app.datetime_format')),
            ])
            ->emptyStateHeading(__('fleet.events.empty'));
    }

    protected static function details(VehicleEvent $event): ?string
    {
        $data = (array) $event->data;

        return match ($event->type) {
            VehicleEventType::IdentityChanged => __('fleet.events.identity', [
                'from' => $data['from']['plate'] ?? '—',
                'to' => $data['to']['plate'] ?? '—',
            ]),
            VehicleEventType::OdometerCorrected => __('fleet.events.odometer', [
                'from' => FleetFormat::distance($data['from'] ?? null),
                'to' => FleetFormat::distance($data['to'] ?? null),
            ]),
            default => null,
        };
    }
}
