<?php

namespace App\Modules\Agenda\Filament;

use App\Modules\Agenda\AgendaModule;
use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Filament\Appointments\AppointmentResource;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Services\AgendaAccess;
use App\Modules\Agenda\Services\AppointmentBook;
use App\Modules\Concerns\InModuleMenu;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;

/**
 * "Remind tomorrow" (front desk): tomorrow's appointments, each with a WhatsApp button that opens the ready
 * reminder (with the client's link to confirm, reschedule or cancel). Free: no paid WhatsApp service. A tapped
 * button shows as "sent".
 */
class RemindersPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = AgendaModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBellAlert;

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'agenda/lembretes';

    protected string $view = 'agenda.reminders';

    /** "2026-11-04": the day to remind (tomorrow by default). */
    public string $day = '';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->canWrite(AgendaModule::KEY) && app(AgendaAccess::class)->runsTheDesk();
    }

    public static function getNavigationLabel(): string
    {
        return __('agenda.reminders.page_title');
    }

    public function getTitle(): string
    {
        return __('agenda.reminders.page_title');
    }

    public function mount(): void
    {
        abort_unless(static::canAccess(), 403);

        $this->day = CarbonImmutable::now(AgendaSetting::timezone())->addDay()->toDateString();
    }

    public function date(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->day, AgendaSetting::timezone())->startOfDay();
    }

    public function previous(): void
    {
        $this->day = $this->date()->subDay()->toDateString();
    }

    public function next(): void
    {
        $this->day = $this->date()->addDay()->toDateString();
    }

    /**
     * @return Collection<int, Appointment>
     */
    public function appointments(): Collection
    {
        return Appointment::query()->with(['client', 'item', 'lines', 'professional.person'])
            ->whereIn('status', [AppointmentStatus::Scheduled->value, AppointmentStatus::Confirmed->value])
            ->whereBetween('starts_at', [$this->date()->utc(), $this->date()->endOfDay()->utc()])
            ->orderBy('starts_at')
            ->get();
    }

    public function markSent(int $id): void
    {
        abort_unless(static::canAccess(), 403);
        $appointment = AppointmentResource::getEloquentQuery()->findOrFail($id);
        app(AppointmentBook::class)->markSent($appointment, 'reminder', auth()->user());
    }
}
