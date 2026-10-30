<?php

namespace App\Modules\Field\Filament\Orders\Pages;

use App\Models\User;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\DayPlanner;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\Field\Services\FieldException;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Collection;

/**
 * The agenda of a day: one column per technician with their orders in time order, the orders still without a
 * time ("To schedule"), and the map of the day with each address (a color per technician). Canceled orders do
 * not show. On the phone the columns are one under the other.
 */
class Agenda extends Page
{
    protected static string $resource = OrderResource::class;

    protected string $view = 'field.agenda';

    /** "2026-10-31" */
    public string $day = '';

    /** Colors of the technicians on the map and the columns, in order. */
    public const COLORS = ['#044299', '#0F766E', '#B45309', '#7C3AED', '#BE123C', '#15803D', '#0369A1', '#A16207'];

    public function mount(): void
    {
        abort_unless(OrderResource::canViewAny(), 403);

        $this->day = CarbonImmutable::now(OrderResource::timezone())->toDateString();
    }

    public function getTitle(): string|Htmlable
    {
        return __('field.agenda.title');
    }

    public function date(): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('Y-m-d', $this->day, OrderResource::timezone())->startOfDay();
    }

    public function previous(): void
    {
        $this->day = $this->date()->subDay()->toDateString();
    }

    public function next(): void
    {
        $this->day = $this->date()->addDay()->toDateString();
    }

    public function today(): void
    {
        $this->day = CarbonImmutable::now(OrderResource::timezone())->toDateString();
    }

    /**
     * One column per technician (with a color) and their orders of the day.
     *
     * @return array<int, array{technician: User, color: string, orders: Collection<int, WorkOrder>}>
     */
    public function getColumns(): array
    {
        $date = $this->date();
        $orders = WorkOrder::query()
            ->with(['customer', 'site', 'serviceType'])
            ->where('status', '!=', OrderStatus::Canceled->value)
            ->whereBetween('scheduled_at', [$date->utc(), $date->endOfDay()->utc()])
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy('technician_id');

        return app(FieldAccess::class)->technicians()
            ->values()
            ->map(fn (User $technician, int $index): array => [
                'technician' => $technician,
                'color' => self::COLORS[$index % count(self::COLORS)],
                'orders' => $orders->get($technician->getKey(), collect()),
            ])
            ->all();
    }

    /**
     * Orders without a time yet (oldest and most urgent first).
     *
     * @return Collection<int, WorkOrder>
     */
    public function getUnscheduled(): Collection
    {
        return WorkOrder::query()
            ->with(['customer', 'site', 'serviceType'])
            ->where('status', OrderStatus::Open->value)
            ->orderByRaw("case priority when 'urgent' then 0 when 'high' then 1 else 2 end")
            ->orderBy('number')
            ->limit(50)
            ->get();
    }

    /**
     * Pins of the day's map: orders with a point, colored by technician.
     *
     * @param  array<int, array{technician: User, color: string, orders: Collection<int, WorkOrder>}>  $columns
     * @return array<int, array{lat: float, lng: float, color: string, title: string, url: string}>
     */
    public function pins(array $columns): array
    {
        return collect($columns)
            ->flatMap(fn (array $column): Collection => $column['orders']
                ->filter(fn (WorkOrder $order): bool => (bool) $order->site?->hasPoint())
                ->map(fn (WorkOrder $order): array => [
                    'lat' => $order->site->latitude,
                    'lng' => $order->site->longitude,
                    'color' => $column['color'],
                    'title' => $order->scheduled_at->setTimezone(OrderResource::timezone())->format('H:i').' · '.$column['technician']->name.' · '.$order->customer->displayName(),
                    'url' => OrderResource::getUrl('view', ['record' => $order]),
                ]))
            ->values()
            ->all();
    }

    /**
     * "Suggest order" of a technician's day: the shortest way (nearest service next, from the company's base) with
     * the times one after the other. The office sees the list first and confirms.
     */
    public function suggestAction(): Action
    {
        $plan = fn (array $arguments): array => app(DayPlanner::class)->suggest(
            app(FieldAccess::class)->technicians()->firstWhere('id', (int) ($arguments['technician'] ?? 0)) ?? abort(404),
            $this->date(),
        );

        return Action::make('suggest')
            ->label(__('field.agenda.suggest'))
            ->icon(Heroicon::OutlinedSparkles)
            ->color('gray')
            ->size('xs')
            ->link()
            ->visible(fn (): bool => OrderResource::canCreate())
            ->modalHeading(__('field.agenda.suggest_title'))
            ->modalDescription(__('field.agenda.suggest_help'))
            ->modalSubmitActionLabel(__('field.agenda.suggest_apply'))
            ->modalContent(fn (array $arguments) => view('field.agenda-suggest', ['plan' => $plan($arguments), 'timezone' => OrderResource::timezone()]))
            ->action(function (array $arguments) use ($plan): void {
                try {
                    app(DayPlanner::class)->apply($plan($arguments), auth()->user());
                } catch (FieldException $exception) {
                    Notification::make()->title($exception->getMessage())->danger()->send();

                    return;
                }

                Notification::make()->title(__('field.agenda.suggest_done'))->success()->send();
            });
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('field.orders.new'))
                ->icon(Heroicon::OutlinedPlus)
                ->url(OrderResource::getUrl('create'))
                ->visible(fn (): bool => OrderResource::canCreate()),
        ];
    }
}
