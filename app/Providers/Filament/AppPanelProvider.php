<?php

namespace App\Providers\Filament;

use App\Filament\Pages\Home;
use App\Http\Middleware\EnsureLicense;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\RevalidateMembership;
use App\Http\Middleware\SetLocale;
use Filament\Actions\Action;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Support\Icons\Heroicon;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * The customer app: one panel for every company, at <subdomain>.<domain>. The menu shows only the
 * modules in the company's license. Sign-in is done by the Kiban Core (no login page here).
 */
class AppPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('app')
            ->path('')
            ->brandName(fn (): string => config('app.name'))
            ->brandLogo(asset('images/brand/logo.png'))
            ->darkModeBrandLogo(asset('images/brand/logo-dark.png'))
            ->brandLogoHeight('2.75rem')
            ->favicon(asset('images/brand/favicon.png'))
            ->colors([
                'primary' => array_map(Color::convertToOklch(...), config('kiban.brand.primary_palette')),
                'gray' => Color::Neutral,
            ])
            ->pages([
                Home::class,
                ...$this->modulePages(),
            ])
            ->resources($this->moduleResources())
            ->widgets(collect(config('kiban.modules'))->flatMap(fn (string $module): array => app($module)->widgets())->all())
            ->userMenuItems([
                Action::make('core_account')
                    ->label(fn (): string => __('app.menu.account'))
                    ->icon(Heroicon::OutlinedUserCircle)
                    ->url(fn (): string => config('kiban.core.url').'/conta')
                    ->openUrlInNewTab(),
                ...$this->localeMenuItems(),
            ])
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.license-notice'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                IdentifyTenant::class,
                EnsureLicense::class,
                SetLocale::class,
                // Before Authenticate (which Laravel runs early): someone outside the company gets a clear page, not a bare 403.
                RevalidateMembership::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Also on the screens' background requests (Livewire: buttons, forms, tables). Without these,
            // the company is unknown there and every action is refused (403).
            ->persistentMiddleware([
                IdentifyTenant::class,
                EnsureLicense::class,
                SetLocale::class,
                RevalidateMembership::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }

    /**
     * One entry per language in the user menu; the current one is marked with a check.
     *
     * @return array<int, Action>
     */
    protected function localeMenuItems(): array
    {
        return collect(config('kiban.locales'))
            ->map(fn (string $name, string $code): Action => Action::make("locale_{$code}")
                ->label($name)
                ->icon(fn (): Heroicon => app()->getLocale() === $code ? Heroicon::OutlinedCheck : Heroicon::OutlinedLanguage)
                ->url(fn (): string => route('locale.switch', $code)))
            ->values()
            ->all();
    }

    /**
     * @return array<int, class-string>
     */
    protected function modulePages(): array
    {
        return collect(config('kiban.modules'))
            ->flatMap(fn (string $module): array => app($module)->pages())
            ->all();
    }

    /**
     * @return array<int, class-string>
     */
    protected function moduleResources(): array
    {
        return collect(config('kiban.modules'))
            ->flatMap(fn (string $module): array => app($module)->resources())
            ->all();
    }
}
