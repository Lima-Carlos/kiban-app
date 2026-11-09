<?php

namespace App\Modules\Field\Filament\Customers\Pages;

use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Filament\Quotes\QuoteResource;
use App\Modules\Field\Services\CustomerPortal;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

class ViewCustomer extends ViewRecord
{
    protected static string $resource = CustomerResource::class;

    public function getTitle(): string|Htmlable
    {
        return $this->getRecord()->displayName();
    }

    protected function getHeaderActions(): array
    {
        return [
            // Straight to a new work order with this customer chosen.
            Action::make('newOrder')
                ->label(__('field.orders.new'))
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->url(fn (): string => OrderResource::getUrl('create', ['cliente' => $this->getRecord()->getKey()]))
                ->visible(fn (): bool => $this->getRecord()->is_active && OrderResource::canCreate()),
            Action::make('newQuote')
                ->label(__('field.quotes.new'))
                ->icon(Heroicon::OutlinedDocumentCurrencyDollar)
                ->color('gray')
                ->url(fn (): string => QuoteResource::getUrl('create', ['cliente' => $this->getRecord()->getKey()]))
                ->visible(fn (): bool => $this->getRecord()->is_active && QuoteResource::canCreate()),
            // The customer's own page (quotes, visits, reports, PMOC): open it or send it by WhatsApp.
            ActionGroup::make([
                Action::make('portalOpen')
                    ->label(__('field.portal.action_open'))
                    ->icon(Heroicon::OutlinedArrowTopRightOnSquare)
                    ->url(fn (): string => app(CustomerPortal::class)->url($this->getRecord()), shouldOpenInNewTab: true),
                Action::make('portalWhatsapp')
                    ->label(__('field.portal.action_whatsapp'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->visible(fn (): bool => filled($this->getRecord()->phone))
                    ->url(fn (): ?string => app(CustomerPortal::class)->whatsappUrl($this->getRecord()), shouldOpenInNewTab: true),
                Action::make('portalRenew')
                    ->label(__('field.portal.action_renew'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->requiresConfirmation()
                    ->modalDescription(__('field.portal.renew_help'))
                    ->authorize(fn (): bool => CustomerResource::canEdit($this->getRecord()))
                    ->action(function (): void {
                        app(CustomerPortal::class)->renew($this->getRecord());
                        Notification::make()->success()->title(__('field.portal.renewed'))->send();
                    }),
            ])
                ->label(__('field.portal.action'))
                ->icon(Heroicon::OutlinedGlobeAlt)
                ->button()
                ->color('gray')
                ->visible(fn (): bool => $this->getRecord()->is_active),
            EditAction::make()->color('gray'),
        ];
    }
}
