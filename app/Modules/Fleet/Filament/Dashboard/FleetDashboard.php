<?php

namespace App\Modules\Fleet\Filament\Dashboard;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\Fleet\Services\FleetReports;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\ToggleButtons;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Frota dashboard: spending, volume, distance, average consumption and cost per distance, for the last
 * 1, 3, 6, 9 or 12 months, all vehicles or one. For managers and viewers (not drivers or mechanics).
 */
class FleetDashboard extends Dashboard
{
    use InModuleMenu;

    protected static string $moduleKey = FleetModule::KEY;

    use HasFiltersForm;

    protected static string $routePath = 'frota/painel';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static ?int $navigationSort = 0;

    public static function getRoutePath(Panel $panel): string
    {
        return static::$routePath;
    }

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null
            && $tenant->license()->hasModule(FleetModule::KEY)
            && in_array(app(FleetAccess::class)->fleetRole(), [FleetRole::Manager, FleetRole::Viewer], true);
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.dashboard.title');
    }

    public function getTitle(): string
    {
        return __('fleet.dashboard.title');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                ToggleButtons::make('period')
                    ->label(__('fleet.dashboard.period'))
                    ->options(collect(FleetReports::PERIODS)->mapWithKeys(fn (int $months): array => [(string) $months => trans_choice('fleet.dashboard.months', $months, ['count' => $months])])->all())
                    ->default((string) FleetReports::DEFAULT_PERIOD)
                    ->inline()
                    ->grouped(),
                Select::make('vehicle')
                    ->label(__('fleet.fields.vehicle'))
                    ->placeholder(__('fleet.dashboard.all_vehicles'))
                    ->options(fn (): array => Vehicle::query()->orderBy('plate')->get()->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->getKey() => $vehicle->displayName()])->all())
                    ->searchable(),
            ]);
    }

    public function getWidgets(): array
    {
        return [
            FleetStatsWidget::class,
            InspectionStatusWidget::class,
            ConsumptionChartWidget::class,
            FuelSpendChartWidget::class,
            ConsumptionAlertsWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 2;
    }
}
