<?php

namespace App\Modules\TimeClock\Filament\Dashboard;

use App\Modules\ModuleRegistry;
use App\Modules\TimeClock\Enums\TimeClockRole;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\Services\TimeClockReports;
use App\Modules\TimeClock\TimeClockModule;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Forms\Components\Select;
use Filament\Pages\Dashboard;
use Filament\Pages\Dashboard\Concerns\HasFiltersForm;
use Filament\Panel;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

/**
 * Ponto dashboard (managers and viewers): who is working right now, who is late, and the month's overtime,
 * minutes short, absences, night hours and hour bank, with the people at the top of each.
 */
class TimeClockDashboard extends Dashboard
{
    use HasFiltersForm;

    protected static string $routePath = 'ponto/painel';

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
            && $tenant->license()->hasModule(TimeClockModule::KEY)
            && in_array(app(TimeClockAccess::class)->role(), [TimeClockRole::Manager, TimeClockRole::Viewer], true);
    }

    public static function getNavigationGroup(): ?string
    {
        return app(ModuleRegistry::class)->get(TimeClockModule::KEY)->name();
    }

    public static function getNavigationLabel(): string
    {
        return __('timeclock.dashboard.title');
    }

    public function getTitle(): string
    {
        return __('timeclock.dashboard.title');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('month')
                ->label(__('timeclock.dashboard.month'))
                ->options(fn (): array => app(TimeClockReports::class)->monthOptions())
                ->default(now()->format('Y-m'))
                ->selectablePlaceholder(false),
        ]);
    }

    public function getWidgets(): array
    {
        return [
            NowWidget::class,
            MonthStatsWidget::class,
            MonthRankingWidget::class,
        ];
    }

    public function getColumns(): int|array
    {
        return 1;
    }
}
