<?php

namespace App\Modules\Fleet\Services;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Models\Driver;
use App\Modules\Fleet\Models\FleetMember;
use App\Modules\Fleet\Models\Vehicle;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\DB;

/**
 * The Frota part of the "People" screen: the person's role in the fleet and, for drivers,
 * their vehicles and driver's license.
 */
class FleetPeople
{
    public function __construct(protected FleetAccess $access) {}

    /**
     * @return array<int, mixed>
     */
    public function fields(): array
    {
        $isDriver = fn (Get $get): bool => in_array($get('role'), [FleetRole::Driver->value, FleetRole::Driver], true);

        return [
            Select::make('role')
                ->label(__('fleet.people.role'))
                ->options(collect([FleetRole::Driver, FleetRole::Mechanic, FleetRole::Manager, FleetRole::Viewer])->mapWithKeys(fn (FleetRole $role): array => [$role->value => $role->getLabel()])->all())
                ->default(FleetRole::Driver->value)
                ->helperText(__('fleet.people.role_help'))
                ->required()
                ->live(),
            Grid::make(2)
                ->visible($isDriver)
                ->components([
                    Select::make('vehicles')
                        ->label(__('fleet.fields.vehicles'))
                        ->options(fn (): array => Vehicle::query()->countingInLimit()->orderBy('plate')->get()->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->getKey() => $vehicle->displayName()])->all())
                        ->multiple()
                        ->helperText(__('fleet.helpers.driver_vehicles'))
                        ->columnSpanFull(),
                    Toggle::make('any_vehicle')
                        ->label(__('fleet.fields.any_vehicle'))
                        ->helperText(__('fleet.helpers.any_vehicle'))
                        ->columnSpanFull(),
                    TextInput::make('license_number')
                        ->label(__('fleet.fields.license_number'))
                        ->maxLength(30),
                    TextInput::make('license_category')
                        ->label(__('fleet.fields.license_category'))
                        ->maxLength(10),
                    DatePicker::make('license_expires_on')
                        ->label(__('fleet.fields.license_expires_on')),
                    TextInput::make('document')
                        ->label(__('fleet.fields.document'))
                        ->maxLength(30),
                ]),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function data(User $user): array
    {
        $driver = Driver::query()->where('user_id', $user->getKey())->first();

        return [
            'role' => $this->access->fleetRole($user)->value,
            'vehicles' => $driver?->vehicles()->pluck('fleet_vehicles.id')->all() ?? [],
            'any_vehicle' => (bool) $driver?->any_vehicle,
            'license_number' => $driver?->license_number,
            'license_category' => $driver?->license_category,
            'license_expires_on' => $driver?->license_expires_on?->toDateString(),
            'document' => $driver?->document,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function save(User $user, array $data): void
    {
        // Owners and administrators are always managers of the fleet.
        if ($user->canManageTenant()) {
            return;
        }

        $role = FleetRole::tryFrom((string) ($data['role'] ?? '')) ?? FleetRole::Viewer;

        DB::transaction(function () use ($user, $data, $role): void {
            $driver = Driver::query()->where('user_id', $user->getKey())->first();

            if ($role === FleetRole::Driver) {
                FleetMember::query()->where('user_id', $user->getKey())->delete();

                $driver ??= new Driver;
                $driver->fill([
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                    'license_number' => $data['license_number'] ?? null,
                    'license_category' => $data['license_category'] ?? null,
                    'license_expires_on' => $data['license_expires_on'] ?? null,
                    'document' => $data['document'] ?? null,
                    'any_vehicle' => (bool) ($data['any_vehicle'] ?? false),
                    'is_active' => true,
                ]);
                $driver->forceFill(['user_id' => $user->getKey()])->save();
                $driver->vehicles()->sync(Vehicle::query()->whereKey((array) ($data['vehicles'] ?? []))->pluck('id')->all());

                return;
            }

            FleetMember::query()->updateOrCreate(['user_id' => $user->getKey()], ['role' => $role]);
            // No longer a driver: the record stays (history), inactive.
            $driver?->forceFill(['is_active' => false])->save();
        });
    }

    public function remove(User $user): void
    {
        FleetMember::query()->where('user_id', $user->getKey())->delete();
        Driver::query()->where('user_id', $user->getKey())->update(['user_id' => null, 'is_active' => false]);
    }

    public function roleLabel(User $user): string
    {
        return $this->access->fleetRole($user)->getLabel();
    }
}
