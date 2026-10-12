<?php

namespace App\Modules\Fleet;

use App\Models\Tenant;
use App\Modules\Fleet\Filament\Drivers\DriverResource;
use App\Modules\Fleet\Filament\FleetSettingsPage;
use App\Modules\Fleet\Filament\FuelStations\FuelStationResource;
use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\FuelStation;
use App\Modules\Fleet\Models\OdometerReading;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\VehicleDocument;
use App\Modules\Fleet\Models\VehicleEvent;
use App\Modules\Fleet\Policies\DriverPolicy;
use App\Modules\Fleet\Policies\FuelStationPolicy;
use App\Modules\Fleet\Policies\OdometerReadingPolicy;
use App\Modules\Fleet\Policies\VehicleDocumentPolicy;
use App\Modules\Fleet\Policies\VehicleEventPolicy;
use App\Modules\Fleet\Policies\VehiclePolicy;
use App\Modules\Module;
use App\Modules\Step;

/**
 * Kiban Frota: vehicles with the odometer always up to date, oil changes and revisions, documents,
 * drivers (with their own login on the phone) and fuel stations. Product code in the Core: "frota".
 */
class FleetModule extends Module
{
    public const KEY = 'frota';

    /** Plan limit in the Core: number of vehicles (inactive ones do not count). */
    public const LIMIT_VEHICLES = 'vehicles';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('fleet.module');
    }

    public function icon(): string
    {
        return 'heroicon-o-truck';
    }

    public function steps(): array
    {
        return [
            new Step(
                key: 'vehicle',
                labelKey: 'fleet.steps.vehicle',
                descriptionKey: 'fleet.steps.vehicle_help',
                done: fn (Tenant $tenant): bool => Vehicle::query()->exists(),
                url: fn (): string => VehicleResource::getUrl('create'),
            ),
            new Step(
                key: 'driver',
                labelKey: 'fleet.steps.driver',
                descriptionKey: 'fleet.steps.driver_help',
                requires: ['vehicle'],
                done: fn (Tenant $tenant): bool => Driver::query()->whereHas('vehicles')->exists(),
                url: fn (): string => DriverResource::getUrl('index'),
            ),
            new Step(
                key: 'station',
                labelKey: 'fleet.steps.station',
                descriptionKey: 'fleet.steps.station_help',
                done: fn (Tenant $tenant): bool => FuelStation::query()->exists(),
                url: fn (): string => FuelStationResource::getUrl('index'),
            ),
        ];
    }

    public function resources(): array
    {
        return [VehicleResource::class, DriverResource::class, FuelStationResource::class];
    }

    public function pages(): array
    {
        return [FleetSettingsPage::class];
    }

    public function policies(): array
    {
        return [
            Vehicle::class => VehiclePolicy::class,
            Driver::class => DriverPolicy::class,
            FuelStation::class => FuelStationPolicy::class,
            VehicleDocument::class => VehicleDocumentPolicy::class,
            OdometerReading::class => OdometerReadingPolicy::class,
            VehicleEvent::class => VehicleEventPolicy::class,
        ];
    }
}
