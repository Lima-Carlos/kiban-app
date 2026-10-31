<?php

namespace App\Modules\Field\Filament\Orders\Pages;

use App\Filament\Support\EmptyListShowsPendingStep;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\WorkOrder;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

class ListOrders extends ListRecords
{
    use EmptyListShowsPendingStep;

    protected static string $resource = OrderResource::class;

    /** Says why an order cannot be opened (read-only, a first step missing). */
    public function getSubheading(): string|Htmlable|null
    {
        $response = OrderResource::getCreateAuthorizationResponse();

        return $response->denied() && $response->message() !== __('field.denied.manager') ? $response->message() : null;
    }

    /**
     * Tabs above the list (easy on the phone): still to do, today's, done, canceled, all.
     *
     * @return array<string, Tab>
     */
    public function getTabs(): array
    {
        $pending = array_map(fn (OrderStatus $status): string => $status->value, OrderStatus::pending());
        $today = [
            CarbonImmutable::now(OrderResource::timezone())->startOfDay()->utc(),
            CarbonImmutable::now(OrderResource::timezone())->endOfDay()->utc(),
        ];

        return [
            'pending' => Tab::make(__('field.orders.tabs.pending'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereIn('status', $pending))
                ->badge(fn (): int => WorkOrder::query()->whereIn('status', $pending)->count()),
            'today' => Tab::make(__('field.orders.tabs.today'))
                ->modifyQueryUsing(fn (Builder $query) => $query->whereBetween('scheduled_at', $today)),
            'done' => Tab::make(__('field.orders.tabs.done'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::Done->value)),
            'canceled' => Tab::make(__('field.orders.tabs.canceled'))
                ->modifyQueryUsing(fn (Builder $query) => $query->where('status', OrderStatus::Canceled->value)),
            'all' => Tab::make(__('field.orders.tabs.all')),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('agenda')
                ->label(__('field.agenda.title'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->color('gray')
                ->url(OrderResource::getUrl('agenda')),
        ];
    }
}
