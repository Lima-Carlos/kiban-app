<?php

namespace App\Modules\Field\Filament\Dashboard;

use App\Modules\Concerns\InModuleMenu;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\Field\Services\FieldReports;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Kiban Campo dashboard (managers, the service desk and viewers): where each technician is right now, and the
 * month's done orders, revenue, time at the customer, punctuality, returns, stars and quotes, per technician.
 */
class FieldDashboard extends Dashboard
{
    use HasFiltersForm;
    use InModuleMenu;

    protected static string $moduleKey = FieldModule::KEY;

    protected static string $routePath = 'campo/painel';

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
            && $tenant->license()->hasModule(FieldModule::KEY)
            && in_array(app(FieldAccess::class)->role(), [FieldRole::Manager, FieldRole::Dispatcher, FieldRole::Viewer], true);
    }

    public static function getNavigationLabel(): string
    {
        return __('field.dashboard.title');
    }

    public function getTitle(): string
    {
        return __('field.dashboard.title');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('month')
                ->label(__('field.dashboard.month'))
                ->options(fn (): array => app(FieldReports::class)->monthOptions())
                ->default(now()->format('Y-m'))
                ->selectablePlaceholder(false),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            FieldNowWidget::class,
            FieldMonthWidget::class,
            FieldTechniciansWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
