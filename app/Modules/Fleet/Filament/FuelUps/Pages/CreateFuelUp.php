<?php

namespace App\Modules\Fleet\Filament\FuelUps\Pages;

use App\Filament\Support\NewRecordTitle;
use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\Filament\FuelUps\FuelUpResource;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\FuelService;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

/**
 * Manager types a fuel-up without a code (paid by card, receipt received later...).
 */
class CreateFuelUp extends CreateRecord
{
    use NewRecordTitle;

    protected static string $resource = FuelUpResource::class;

    public function form(Schema $schema): Schema
    {
        $settings = FleetSetting::current();

        return $schema
            ->columns(3)
            ->components([
                Select::make('vehicle_id')
                    ->label(__('fleet.fields.vehicle'))
                    ->options(fn (): array => Vehicle::query()->countingInLimit()->orderBy('plate')->get()->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->getKey() => $vehicle->displayName()])->all())
                    ->searchable()
                    ->required(),
                Select::make('driver_id')
                    ->label(__('fleet.drivers.singular_title'))
                    ->options(fn (): array => Driver::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                Select::make('station_id')
                    ->label(__('fleet.stations.singular_title'))
                    ->options(fn (): array => FuelStation::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable(),
                DateTimePicker::make('fueled_at')
                    ->label(__('fleet.fields.date'))
                    ->seconds(false)
                    ->maxDate(now())
                    ->default(now())
                    ->required(),
                TextInput::make('odometer')
                    ->label(__('fleet.fields.odometer'))
                    ->integer()
                    ->minValue(0)
                    ->suffix($settings->distance_unit)
                    ->required(),
                Select::make('fuel')
                    ->label(__('fleet.fields.fuel'))
                    ->options(FuelUpResource::fuelOptions())
                    ->required(),
                TextInput::make('volume')
                    ->label(__('fleet.fields.volume'))
                    ->numeric()
                    ->minValue(0.1)
                    ->step(0.001)
                    ->suffix($settings->volume_unit)
                    ->required(),
                TextInput::make('total')
                    ->label(__('fleet.fields.total'))
                    ->numeric()
                    ->minValue(0.01)
                    ->step(0.01)
                    ->prefix($settings->currency)
                    ->required(),
                Toggle::make('full_tank')
                    ->label(__('fleet.fields.full_tank'))
                    ->helperText(__('fleet.fuel.full_tank_help'))
                    ->default(true)
                    ->inline(false),
                FleetFields::file('receipt_path', 'fuel')
                    ->label(__('fleet.fuel.receipt_photo')),
                Textarea::make('notes')
                    ->label(__('fleet.fields.notes'))
                    ->rows(2)
                    ->columnSpan(2),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(FuelService::class)->register([
                'vehicle' => Vehicle::query()->whereKey($data['vehicle_id'])->firstOrFail(),
                'driver_id' => $data['driver_id'] ?? null,
                'station' => filled($data['station_id'] ?? null) ? FuelStation::query()->whereKey($data['station_id'])->firstOrFail() : null,
                'odometer' => (int) $data['odometer'],
                'volume' => (float) $data['volume'],
                'total_cents' => (int) round((float) $data['total'] * 100),
                'full_tank' => (bool) $data['full_tank'],
                'fuel' => FuelType::tryFrom((string) $data['fuel']),
                // The date picker already converts from the company time zone.
                'fueled_at' => CarbonImmutable::parse($data['fueled_at']),
                'receipt_path' => $data['receipt_path'] ?? null,
                'notes' => $data['notes'] ?? null,
            ], auth()->user());
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return FuelUpResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
