<?php

namespace App\Modules\Agenda\Filament;

use App\Connections\Bridges\AgendaTimeClock;
use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Enums\AgendaRole;
use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Filament\Appointments\AppointmentResource;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\Professional;
use App\Modules\Agenda\Services\AgendaAccess;
use App\Modules\Agenda\Services\AgendaException;
use App\Modules\Agenda\Services\AppointmentBook;
use App\Modules\Agenda\Services\WorkingHours;
use App\Modules\Concerns\InModuleMenu;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * The desk's calendar: a day with one column per professional (on the phone, one under the other) or a week, the
 * cards in time order with the pet, the tutor, the services and the status, and a button for the next step
 * ("Arrived", "Start", "Pet ready", "Delivered"). With the Ponto, each column shows whether the professional has
 * clocked in today. A professional sees only their own column.
 */
class CalendarPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = AgendaModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'agenda';

    protected string $view = 'agenda.calendar';

    /** "2026-11-03" */
    public string $day = '';

    /** "day" or "week". */
    public string $mode = 'day';

    /** One professional only (week view), or null for all. */
    public ?int $professional = null;

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->hasModule(AgendaModule::KEY) && AppointmentResource::canViewAny();
    }

    public static function getNavigationLabel(): string
    {
        return __('agenda.calendar.title');
    }

    public function getTitle(): string|Htmlable
    {
        return __('agenda.calendar.title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->day = CarbonImmutable::now(AgendaSetting::timezone())->toDateString();
    }

    public function date(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->day, AgendaSetting::timezone())->startOfDay();
    }

    public function previous(): void
    {
        $this->day = $this->date()->sub($this->mode === 'week' ? '1 week' : '1 day')->toDateString();
    }

    public function next(): void
    {
        $this->day = $this->date()->add($this->mode === 'week' ? '1 week' : '1 day')->toDateString();
    }

    public function today(): void
    {
        $this->day = CarbonImmutable::now(AgendaSetting::timezone())->toDateString();
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode === 'week' ? 'week' : 'day';
    }

    /**
     * Professionals shown (a professional sees only themselves).
     *
     * @return Collection<int, Professional>
     */
    public function professionals(): Collection
    {
        $access = app(AgendaAccess::class);
        $query = Professional::query()->active()->with(['person', 'services'])->orderBy('sort')->orderBy('id');

        if ($access->role() === AgendaRole::Professional) {
            $query->whereKey($access->professionalFor()?->getKey() ?? 0);
        } elseif ($this->professional !== null) {
            $query->whereKey($this->professional);
        }

        return $query->get();
    }

    /**
     * One column per professional with the day's appointments, the hours of the day and (with the Ponto) whether
     * they have clocked in.
     *
     * @return array<int, array{professional: Professional, appointments: Collection<int, Appointment>, hours: ?string, clock: ?array}>
     */
    public function columns(): array
    {
        $professionals = $this->professionals();
        $date = $this->date();
        $appointments = $this->appointments($professionals, $date, $date->endOfDay())->groupBy('professional_id');
        $clock = $date->isSameDay(CarbonImmutable::now(AgendaSetting::timezone())) ? app(AgendaTimeClock::class)->today($professionals) : [];
        $hours = app(WorkingHours::class);

        return $professionals->map(fn (Professional $professional): array => [
            'professional' => $professional,
            'appointments' => $appointments->get($professional->getKey(), collect()),
            'hours' => collect($hours->intervals($professional, $date))->map(fn (array $piece): string => $piece[0]->format('H:i').'–'.$piece[1]->format('H:i'))->implode(' · ') ?: null,
            'clock' => $clock[$professional->getKey()] ?? null,
        ])->all();
    }

    /**
     * The week (Monday to Sunday): each day with its appointments.
     *
     * @return array<string, array{date: CarbonImmutable, appointments: Collection<int, Appointment>}>
     */
    public function week(): array
    {
        $start = $this->date()->startOfWeek();
        $appointments = $this->appointments($this->professionals(), $start, $start->addDays(6)->endOfDay())
            ->groupBy(fn (Appointment $appointment): string => $appointment->starts_at->setTimezone(AgendaSetting::timezone())->toDateString());
        $days = [];

        for ($day = 0; $day < 7; $day++) {
            $date = $start->addDays($day);
            $days[$date->toDateString()] = ['date' => $date, 'appointments' => $appointments->get($date->toDateString(), collect())];
        }

        return $days;
    }

    /**
     * Appointments of a professional's day (for the professional's start page).
     *
     * @return Collection<int, Appointment>
     */
    public static function dayOf(Professional $professional): Collection
    {
        $today = CarbonImmutable::now(AgendaSetting::timezone());

        return Appointment::query()->with(['client', 'item', 'lines'])
            ->where('professional_id', $professional->getKey())
            ->where('status', '!=', AppointmentStatus::Canceled->value)
            ->whereBetween('starts_at', [$today->startOfDay()->utc(), $today->endOfDay()->utc()])
            ->orderBy('starts_at')
            ->get();
    }

    /**
     * @param  Collection<int, Professional>  $professionals
     * @return Collection<int, Appointment>
     */
    protected function appointments(Collection $professionals, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $appointments = Appointment::query()->with(['client', 'item', 'lines.service', 'professional.person'])
            ->whereIn('professional_id', $professionals->modelKeys())
            ->where('status', '!=', AppointmentStatus::Canceled->value)
            ->whereBetween('starts_at', [$from->utc(), $to->utc()])
            ->orderBy('starts_at')
            ->get();

        // Clients who miss a lot, once for all the cards.
        $flag = AgendaSetting::current()->no_show_flag;
        $noShows = Appointment::query()->where('status', AppointmentStatus::NoShow->value)->whereIn('customer_id', $appointments->pluck('customer_id')->unique())
            ->selectRaw('customer_id, count(*) as total')->groupBy('customer_id')->pluck('total', 'customer_id');
        $appointments->each(fn (Appointment $appointment) => $appointment->setAttribute('flagged', $flag > 0 && (int) $noShows->get($appointment->customer_id, 0) >= $flag));

        return $appointments;
    }

    /** The next step of the card's button ("Arrived", "Start", "Pet ready", "Delivered"), or null. */
    public function nextStep(Appointment $appointment): ?AppointmentStatus
    {
        return match ($appointment->status) {
            AppointmentStatus::Scheduled, AppointmentStatus::Confirmed => AppointmentStatus::Arrived,
            AppointmentStatus::Arrived => AppointmentStatus::InService,
            AppointmentStatus::InService => AppointmentStatus::Ready,
            AppointmentStatus::Ready => AppointmentStatus::Delivered,
            default => null,
        };
    }

    public function canWork(Appointment $appointment): bool
    {
        return AppointmentResource::can('work', $appointment);
    }

    public function advance(int $id): void
    {
        $appointment = AppointmentResource::getEloquentQuery()->findOrFail($id);
        abort_unless($this->canWork($appointment), 403);
        $next = $this->nextStep($appointment);

        if ($next === null) {
            return;
        }

        try {
            app(AppointmentBook::class)->setStatus($appointment, $next, auth()->user());
        } catch (AgendaException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title(__('agenda.calendar.moved_to', ['name' => $appointment->title(), 'status' => $next->getLabel()]))->send();
    }

    /** The desk tapped a WhatsApp button (the message opens in a new tab): it shows as sent. */
    public function markSent(int $id, string $what): void
    {
        $appointment = AppointmentResource::getEloquentQuery()->findOrFail($id);
        abort_unless($this->canWork($appointment), 403);
        app(AppointmentBook::class)->markSent($appointment, $what === 'ready' ? 'ready' : 'reminder', auth()->user());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('agenda.appointments.new'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(fn (): string => AppointmentResource::getUrl('create', ['data' => $this->day]))
                ->visible(fn (): bool => AppointmentResource::canCreate()),
            Action::make('reminders')
                ->label(__('agenda.reminders.title'))
                ->icon(Heroicon::OutlinedBellAlert)
                ->color('gray')
                ->url(fn (): string => RemindersPage::getUrl())
                ->visible(fn (): bool => RemindersPage::canAccess()),
        ];
    }
}
