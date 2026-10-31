<?php

namespace App\Modules\Fleet\Filament;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\Enums\MaintenanceItem;
use App\Modules\Fleet\Enums\MaintenanceType;
use App\Modules\Fleet\Enums\ServiceKind;
use App\Modules\Fleet\Enums\WashKind;
use App\Modules\Fleet\Models\FleetSetting;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Models\Workshop;
use App\Modules\Fleet\Services\FleetAccess;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ToggleButtons;
use Filament\Schemas\Components\Grid;
use Filament\Support\Facades\FilamentTimezone;

/**
 * Forms used in several Frota screens: maintenance, wash and appointment (scheduling).
 */
class WorkForms
{
    /**
     * @return array<int, mixed>
     */
    public static function maintenance(bool $withVehicle = true): array
    {
        $settings = FleetSetting::current();

        return array_values(array_filter([
            $withVehicle ? static::vehicleSelect() : null,
            Grid::make(3)->components([
                ToggleButtons::make('type')
                    ->label(__('fleet.fields.maintenance_type'))
                    ->options(MaintenanceType::class)
                    ->default(MaintenanceType::Preventive->value)
                    ->inline()
                    ->required(),
                DatePicker::make('performed_on')
                    ->label(__('fleet.fields.performed_on'))
                    ->default(now())
                    ->maxDate(now())
                    ->required(),
                TextInput::make('odometer')
                    ->label(__('fleet.fields.odometer'))
                    ->integer()
                    ->minValue(0)
                    ->suffix($settings->distance_unit)
                    ->required(),
            ]),
            CheckboxList::make('services')
                ->label(__('fleet.fields.services'))
                ->options(MaintenanceItem::class)
                ->columns(['default' => 2, 'md' => 4])
                ->helperText(__('fleet.helpers.services'))
                ->required(),
            Textarea::make('description')
                ->label(__('fleet.fields.what_was_done'))
                ->rows(2),
            Grid::make(2)->components([
                static::workshopSelect(),
                static::mechanicSelect('performed_by')->label(__('fleet.fields.mechanic')),
            ]),
            Grid::make(2)->components([
                static::money('parts')->label(__('fleet.fields.parts')),
                static::money('labor')->label(__('fleet.fields.labor')),
            ]),
            FleetFields::file('invoice_path', 'maintenance')->label(__('fleet.fields.invoice')),
        ]));
    }

    /**
     * @return array<int, mixed>
     */
    public static function wash(bool $withVehicle = true): array
    {
        $settings = FleetSetting::current();

        return array_values(array_filter([
            $withVehicle ? static::vehicleSelect() : null,
            Grid::make(3)->components([
                ToggleButtons::make('kind')
                    ->label(__('fleet.fields.wash_kind'))
                    ->options(WashKind::class)
                    ->default(WashKind::Simple->value)
                    ->inline()
                    ->required(),
                DatePicker::make('washed_on')
                    ->label(__('fleet.fields.date'))
                    ->default(now())
                    ->maxDate(now())
                    ->required(),
                TextInput::make('odometer')
                    ->label(__('fleet.fields.odometer'))
                    ->integer()
                    ->minValue(0)
                    ->suffix($settings->distance_unit)
                    ->helperText(__('fleet.helpers.optional')),
            ]),
            Grid::make(2)->components([
                static::money('cost')->label(__('fleet.fields.cost')),
                TextInput::make('place')->label(__('fleet.fields.place'))->maxLength(255),
            ]),
            Textarea::make('notes')->label(__('fleet.fields.notes'))->rows(2),
        ]));
    }

    /**
     * @return array<int, mixed>
     */
    public static function schedule(bool $withVehicleAndKind = true): array
    {
        return array_values(array_filter([
            $withVehicleAndKind ? static::vehicleSelect() : null,
            $withVehicleAndKind ? ToggleButtons::make('kind')
                ->label(__('fleet.fields.service_kind'))
                ->options(ServiceKind::class)
                ->default(ServiceKind::Maintenance->value)
                ->inline()
                ->required() : null,
            DateTimePicker::make('scheduled_at')
                ->label(__('fleet.fields.scheduled_at'))
                ->seconds(false)
                ->minDate(now(FilamentTimezone::get())->startOfDay())
                ->required(),
            Grid::make(2)->components([
                static::workshopSelect(),
                TextInput::make('location')->label(__('fleet.fields.place'))->maxLength(255)->helperText(__('fleet.helpers.place')),
            ]),
            static::mechanicSelect('assigned_to')->label(__('fleet.fields.assigned_to')),
            Textarea::make('notes')->label(__('fleet.fields.notes'))->rows(2),
        ]));
    }

    public static function vehicleSelect(string $field = 'vehicle_id'): Select
    {
        return Select::make($field)
            ->label(__('fleet.fields.vehicle'))
            ->options(function (): array {
                $visible = app(FleetAccess::class)->visibleVehicleIds();

                return Vehicle::query()->countingInLimit()
                    ->when($visible !== null, fn ($query) => $query->whereKey($visible))
                    ->orderBy('plate')->get()
                    ->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->getKey() => $vehicle->displayName()])->all();
            })
            ->searchable()
            ->required();
    }

    public static function workshopSelect(): Select
    {
        return Select::make('workshop_id')
            ->label(__('fleet.fields.workshop'))
            ->options(fn (): array => Workshop::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
            ->searchable()
            ->placeholder(__('fleet.helpers.own_mechanic'));
    }

    /** People of the company who are mechanics (or managers) in the Frota. */
    public static function mechanicSelect(string $field): Select
    {
        return Select::make($field)
            ->options(function (): array {
                $tenant = app(TenantContext::class)->get();
                $access = app(FleetAccess::class);

                return $tenant->users()->orderBy('name')->get()
                    ->filter(fn (User $user): bool => in_array($access->fleetRole($user), [FleetRole::Mechanic, FleetRole::Manager], true))
                    ->pluck('name', 'id')->all();
            })
            ->searchable();
    }

    public static function money(string $field): TextInput
    {
        return TextInput::make($field)
            ->numeric()
            ->minValue(0)
            ->step(0.01)
            ->prefix(FleetSetting::current()->currency);
    }

    public static function cents(mixed $value): int
    {
        return (int) round((float) ($value ?? 0) * 100);
    }

    public static function date(mixed $value): CarbonImmutable
    {
        return CarbonImmutable::parse((string) $value);
    }
}
