<?php

namespace App\Modules\Fleet\Filament\Appointments\Pages;

use App\Modules\Fleet\Enums\AppointmentStatus;
use App\Modules\Fleet\Filament\Appointments\AppointmentResource;
use App\Modules\Fleet\Models\Appointment;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * Month calendar of the appointments (maintenance in orange, washes in blue). Overdue ones in red.
 */
class AppointmentCalendar extends Page
{
    protected static string $resource = AppointmentResource::class;

    protected string $view = 'fleet.appointments.calendar';

    /** "2026-10" */
    public string $month = '';

    public function mount(): void
    {
        $this->month = CarbonImmutable::now(AppointmentResource::timezone())->format('Y-m');
    }

    public function getTitle(): string|Htmlable
    {
        return __('fleet.appointments.calendar');
    }

    public function previous(): void
    {
        $this->month = $this->first()->subMonth()->format('Y-m');
    }

    public function next(): void
    {
        $this->month = $this->first()->addMonth()->format('Y-m');
    }

    public function first(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->month.'-01', AppointmentResource::timezone())->startOfDay();
    }

    /**
     * Weeks of the month (Monday first), each day with its appointments.
     *
     * @return array<int, array<int, array{date: CarbonImmutable, inMonth: bool, today: bool, items: array<int, Appointment>}>>
     */
    public function getWeeks(): array
    {
        $timezone = AppointmentResource::timezone();
        $first = $this->first();
        $start = $first->startOfWeek(CarbonImmutable::MONDAY);
        $end = $first->endOfMonth()->endOfWeek(CarbonImmutable::SUNDAY);

        $appointments = Appointment::query()
            ->with('vehicle')
            ->where('status', '!=', AppointmentStatus::Canceled->value)
            ->whereBetween('scheduled_at', [$start->utc(), $end->endOfDay()->utc()])
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn (Appointment $appointment): string => $appointment->scheduled_at->timezone($timezone)->toDateString());

        $weeks = [];

        for ($day = $start; $day->lte($end); $day = $day->addDay()) {
            $weeks[intdiv((int) $start->diffInDays($day), 7)][] = [
                'date' => $day,
                'inMonth' => $day->month === $first->month,
                'today' => $day->isToday(),
                'items' => $appointments->get($day->toDateString(), collect())->all(),
            ];
        }

        return $weeks;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('list')
                ->label(__('fleet.appointments.list'))
                ->icon(Heroicon::OutlinedListBullet)
                ->color('gray')
                ->url(AppointmentResource::getUrl('index')),
        ];
    }
}
