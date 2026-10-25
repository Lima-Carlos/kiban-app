<?php

namespace App\Modules\TimeClock\Filament;

use App\Modules\ModuleRegistry;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Services\PunchPhotoStore;
use App\Modules\TimeClock\Services\PunchService;
use App\Modules\TimeClock\Services\ReceiptService;
use App\Modules\TimeClock\Services\ScheduleCalendar;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\TimeClockModule;
use App\People\PersonRuleException;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * "Clock in" on the phone (employees with a login): the live camera takes a selfie, the phone gives the
 * location, and the big button records the punch with the server's time. Nothing blocks the punch: without
 * location or photo, or outside the workplace radius, it is only flagged for the manager.
 * Below: today's hours and the last punches with their receipts.
 */
class PunchPage extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFingerPrint;

    protected static ?int $navigationSort = 1;

    protected static ?string $slug = 'ponto/bater-ponto';

    protected string $view = 'timeclock.punch-page';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null
            && $tenant->license()->canWrite(TimeClockModule::KEY)
            && auth()->check()
            && app(TimeClockAccess::class)->employeeFor(auth()->user()) !== null;
    }

    public static function getNavigationGroup(): ?string
    {
        return app(ModuleRegistry::class)->get(TimeClockModule::KEY)->name();
    }

    public static function getNavigationLabel(): string
    {
        return __('timeclock.punches.punch_now');
    }

    public function getTitle(): string
    {
        return __('timeclock.punches.punch_now');
    }

    public function employee(): Employee
    {
        return app(TimeClockAccess::class)->employeeFor(auth()->user()) ?? abort(403);
    }

    /** Today in the workplace's time zone. */
    public function today(): CarbonImmutable
    {
        return CarbonImmutable::now($this->employee()->workplace?->timezone ?? FilamentTimezone::get());
    }

    /**
     * @return array{date: CarbonImmutable, plan: ?array, holiday: ?string, schedule: mixed}
     */
    public function todayPlan(): array
    {
        $today = $this->today();

        return app(ScheduleCalendar::class)->period($this->employee(), $today, $today)[$today->toDateString()];
    }

    /**
     * @return Collection<int, Punch>
     */
    public function todayPunches(): Collection
    {
        $today = $this->today();

        return Punch::query()
            ->where('employee_id', $this->employee()->getKey())
            ->whereBetween('punched_at', [$today->startOfDay()->utc(), $today->endOfDay()->utc()])
            ->orderBy('punched_at')
            ->get();
    }

    /**
     * @return Collection<int, Punch>
     */
    public function recentPunches(): Collection
    {
        return Punch::query()->where('employee_id', $this->employee()->getKey())->latest('punched_at')->limit(20)->get();
    }

    public function receiptUrl(Punch $punch): string
    {
        return app(ReceiptService::class)->url($punch);
    }

    /** Called by the page with the selfie (data URL) and the location (when the phone gives them). */
    public function punch(?string $photo = null, ?float $latitude = null, ?float $longitude = null, ?float $accuracy = null): void
    {
        abort_unless(static::canAccess(), 403);

        try {
            $punch = app(PunchService::class)->punch($this->employee(), [
                'photo_path' => app(PunchPhotoStore::class)->fromDataUrl($photo),
                'latitude' => $latitude,
                'longitude' => $longitude,
                'accuracy' => $accuracy,
                'device' => (string) request()->userAgent(),
            ], auth()->user());
        } catch (PersonRuleException $exception) {
            Notification::make()->warning()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()
            ->success()
            ->title(__('timeclock.punches.done', ['time' => $punch->localTime()->format('H:i')]))
            ->body(__('timeclock.punches.done_body', ['nsr' => $punch->nsr]))
            ->actions([
                Action::make('receipt')
                    ->label(__('timeclock.receipt.open'))
                    ->url($this->receiptUrl($punch), shouldOpenInNewTab: true),
            ])
            ->persistent()
            ->send();
    }
}
