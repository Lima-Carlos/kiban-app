<?php

namespace App\Filament\Pages;

use App\Access\AccessManager;
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
        $user = auth()->user();

        // Only the modules this person can open (one system, many modules).
        return collect(app(AccessManager::class)->modulesFor($user, $tenant))
            ->map(function (Module $module) use ($tenant, $user): array {
                // First steps only for who sets the module up; the others get their own block (e.g. driver buttons).
                $steps = $module->showsSetupTo($user) ? $module->progress($tenant) : [];
                $done = count(array_filter($steps, fn (array $step): bool => $step['done']));
                $total = count($steps);

                return [
                    'module' => $module,
                    'personal' => $module->personalHome($user),
                    'widgets' => $module->homeWidgets($user),
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

    /**
     * Urgent items of every module this person can open.
     *
     * @return array<int, array{text: string, url: ?string, color: string}>
     */
    public function getAttention(): array
    {
        return collect(app(AccessManager::class)->modulesFor(auth()->user(), $this->tenant()))
            ->flatMap(fn (Module $module): array => $module->attention(auth()->user()))
            ->values()
            ->all();
    }

    public function companyHasModules(): bool
    {
        return app(ModuleRegistry::class)->enabledFor($this->tenant()) !== [];
    }

    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->get();
    }
}
