<?php

namespace App\Filament\Pages;

use App\Digest\WeeklyDigest;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Livewire\Attributes\Url;

/**
 * "Resumo da semana" (Company group): the week of every system of the company in one page, the same summary the
 * owner and the administrators get on Monday in the bell and by e-mail (WeeklyDigest). Any past week can be seen.
 */
class WeeklyDigestPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedNewspaper;

    protected static ?string $slug = 'empresa/resumo-da-semana';

    protected static ?int $navigationSort = -3;

    protected string $view = 'filament.pages.weekly-digest';

    #[Url]
    public ?string $week = null;

    public static function getNavigationGroup(): ?string
    {
        return __('app.menu.company');
    }

    /** The owner and the administrators of the company. */
    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();
        $user = auth()->user();

        return $tenant !== null && $user !== null && $tenant->license()->isActive()
            && in_array($user->roleIn($tenant), ['owner', 'admin'], true);
    }

    public static function getNavigationLabel(): string
    {
        return __('digest.title');
    }

    public function getTitle(): string
    {
        return __('digest.title');
    }

    public function getSubheading(): ?string
    {
        return app(WeeklyDigest::class)->weekLabel($this->start());
    }

    public function start(): CarbonImmutable
    {
        $tz = FilamentTimezone::get();
        $week = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $this->week) ? CarbonImmutable::parse($this->week, $tz) : null;

        return ($week ?? WeeklyDigest::weekBefore(CarbonImmutable::now($tz)))->startOfWeek(CarbonImmutable::MONDAY)->startOfDay();
    }

    /**
     * @return array<string, mixed>
     */
    public function digest(): array
    {
        $tenant = app(TenantContext::class)->get();

        return app(WeeklyDigest::class)->build($tenant, $this->start(), auth()->user());
    }

    protected function getHeaderActions(): array
    {
        $tenant = app(TenantContext::class)->get();

        return [
            Action::make('previous')
                ->label(__('digest.previous'))
                ->icon(Heroicon::OutlinedChevronLeft)
                ->color('gray')
                ->action(fn () => $this->week = $this->start()->subWeek()->toDateString()),
            Action::make('next')
                ->label(__('digest.next'))
                ->icon(Heroicon::OutlinedChevronRight)
                ->color('gray')
                ->disabled(fn (): bool => $this->start()->addWeek()->gt(CarbonImmutable::now(FilamentTimezone::get())))
                ->action(fn () => $this->week = $this->start()->addWeek()->toDateString()),
            Action::make('email')
                ->label(fn (): string => ($tenant->fresh()->weekly_digest_email ?? true) ? __('digest.email_on') : __('digest.email_off'))
                ->icon(fn (): Heroicon => ($tenant->fresh()->weekly_digest_email ?? true) ? Heroicon::OutlinedEnvelope : Heroicon::OutlinedNoSymbol)
                ->color('gray')
                ->tooltip(__('digest.email_help'))
                ->action(function () use ($tenant): void {
                    $tenant = $tenant->fresh();
                    $tenant->forceFill(['weekly_digest_email' => ! ($tenant->weekly_digest_email ?? true)])->save();
                    Notification::make()->success()->title($tenant->weekly_digest_email ? __('digest.email_turned_on') : __('digest.email_turned_off'))->send();
                }),
        ];
    }
}
