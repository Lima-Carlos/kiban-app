<?php

namespace App\Providers;

use App\Access\AccessManager;
use App\Http\Responses\LogoutResponse;
use App\Licensing\CoreClient;
use App\Licensing\LicenseManager;
use App\Licensing\LicenseVerifier;
use App\Modules\ModuleRegistry;
use App\Tenancy\TenantContext;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // One current company and one copy of each service per request.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(AccessManager::class);
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(CoreClient::class);
        $this->app->singleton(LicenseVerifier::class);
        $this->app->singleton(LicenseManager::class);

        $this->app->bind(LogoutResponseContract::class, LogoutResponse::class);
    }

    public function boot(): void
    {
        // Single address (app.../<company>/...): files (images, styles, scripts) are the same for every company,
        // at the root of the app, never under a company's path.
        URL::useAssetOrigin((string) config('app.url'));

        // Access rules of each module (license, read-only, first steps, limits).
        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->policies() as $model => $policy) {
                Gate::policy($model, $policy);
            }
        }
    }
}
