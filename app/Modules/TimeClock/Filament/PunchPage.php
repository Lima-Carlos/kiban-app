<?php

namespace App\Modules\TimeClock\Filament;

use App\Connections\Bridges\FleetTimeClock;
use App\Modules\Concerns\InModuleMenu;
use App\Modules\Fleet\Models\Vehicle;
use App\Modules\TimeClock\Models\Employee;
use App\Modules\TimeClock\Models\Punch;
use App\Modules\TimeClock\Services\PhotoNoticeService;
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
 * Without signal, the punch is kept on the phone and sent by itself later (public/js/punch-queue.js, sent to
 * PhoneQueueController); each kept punch shows "waiting for signal" until it goes.
 * Below: today's hours and the last punches with their receipts.
 */
class PunchPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = TimeClockModule::KEY;

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
     * Frota + Ponto: the person's vehicles still waiting for the inspection, right after clocking in (a suggestion).
     *
     * @return Collection<int, array{vehicle: Vehicle, url: string}>
     */
    public function inspectionSuggestions(): Collection
    {
        return app(FleetTimeClock::class)->inspectionsAfterPunch(auth()->user(), $this->todayPunches()->count());
    }

    /**
     * @return Collection<int, Punch>
     */
    public function recentPunches(): Collection
    {
        return Punch::query()->where('employee_id', $this->employee()->getKey())->latest('punched_at')->limit(20)->get();
    }

    /** Whose punches are in the phone's queue: only this person in this company sends them. */
    public function queueOwner(): string
    {
        return app(TenantContext::class)->get()->getKey().':'.auth()->id();
    }

    public function receiptUrl(Punch $punch): string
    {
        return app(ReceiptService::class)->url($punch);
    }

    /** The privacy notice of the photos still has to be read (first time, or a new version). */
    public function needsPhotoNotice(): bool
    {
        return ! app(PhotoNoticeService::class)->acknowledged($this->employee());
    }

    public function allowsWithoutPhoto(): bool
    {
        return app(PhotoNoticeService::class)->allowsWithoutPhoto();
    }

    /** "I am aware": recorded, then the page opens the camera. */
    public function acknowledgePhotoNotice(): void
    {
        abort_unless(static::canAccess(), 403);

        app(PhotoNoticeService::class)->record($this->employee(), Punch::PHONE, auth()->user(), null, request()->ip(), (string) request()->userAgent());
    }

    /**
     * Called by the page with the selfie (data URL) and the location (when the phone gives them).
     * Without the acknowledgement of the notice, no photo is stored.
     */
    public function punch(?string $photo = null, ?float $latitude = null, ?float $longitude = null, ?float $accuracy = null, bool $withoutPhoto = false): void
    {
        abort_unless(static::canAccess(), 403);

        $declined = $withoutPhoto && $this->allowsWithoutPhoto();

        if ($declined || $this->needsPhotoNotice()) {
            $photo = null;
        }

        try {
            $punch = app(PunchService::class)->punch($this->employee(), [
                'photo_declined' => $declined,
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
