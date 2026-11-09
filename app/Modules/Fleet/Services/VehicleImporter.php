<?php

namespace App\Modules\Fleet\Services;

use App\Modules\Fleet\Enums\FuelType;
use App\Modules\Fleet\Enums\VehicleType;
use App\Modules\Fleet\Models\Vehicle;
use App\Support\Spreadsheet\SpreadsheetImporter;
use Filament\Support\Contracts\HasLabel;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Vehicles from a spreadsheet (e.g. the fleet list of the old system or the insurance). A new plate is registered
 * with its current odometer (VehicleService: the plan limit counts); a plate already registered gets the
 * identification updated (empty cells never erase; the odometer only changes by the normal ways).
 */
class VehicleImporter extends SpreadsheetImporter
{
    public function __construct(protected VehicleService $vehicles) {}

    protected function columnKeys(): array
    {
        return ['plate', 'brand', 'model', 'color', 'manufacture_year', 'model_year', 'type', 'fuel_type', 'tank_capacity', 'odometer', 'vin', 'registration', 'notes'];
    }

    protected function requiredColumn(): string
    {
        return 'plate';
    }

    protected function langPrefix(): string
    {
        return 'fleet.import';
    }

    protected function aliases(): array
    {
        return [
            'plate' => ['placa', 'matricula', 'license_plate', 'patente'],
            'brand' => ['marca', 'fabricante', 'make'],
            'model' => ['modelo'],
            'color' => ['cor'],
            'manufacture_year' => ['ano', 'ano_fabricacao', 'year', 'ano_fab'],
            'model_year' => ['ano_modelo', 'ano_mod'],
            'type' => ['tipo', 'categoria'],
            'fuel_type' => ['combustivel', 'fuel', 'combustible'],
            'tank_capacity' => ['tanque', 'capacidade_tanque', 'tank'],
            'odometer' => ['km', 'km_atual', 'hodometro', 'odometro', 'quilometragem', 'kilometraje', 'mileage'],
            'vin' => ['chassi', 'chasis'],
            'registration' => ['renavam'],
            'notes' => ['observacao', 'observacoes', 'obs'],
        ];
    }

    protected function line(array $data): bool|string
    {
        $validator = Validator::make($data, [
            'plate' => ['required', 'regex:/^[A-Za-z0-9\- ]{2,12}$/'],
            'brand' => ['nullable', 'max:60'],
            'model' => ['nullable', 'max:80'],
            'manufacture_year' => ['nullable', 'integer', 'between:1950,'.((int) now()->year + 1)],
            'model_year' => ['nullable', 'integer', 'between:1950,'.((int) now()->year + 2)],
            'tank_capacity' => ['nullable', 'numeric', 'between:1,2000'],
            'odometer' => ['nullable', 'integer', 'between:0,9999999'],
        ], [], collect(array_keys($data))->mapWithKeys(fn (string $key): array => [$key => __('fleet.import.columns.'.$key)])->all());

        if ($validator->fails()) {
            return $validator->errors()->first();
        }

        $plate = Vehicle::normalizePlate((string) $data['plate']);
        $existing = Vehicle::query()->where('plate', $plate)->first();
        $fields = array_filter([
            'brand' => $data['brand'] ?? null,
            'model' => $data['model'] ?? null,
            'color' => $data['color'] ?? null,
            'manufacture_year' => $data['manufacture_year'] ?? null,
            'model_year' => $data['model_year'] ?? null,
            'type' => $this->type($data['type'] ?? null),
            'fuel_type' => $this->fuel($data['fuel_type'] ?? null),
            'tank_capacity' => isset($data['tank_capacity']) && $data['tank_capacity'] !== '' ? str_replace(',', '.', (string) $data['tank_capacity']) : null,
            'vin' => $data['vin'] ?? null,
            'registration' => $data['registration'] ?? null,
            'notes' => $data['notes'] ?? null,
        ], fn ($value): bool => filled($value));

        if (filled($fields['vin'] ?? null) && Vehicle::query()->where('vin', $fields['vin'])->when($existing, fn ($query) => $query->whereKeyNot($existing->getKey()))->exists()) {
            return __('fleet.import.vin_taken');
        }

        if ($existing !== null) {
            $existing->fill($fields)->save();

            return false;
        }

        if (blank($fields['brand'] ?? null) || blank($fields['model'] ?? null)) {
            return __('fleet.import.brand_model');
        }

        try {
            $this->vehicles->register(['plate' => $plate] + $fields + ['type' => VehicleType::Car->value, 'fuel_type' => FuelType::Flex->value], (int) ($data['odometer'] ?? 0), auth()->user());
        } catch (FleetException $exception) {
            return $exception->getMessage();
        }

        return true;
    }

    protected function type(?string $typed): ?string
    {
        return $this->enumValue(VehicleType::cases(), $typed, [
            'carro' => 'car', 'automovel' => 'car', 'auto' => 'car', 'moto' => 'motorcycle', 'motocicleta' => 'motorcycle',
            'furgao' => 'van', 'utilitario' => 'van', 'picape' => 'pickup', 'caminhonete' => 'pickup', 'camioneta' => 'pickup',
            'caminhao' => 'truck', 'camion' => 'truck', 'onibus' => 'bus', 'maquina' => 'machine',
        ]);
    }

    protected function fuel(?string $typed): ?string
    {
        return $this->enumValue(FuelType::cases(), $typed, [
            'gasolina' => 'gasoline', 'etanol' => 'ethanol', 'alcool' => 'ethanol', 'flex' => 'flex', 'diesel' => 'diesel', 's10' => 'diesel',
            'gnv' => 'cng', 'gas' => 'cng', 'eletrico' => 'electric', 'electrico' => 'electric', 'hibrido' => 'hybrid',
        ]);
    }

    /**
     * The enum value from what was typed (value, label in any language or a common word).
     *
     * @param  array<int, \BackedEnum&HasLabel>  $cases
     * @param  array<string, string>  $words
     */
    protected function enumValue(array $cases, ?string $typed, array $words): ?string
    {
        $slug = Str::slug((string) $typed, '_');

        if ($slug === '') {
            return null;
        }

        foreach ($cases as $case) {
            if ($slug === $case->value || $slug === Str::slug((string) $case->getLabel(), '_')) {
                return $case->value;
            }
        }

        return $words[$slug] ?? null;
    }
}
