<?php

namespace App\Modules\Field\Filament\Orders\Pages;

use App\Models\User;
use App\Modules\Field\Enums\OrderStatus;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldException;
use App\Modules\Field\Services\OrderReport;
use App\Modules\Field\Services\WorkOrderService;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The order's page: what, where, when and who, the technician's checklist and the whole history.
 * Actions: schedule / reschedule, take off the agenda, cancel (with the reason), write a note.
 */
class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var WorkOrder $order */
        $order = $this->getRecord();

        return $order->label().' · '.$order->serviceType->name;
    }

    /** A clash on the technician's agenda is only a warning (the service desk may squeeze a quick job in). */
    public static function warnClashes(WorkOrder $order): void
    {
        if ($order->technician === null || $order->scheduled_at === null) {
            return;
        }

        $clashes = app(WorkOrderService::class)->clashes($order->technician, $order->scheduled_at, $order->duration_minutes, $order);

        if ($clashes->isNotEmpty()) {
            Notification::make()
                ->warning()
                ->title(__('field.orders.clash', ['name' => $order->technician->name]))
                ->body($clashes->map(fn (WorkOrder $other): string => $other->label().' · '.OrderResource::when($other).' · '.$other->customer->displayName())->implode("\n"))
                ->persistent()
                ->send();
        }
    }

    protected function getHeaderActions(): array
    {
        $service = app(WorkOrderService::class);
        $editable = fn (): bool => ! $this->getRecord()->status->isClosed() && OrderResource::canEdit($this->getRecord());
        $run = function (callable $change): void {
            try {
                $change();
            } catch (FieldException $exception) {
                Notification::make()->danger()->title($exception->getMessage())->send();

                return;
            }

            Notification::make()->success()->title(__('field.orders.saved'))->send();
            $this->getRecord()->refresh();
        };

        $report = app(OrderReport::class);
        $done = fn (): bool => $this->getRecord()->status === OrderStatus::Done;

        return [
            // The report of a done order: to the customer by WhatsApp, or the PDF.
            Action::make('reportWhatsapp')
                ->label(__('field.report.send_whatsapp'))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->url(fn (): string => $report->whatsappUrl($this->getRecord()), shouldOpenInNewTab: true)
                ->visible($done),
            Action::make('reportPdf')
                ->label(__('field.report.pdf'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('gray')
                ->url(fn (): string => $report->pdfUrl($this->getRecord()), shouldOpenInNewTab: true)
                ->visible($done),
            Action::make('reportOpen')
                ->label(__('field.report.open'))
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url(fn (): string => $report->url($this->getRecord()), shouldOpenInNewTab: true)
                ->visible($done),
            Action::make('schedule')
                ->label(fn (): string => $this->getRecord()->scheduled_at ? __('field.orders.reschedule') : __('field.orders.schedule'))
                ->icon(Heroicon::OutlinedCalendarDays)
                ->visible($editable)
                ->fillForm(fn (): array => [
                    'technician_id' => $this->getRecord()->technician_id,
                    'scheduled_at' => $this->getRecord()->scheduled_at?->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'),
                    'duration_minutes' => $this->getRecord()->duration_minutes,
                ])
                ->schema(fn (): array => OrderResource::scheduleFields(required: true, order: $this->getRecord()))
                ->action(function (array $data) use ($service, $run): void {
                    $run(function () use ($service, $data): void {
                        $order = $service->schedule($this->getRecord(), User::query()->findOrFail($data['technician_id']), CarbonImmutable::parse($data['scheduled_at'], config('app.timezone')), (int) ($data['duration_minutes'] ?? 0) ?: null, auth()->user());
                        static::warnClashes($order);
                    });
                }),
            EditAction::make()->visible($editable)->color('gray'),
            ActionGroup::make([
                Action::make('note')
                    ->label(__('field.orders.note'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftEllipsis)
                    ->authorize(fn (): bool => OrderResource::canEdit($this->getRecord()))
                    ->schema([Textarea::make('text')->label(__('field.orders.note'))->required()->rows(3)])
                    ->action(fn (array $data) => $run(fn () => $service->note($this->getRecord(), (string) $data['text'], auth()->user()))),
                Action::make('unschedule')
                    ->label(__('field.orders.unschedule'))
                    ->icon(Heroicon::OutlinedCalendar)
                    ->visible(fn (): bool => $editable() && $this->getRecord()->status === OrderStatus::Scheduled)
                    ->requiresConfirmation()
                    ->action(fn () => $run(fn () => $service->unschedule($this->getRecord(), auth()->user()))),
                Action::make('cancel')
                    ->label(__('field.orders.cancel'))
                    ->icon(Heroicon::OutlinedXCircle)
                    ->color('danger')
                    ->visible($editable)
                    ->modalDescription(__('field.orders.cancel_help'))
                    ->schema([Textarea::make('reason')->label(__('field.fields.cancel_reason'))->required()->rows(3)])
                    ->action(fn (array $data) => $run(fn () => $service->cancel($this->getRecord(), (string) $data['reason'], auth()->user()))),
            ])->icon(Heroicon::OutlinedEllipsisVertical)->color('gray')->button()->label(__('field.orders.more')),
        ];
    }
}
