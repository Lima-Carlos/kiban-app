<?php

namespace App\Modules\Field\Filament\Quotes\Pages;

use App\Modules\Field\Enums\QuoteStatus;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Filament\Quotes\QuoteResource;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Services\FieldException;
use App\Modules\Field\Services\QuoteService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The quote's page: lines, totals, the customer and the answer. "Send to the customer" makes the link (and the
 * WhatsApp message); "See as the customer" opens the link; an approved quote without an order gets "Open work order".
 */
class ViewQuote extends ViewRecord
{
    protected static string $resource = QuoteResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Quote $quote */
        $quote = $this->getRecord();

        return $quote->label().' · '.$quote->customer->displayName();
    }

    protected function getHeaderActions(): array
    {
        $service = app(QuoteService::class);
        $canEdit = fn (): bool => QuoteResource::canEdit($this->getRecord());

        return [
            Action::make('send')
                ->label(fn (): string => $this->getRecord()->status === QuoteStatus::Draft ? __('field.quotes.send') : __('field.quotes.send_again'))
                ->icon(Heroicon::OutlinedPaperAirplane)
                ->visible(fn (): bool => $canEdit() && ! $this->getRecord()->status->isAnswered())
                ->action(function () use ($service): void {
                    try {
                        $quote = $service->send($this->getRecord());
                    } catch (FieldException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()
                        ->success()
                        ->title(__('field.quotes.sent'))
                        ->body(__('field.quotes.sent_help'))
                        ->actions([
                            Action::make('whatsapp')->label(__('field.quotes.whatsapp'))->url($service->whatsappUrl($quote), shouldOpenInNewTab: true)->button(),
                            Action::make('link')->label(__('field.quotes.open_link'))->url($service->link($quote), shouldOpenInNewTab: true)->link(),
                        ])
                        ->persistent()
                        ->send();
                    $this->getRecord()->refresh();
                }),
            Action::make('whatsapp')
                ->label(__('field.quotes.whatsapp'))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('gray')
                ->url(fn (): string => $service->whatsappUrl($this->getRecord()), shouldOpenInNewTab: true)
                ->visible(fn (): bool => $canEdit() && $this->getRecord()->status === QuoteStatus::Sent),
            Action::make('preview')
                ->label(__('field.quotes.see_as_customer'))
                ->icon(Heroicon::OutlinedEye)
                ->color('gray')
                ->url(fn (): string => $service->link($this->getRecord()), shouldOpenInNewTab: true)
                ->visible(fn (): bool => $this->getRecord()->status !== QuoteStatus::Draft),
            Action::make('convert')
                ->label(__('field.orders.new'))
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->visible(fn (): bool => $this->getRecord()->status === QuoteStatus::Approved && $this->getRecord()->order_id === null && OrderResource::canCreate())
                ->schema([
                    Select::make('service_type_id')
                        ->label(__('field.fields.service_type'))
                        ->options(fn (): array => ServiceType::query()->active()->orderBy('sort')->pluck('name', 'id')->all())
                        ->required(),
                ])
                ->action(function (array $data) use ($service): void {
                    $order = $service->convert($this->getRecord(), auth()->user(), (int) $data['service_type_id']);
                    $this->redirect(OrderResource::getUrl('view', ['record' => $order]));
                }),
            EditAction::make()->color('gray')->visible(fn (): bool => $canEdit() && $this->getRecord()->isEditable()),
        ];
    }
}
