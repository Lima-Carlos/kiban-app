<?php

namespace App\Modules\Fleet\Filament\Vehicles\RelationManagers;

use App\Modules\Fleet\Models\OdometerReading;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\FleetFormat;
use App\Modules\Fleet\Services\OdometerService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Every odometer reading of the vehicle and where it came from. Only a manager corrects (with a reason).
 */
class OdometerReadingsRelationManager extends RelationManager
{
    protected static string $relationship = 'odometerReadings';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.odometer.title');
    }

    public function isReadOnly(): bool
    {
        return false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('read_at', 'desc')
            ->columns([
                TextColumn::make('value')
                    ->label(__('fleet.fields.odometer'))
                    ->formatStateUsing(fn (int $state): string => FleetFormat::distance($state))
                    ->weight('bold'),
                TextColumn::make('source')
                    ->label(__('fleet.fields.source'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('read_at')
                    ->label(__('fleet.fields.date'))
                    ->dateTime(__('app.datetime_format')),
                TextColumn::make('user.name')
                    ->label(__('fleet.fields.by'))
                    ->placeholder('—'),
                TextColumn::make('reason')
                    ->label(__('fleet.fields.reason'))
                    ->placeholder('—')
                    ->wrap(),
            ])
            ->headerActions([
                Action::make('correct')
                    ->label(__('fleet.actions.correct_odometer'))
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->color('gray')
                    ->authorize(fn (): bool => auth()->user()->can('update', $this->getOwnerRecord()))
                    ->modalDescription(__('fleet.helpers.correct_odometer'))
                    ->schema([
                        TextInput::make('value')
                            ->label(__('fleet.fields.odometer'))
                            ->integer()
                            ->minValue(0)
                            ->required(),
                        Textarea::make('reason')
                            ->label(__('fleet.fields.reason'))
                            ->required(),
                    ])
                    ->action(function (array $data): void {
                        /** @var Vehicle $vehicle */
                        $vehicle = $this->getOwnerRecord();

                        try {
                            app(OdometerService::class)->correct($vehicle, (int) $data['value'], $data['reason'], auth()->user());
                            Notification::make()->success()->title(__('fleet.notifications.saved'))->send();
                        } catch (FleetException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                        }
                    }),
            ])
            ->recordActions([])
            ->emptyStateHeading(__('fleet.odometer.empty'));
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', OdometerReading::class) ?? false;
    }
}
