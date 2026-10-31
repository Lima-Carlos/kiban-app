<?php

namespace App\Providers;

use App\Access\AccessManager;
use App\Access\CurrentModule;
use App\Filament\Support\ModuleResource;
use App\Http\Responses\LogoutResponse;
use App\Licensing\CoreClient;
use App\Licensing\LicenseManager;
use App\Licensing\LicenseVerifier;
use App\Modules\ModuleRegistry;
use App\Tenancy\TenantContext;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as LogoutResponseContract;
use Filament\Actions\CreateAction;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Record numbers in addresses are numbers: "/frota/veiculos/create" is "page not found" (404), never an
        // error 500 from the database. Here (not in boot) because Filament creates the screens' routes early.
        Route::pattern('record', '[0-9]+');
        Route::pattern('order', '[0-9]+');

        // Single address (app.../<company>/...): files (images, styles, scripts) are the same for every company,
        // at the root of the app, never under a company's path (set here, before the panel builds its logo).
        URL::useAssetOrigin((string) config('app.url'));

        // One current company and one copy of each service per request.
        $this->app->scoped(TenantContext::class);
        $this->app->scoped(AccessManager::class);
        $this->app->scoped(CurrentModule::class);
        $this->app->singleton(ModuleRegistry::class);
        $this->app->singleton(CoreClient::class);
        $this->app->singleton(LicenseVerifier::class);
        $this->app->singleton(LicenseManager::class);

        $this->app->bind(LogoutResponseContract::class, LogoutResponse::class);
    }

    public function boot(): void
    {
        // Access rules of each module (license, read-only, first steps, limits).
        foreach (app(ModuleRegistry::class)->all() as $module) {
            foreach ($module->policies() as $model => $policy) {
                Gate::policy($model, $policy);
            }
        }

        // On the phone, every list shows one card per line, with the name of each field (no columns cut off
        // at the side). On larger screens it stays a table.
        // "Sort by: Default" on the phone, instead of "Sort by: -".
        Table::configureUsing(fn (Table $table) => $table->stackedOnMobile()->defaultSortOptionLabel(fn (): string => __('app.default_sort')));

        // "Novo veículo" instead of "Criar Veículo" on the create buttons and windows of the modules
        // (a label set by the screen itself, e.g. "Adicionar funcionário", still wins).
        CreateAction::configureUsing(function (CreateAction $action): void {
            $newLabel = function () use ($action): ?string {
                $livewire = $action->getLivewire();
                $resource = method_exists($livewire, 'getResource') ? $livewire::getResource() : null;

                return is_string($resource) && is_subclass_of($resource, ModuleResource::class) ? $resource::getNewRecordLabel() : null;
            };

            $action
                ->successNotificationTitle(__('app.created'))
                ->label(fn (): string => $newLabel() ?? __('filament-actions::create.single.label', ['label' => $action->getModelLabel()]))
                ->modalHeading(fn (): string => $newLabel() ?? __('filament-actions::create.single.modal.heading', ['label' => $action->getModelLabel()]));
        });
    }
}
