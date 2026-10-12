<?php

namespace App\Modules\Fleet\Filament\FuelUps\Pages;

use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\Filament\FuelUps\FuelUpResource;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetException;
use App\Modules\Fleet\Services\FleetFormat;
use App\Modules\Fleet\Services\FuelService;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Schemas\Components\Actions;
use Filament\Schemas\Components\EmbeddedSchema;
use Filament\Schemas\Components\Form;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Illuminate\Contracts\Support\Htmlable;

/**
 * "Fuel up" (phone-first): vehicle, station, odometer and a dashboard photo → a single-use code for the station.
 */
class RequestFuelCode extends Page
{
    protected static string $resource = FuelUpResource::class;

    /** @var array<string, mixed>|null */
    public ?array $data = [];

    public static function canAccess(array $parameters = []): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null
            && $tenant->license()->canWrite(FleetModule::KEY)
            && app(FuelService::class)->canFuel(auth()->user());
    }

    public function getTitle(): string|Htmlable
    {
        return __('fleet.fuel.request_code');
    }

    public function mount(): void
    {
        $vehicle = request()->integer('vehicle') ?: null;
        $own = app(FuelService::class)->ownVehicleIds(auth()->user());

        // Only one own vehicle: already selected.
        if ($vehicle === null && $own !== null && count($own) === 1) {
            $vehicle = $own[0];
        }

        $this->form->fill(['vehicle_id' => $vehicle]);
    }

    public function form(Schema $schema): Schema
    {
        $settings = FleetSetting::current();
        $service = app(FuelService::class);
        $user = auth()->user();

        return $schema
            ->statePath('data')
            ->components([
                Select::make('vehicle_id')
                    ->label(__('fleet.fields.vehicle'))
                    ->options(fn (): array => $this->vehicleOptions())
                    ->searchable()
                    ->required()
                    ->live(),
                Select::make('station_id')
                    ->label(__('fleet.stations.singular_title'))
                    ->options(fn (): array => FuelStation::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->required(),
                TextInput::make('odometer')
                    ->label(__('fleet.fuel.odometer_now'))
                    ->integer()
                    ->required()
                    ->minValue(fn (Get $get): int => (int) Vehicle::query()->whereKey($get('vehicle_id'))->value('odometer'))
                    ->suffix($settings->distance_unit)
                    ->helperText(fn (Get $get): ?string => ($odometer = Vehicle::query()->whereKey($get('vehicle_id'))->value('odometer')) !== null
                        ? __('fleet.fuel.last_odometer', ['value' => FleetFormat::distance((int) $odometer)])
                        : null),
                FleetFields::photo('odometer_photo_path', 'fuel')
                    ->label(__('fleet.fuel.odometer_photo'))
                    ->helperText(__('fleet.fuel.odometer_photo_help'))
                    ->required(),
                Textarea::make('reason')
                    ->label(__('fleet.fuel.other_vehicle_reason'))
                    ->helperText(__('fleet.fuel.other_vehicle_help'))
                    ->rows(2)
                    ->visible(fn (Get $get): bool => ($vehicle = Vehicle::query()->find($get('vehicle_id'))) !== null && $service->isOtherVehicle($user, $vehicle))
                    ->required(fn (Get $get): bool => ($vehicle = Vehicle::query()->find($get('vehicle_id'))) !== null && $service->isOtherVehicle($user, $vehicle)),
            ]);
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            Form::make([EmbeddedSchema::make('form')])
                ->id('form')
                ->livewireSubmitHandler('generate')
                ->footer([
                    Actions::make([
                        Action::make('generate')
                            ->label(__('fleet.fuel.generate'))
                            ->size('lg')
                            ->submit('generate'),
                    ]),
                ]),
        ]);
    }

    public function generate(): void
    {
        abort_unless(static::canAccess(), 403);

        $data = $this->form->getState();
        $vehicle = Vehicle::query()->whereKey($data['vehicle_id'])->firstOrFail();

        if (! array_key_exists($vehicle->getKey(), $this->vehicleOptions())) {
            abort(403);
        }

        try {
            $fuelUp = app(FuelService::class)->authorize(
                $vehicle,
                FuelStation::query()->whereKey($data['station_id'])->firstOrFail(),
                (int) $data['odometer'],
                auth()->user(),
                $data['odometer_photo_path'] ?? null,
                $data['reason'] ?? null,
            );
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        $this->redirect(FuelUpResource::getUrl('view', ['record' => $fuelUp]));
    }

    /**
     * Own vehicles first; other active vehicles only if the company allows fueling them (with a reason).
     *
     * @return array<int, string>
     */
    protected function vehicleOptions(): array
    {
        $own = app(FuelService::class)->ownVehicleIds(auth()->user());
        $vehicles = Vehicle::query()->countingInLimit()->orderBy('plate')->get();

        if ($own === null) {
            return $vehicles->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->getKey() => $vehicle->displayName()])->all();
        }

        $options = $vehicles->whereIn('id', $own)->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->getKey() => $vehicle->displayName()])->all();

        if (FleetSetting::current()->allow_other_vehicle) {
            foreach ($vehicles->whereNotIn('id', $own) as $vehicle) {
                $options[$vehicle->getKey()] = $vehicle->displayName().' · '.__('fleet.fuel.not_yours');
            }
        }

        return $options;
    }
}
