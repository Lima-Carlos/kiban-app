<?php

namespace App\Modules\Pedidos\Filament\Orders\Pages;

use App\Modules\Pedidos\Enums\OrderStatus;
use App\Modules\Pedidos\Filament\Orders\OrderResource;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Services\OrderService;
use App\Modules\Pedidos\Services\PedidosAccess;
use App\Modules\Pedidos\Services\PedidosException;
use App\Modules\Pedidos\Services\PedidosPeople;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One order: the next step (preparing, ready, delivered), who prepared and who delivered (may be changed later),
 * cancel with the reason, and the kitchen ticket.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('pedidos.orders.title', ['number' => $this->getRecord()->number]);
    }

    protected function order(): Order
    {
        /** @var Order */
        return $this->getRecord();
    }

    protected function getHeaderActions(): array
    {
        $service = app(OrderService::class);
        $people = app(PedidosPeople::class);
        $can = fn (): bool => OrderResource::can('work', $this->order());

        return [
            Action::make('start')->label(__('pedidos.board.start'))->icon(Heroicon::OutlinedFire)->color('warning')
                ->visible(fn (): bool => $can() && $this->order()->status === OrderStatus::New)
                ->action(fn () => $this->run(fn () => $service->start($this->order(), app(PedidosAccess::class)->personFor()?->getKey()))),
            Action::make('ready')->label(__('pedidos.board.ready'))->icon(Heroicon::OutlinedCheck)->color('success')
                ->visible(fn (): bool => $can() && in_array($this->order()->status, [OrderStatus::New, OrderStatus::Preparing], true))
                ->action(fn () => $this->run(fn () => $service->ready($this->order()))),
            Action::make('deliver')->label(__('pedidos.board.deliver'))->icon(Heroicon::OutlinedHandThumbUp)->color('success')
                ->visible(fn (): bool => $can() && in_array($this->order()->status, [OrderStatus::Ready, OrderStatus::Out], true))
                ->action(fn () => $this->run(fn () => $service->deliver($this->order()))),
            Action::make('ticket')->label(__('pedidos.ticket.button'))->icon(Heroicon::OutlinedPrinter)->color('gray')
                ->url(fn (): string => route('pedidos.ticket', ['order' => $this->order()->getKey()]), shouldOpenInNewTab: true),
            ActionGroup::make([
                Action::make('preparer')->label(__('pedidos.orders.set_preparer'))->icon(Heroicon::OutlinedFire)
                    ->visible($can)
                    ->schema([Select::make('person')->label(__('pedidos.fields.prepared_by'))->options(fn (): array => $people->team('cooks')->pluck('name', 'id')->all())])
                    ->fillForm(fn (): array => ['person' => $this->order()->prepared_by])
                    ->action(fn (array $data) => $this->run(fn () => $service->setPreparer($this->order(), filled($data['person']) ? (int) $data['person'] : null))),
                Action::make('deliverer')->label(__('pedidos.orders.set_deliverer'))->icon(Heroicon::OutlinedTruck)
                    ->visible(fn (): bool => $can() && $this->order()->isDelivery())
                    ->schema([Select::make('person')->label(__('pedidos.fields.delivered_by'))->options(fn (): array => $people->team('delivers')->pluck('name', 'id')->all())])
                    ->fillForm(fn (): array => ['person' => $this->order()->delivered_by])
                    ->action(fn (array $data) => $this->run(fn () => $service->setDeliverer($this->order(), filled($data['person']) ? (int) $data['person'] : null))),
                Action::make('cancel')->label(__('pedidos.orders.cancel'))->icon(Heroicon::OutlinedXCircle)->color('danger')
                    ->visible(fn (): bool => OrderResource::canEdit($this->order()) && ! $this->order()->status->isFinal())
                    ->schema([TextInput::make('reason')->label(__('pedidos.fields.cancel_reason'))->required()->maxLength(255)])
                    ->action(fn (array $data) => $this->run(fn () => $service->cancel($this->order(), (string) $data['reason']))),
            ]),
        ];
    }

    protected function run(callable $change): void
    {
        try {
            $this->record = $change();
            Notification::make()->success()->title(__('pedidos.orders.saved'))->send();
        } catch (PedidosException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
        }
    }
}
