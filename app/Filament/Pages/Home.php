<?php

namespace App\Filament\Pages;

use App\Models\Tenant;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Panel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Start page: the company's modules and their first-steps assistant (what to do, in which order).
 */
class Home extends Page
{
    protected static ?int $navigationSort = -10;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected string $view = 'filament.pages.home';

    public static function getRoutePath(Panel $panel): string
    {
        return '/';
    }

    public static function getNavigationLabel(): string
    {
        return __('app.home.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('app.home.welcome', ['name' => $this->tenant()->name]);
    }

    /**
     * @return array<int, array{module: Module, steps: array<int, array<string, mixed>>, done: int, total: int, percent: int}>
     */
    public function getModules(): array
    {
        $tenant = $this->tenant();

        return collect(app(ModuleRegistry::class)->enabledFor($tenant))
            ->map(function (Module $module) use ($tenant): array {
                $steps = $module->progress($tenant);
                $done = count(array_filter($steps, fn (array $step): bool => $step['done']));
                $total = count($steps);

                return [
                    'module' => $module,
                    'steps' => $steps,
                    'done' => $done,
                    'total' => $total,
                    'percent' => $total > 0 ? (int) round($done * 100 / $total) : 100,
                    'read_only' => $tenant->license()->isReadOnly($module->key()),
                ];
            })
            ->values()
            ->all();
    }

    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->get();
    }
}
