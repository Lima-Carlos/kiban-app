<?php

namespace App\Providers\Filament;

use App\Filament\Pages\AccessProfilesPage;
use App\Filament\Pages\CompanyProfilePage;
use App\Filament\Pages\ConnectionsPage;
use App\Filament\Pages\DocumentsPage;
use App\Filament\Pages\DocumentTypesPage;
use App\Filament\Pages\EpiItemsPage;
use App\Filament\Pages\EpiPage;
use App\Filament\Pages\Home;
use App\Filament\Pages\MyDocumentsPage;
use App\Filament\Pages\MyEpiPage;
use App\Filament\Pages\PeoplePage;
use App\Filament\Pages\PersonDocumentsPage;
use App\Filament\Pages\PersonEpiPage;
use App\Filament\Pages\TeamsPage;
use App\Http\Middleware\EnsureLicense;
use App\Http\Middleware\IdentifyTenant;
use App\Http\Middleware\RecordAccess;
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
                PeoplePage::class,
                AccessProfilesPage::class,
                TeamsPage::class,
                CompanyProfilePage::class,
                ConnectionsPage::class,
                DocumentsPage::class,
                DocumentTypesPage::class,
                PersonDocumentsPage::class,
                MyDocumentsPage::class,
                EpiPage::class,
                EpiItemsPage::class,
                PersonEpiPage::class,
                MyEpiPage::class,
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
                // The person's own documents (driver's license, medical exam...), read only, when there are any.
                Action::make('my_documents')
                    ->label(fn (): string => __('documents.mine.title'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->visible(fn (): bool => MyDocumentsPage::hasDocuments())
                    ->url(fn (): string => MyDocumentsPage::getUrl()),
                // The person's PPE and tools (and the deliveries to sign), when there are any.
                Action::make('my_epi')
                    ->label(fn (): string => __('epi.mine.title'))
                    ->icon(Heroicon::OutlinedWrenchScrewdriver)
                    ->visible(fn (): bool => MyEpiPage::hasDeliveries())
                    ->url(fn (): string => MyEpiPage::getUrl()),
                // LGPD: the privacy channel, "download my data" and requests, in the Kiban account.
                Action::make('privacy')
                    ->label(fn (): string => __('app.menu.privacy'))
                    ->icon(Heroicon::OutlinedShieldCheck)
                    ->url(fn (): string => config('kiban.core.url').'/conta/privacidade')
                    ->openUrlInNewTab(),
                ...$this->localeMenuItems(),
            ])
            // The bell: warnings of the current company, checked every 30 seconds.
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.pwa-head'))
            // The systems of the person as icons at the top of the menu (only with two or more).
            ->renderHook(PanelsRenderHook::SIDEBAR_NAV_START, fn () => view('filament.module-switcher'))
            // "Free trial: N days left · See plans" (owner/administrators, while every system is in trial).
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.trial-banner'))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.install-hint'))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.license-notice'))
            // "News: your systems now work together" (ConnectionCatalog), until each person dismisses it.
            ->renderHook(PanelsRenderHook::BODY_END, fn () => view('filament.connections-modal'))
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
                RecordAccess::class,
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
