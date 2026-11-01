<?php

namespace App\Modules\Agenda\Filament\Appointments\Pages;

use App\Connections\Bridges\AgendaField;
use App\Modules\Agenda\Enums\AppointmentStatus;
use App\Modules\Agenda\Filament\Appointments\AppointmentResource;
use App\Modules\Agenda\Filament\Clients\ClientResource;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\AppointmentEvent;
use App\Modules\Agenda\Models\Professional;
use App\Modules\Agenda\Services\AgendaException;
use App\Modules\Agenda\Services\AgendaFormat;
use App\Modules\Agenda\Services\AgendaMessages;
use App\Modules\Agenda\Services\AppointmentBook;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Support\Country;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * One appointment: the details, the way (buttons for the next step), "Pet ready" with the WhatsApp message,
 * no-show, move, cancel, the Campo work order for the pick-up and the history.
 */
class ViewAppointment extends ViewRecord
{
    protected static string $resource = AppointmentResource::class;

    public function getTitle(): string|Htmlable
    {
        /** @var Appointment $record */
        $record = $this->getRecord();

        return $record->title().' · '.AgendaFormat::when($record->starts_at);
    }

    public function infolist(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make()
                ->columns(['default' => 1, 'sm' => 3])
                ->components([
                    TextEntry::make('status')->label(__('agenda.fields.status'))->badge(),
                    TextEntry::make('when')->label(__('agenda.fields.when'))
                        ->state(fn (Appointment $record): string => AgendaFormat::when($record->starts_at).' – '.AgendaFormat::time($record->ends_at)),
                    TextEntry::make('professional')->label(__('agenda.fields.professional'))->state(fn (Appointment $record): string => $record->professional->name()),
                    TextEntry::make('client')->label(__('agenda.fields.client'))
                        ->state(fn (Appointment $record): string => $record->client->name.($record->client->phone ? ' · '.Country::formatPhone($record->client->phone) : ''))
                        ->url(fn (Appointment $record): ?string => ClientResource::canView($record->client) ? ClientResource::getUrl('view', ['record' => $record->client]) : null)
                        ->color(fn (Appointment $record): ?string => $record->client->isFlagged() ? 'danger' : null)
                        ->helperText(fn (Appointment $record): ?string => $record->client->isFlagged() ? __('agenda.clients.flagged', ['count' => $record->client->noShows()]) : null),
                    TextEntry::make('item')->label(__('agenda.fields.item'))->state(fn (Appointment $record): ?string => $record->item?->label())->placeholder('—'),
                    TextEntry::make('item_notes')->label(__('agenda.fields.item_notes'))->state(fn (Appointment $record): ?string => $record->item?->notes)->color('danger')->placeholder('—'),
                    TextEntry::make('services')->label(__('agenda.fields.services'))->listWithLineBreaks()
                        ->state(fn (Appointment $record): array => $record->lines->map(fn ($line): string => $line->name.' · '.AgendaFormat::money($line->price_cents))->all()),
                    TextEntry::make('price_cents')->label(__('agenda.fields.total'))
                        ->state(fn (Appointment $record): string => AgendaFormat::money($record->price_cents).($record->clientPackage ? ' · '.__('agenda.appointments.with_package', ['name' => $record->clientPackage->name]) : '')),
                    TextEntry::make('origin')->label(__('agenda.fields.origin'))->formatStateUsing(fn (string $state): string => __('agenda.origins.'.$state)),
                    TextEntry::make('pickup')->label(__('agenda.appointments.sections.pickup'))
                        ->visible(fn (Appointment $record): bool => $record->pickup || $record->dropoff)
                        ->state(fn (Appointment $record): string => collect([
                            $record->pickup ? __('agenda.fields.pickup') : null,
                            $record->dropoff ? __('agenda.fields.dropoff') : null,
                            $record->pickupSite?->address(),
                            $record->pickup_from ? __('agenda.appointments.window', ['from' => $record->pickup_from, 'until' => $record->pickup_until ?: '—']) : null,
                        ])->filter()->implode(' · '))
                        ->columnSpanFull(),
                    TextEntry::make('field_order')->label(__('agenda.appointments.field_order'))
                        ->visible(fn (Appointment $record): bool => $record->field_order_id !== null)
                        ->state(fn (Appointment $record): ?string => $record->fieldOrder?->label().' · '.$record->fieldOrder?->status->getLabel())
                        ->url(fn (Appointment $record): ?string => $record->fieldOrder && OrderResource::canView($record->fieldOrder) ? OrderResource::getUrl('view', ['record' => $record->fieldOrder]) : null),
                    TextEntry::make('notes')->label(__('agenda.fields.notes'))->placeholder('—')->columnSpanFull(),
                ]),
            Section::make(__('agenda.appointments.history'))
                ->collapsible()
                ->collapsed()
                ->components([
                    TextEntry::make('events')->hiddenLabel()->listWithLineBreaks()
                        ->state(fn (Appointment $record): array => $record->events()->with('user')->get()->map(fn (AppointmentEvent $event): string => $event->created_at->setTimezone(AgendaSetting::timezone())->translatedFormat(__('app.datetime_format'))
                            .' · '.__('agenda.events.'.$event->type, [
                                'from' => isset($event->data['from']) && is_string($event->data['from']) ? (AppointmentStatus::tryFrom($event->data['from'])?->getLabel() ?? '') : '',
                                'to' => isset($event->data['to']) ? (AppointmentStatus::tryFrom((string) $event->data['to'])?->getLabel() ?? '') : '',
                            ])
                            .($event->user ? ' · '.$event->user->name : (($event->data['by_client'] ?? false) || ($event->data['origin'] ?? null) === Appointment::ONLINE ? ' · '.__('agenda.events.by_client') : '')))->all()),
                ]),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $next = [
            AppointmentStatus::Confirmed->value => Heroicon::OutlinedCheck,
            AppointmentStatus::Arrived->value => Heroicon::OutlinedMapPin,
            AppointmentStatus::InService->value => Heroicon::OutlinedSparkles,
            AppointmentStatus::Ready->value => Heroicon::OutlinedCheckBadge,
            AppointmentStatus::Delivered->value => Heroicon::OutlinedHandThumbUp,
        ];

        $actions = [];

        foreach ($next as $status => $icon) {
            $target = AppointmentStatus::from($status);
            $actions[] = Action::make('to_'.$status)
                ->label(__('agenda.actions.to.'.$status))
                ->icon($icon)
                ->color($target === AppointmentStatus::Ready ? 'success' : 'primary')
                ->authorize(fn (): bool => static::getResource()::can('work', $this->getRecord()))
                ->visible(fn (): bool => $this->getRecord()->status->canMoveTo($target) && $this->isNextStep($target))
                ->action(fn () => $this->moveTo($target));
        }

        return [
            ...$actions,
            Action::make('whatsappReady')
                ->label(__('agenda.actions.whatsapp_ready'))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->color('success')
                ->visible(fn (): bool => $this->getRecord()->status === AppointmentStatus::Ready && filled($this->getRecord()->client->phone))
                ->url(fn (): string => app(AgendaMessages::class)->ready($this->getRecord()), shouldOpenInNewTab: true)
                ->extraAttributes(['wire:click' => 'markSent(\'ready\')']),
            ActionGroup::make([
                Action::make('whatsappBooked')
                    ->label(__('agenda.actions.whatsapp_booked'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->visible(fn (): bool => $this->getRecord()->status->isOpen() && filled($this->getRecord()->client->phone))
                    ->url(fn (): string => app(AgendaMessages::class)->booked($this->getRecord()), shouldOpenInNewTab: true),
                Action::make('move')
                    ->label(__('agenda.actions.move'))
                    ->icon(Heroicon::OutlinedArrowPath)
                    ->authorize('update')
                    ->visible(fn (): bool => $this->getRecord()->status->isOpen())
                    ->fillForm(fn (): array => [
                        'professional_id' => $this->getRecord()->professional_id,
                        'date' => $this->getRecord()->starts_at->setTimezone(AgendaSetting::timezone())->toDateString(),
                        'time' => $this->getRecord()->starts_at->setTimezone(AgendaSetting::timezone())->format('H:i'),
                    ])
                    ->schema([
                        Select::make('professional_id')->label(__('agenda.fields.professional'))
                            ->options(fn (): array => Professional::query()->active()->with('person')->get()->mapWithKeys(fn (Professional $professional): array => [$professional->getKey() => $professional->name()])->all())
                            ->required(),
                        DatePicker::make('date')->label(__('agenda.fields.date'))->required(),
                        TimePicker::make('time')->label(__('agenda.fields.time'))->seconds(false)->format('H:i')->timezone(config('app.timezone'))->required(),
                    ])
                    ->action(function (array $data): void {
                        $this->run(fn () => app(AppointmentBook::class)->move($this->getRecord(), (int) $data['professional_id'], $data['date'].' '.substr((string) $data['time'], 0, 5), auth()->user()), __('agenda.appointments.moved'));
                    }),
                Action::make('fieldOrder')
                    ->label(__('agenda.actions.field_order'))
                    ->icon(Heroicon::OutlinedTruck)
                    ->authorize('update')
                    ->visible(fn (): bool => app(AgendaField::class)->canOpenFor($this->getRecord()))
                    ->requiresConfirmation()
                    ->modalDescription(__('agenda.appointments.field_order_help'))
                    ->action(function (): void {
                        $this->run(fn () => app(AgendaField::class)->openOrder($this->getRecord(), auth()->user()), __('agenda.appointments.field_order_done'));
                    }),
                Action::make('noShow')
                    ->label(__('agenda.actions.no_show'))
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->color('danger')
                    ->authorize('update')
                    ->visible(fn (): bool => $this->getRecord()->status->isOpen() && now()->gte($this->getRecord()->starts_at))
                    ->requiresConfirmation()
                    ->action(fn () => $this->moveTo(AppointmentStatus::NoShow)),
                Action::make('cancel')
                    ->label(__('agenda.actions.cancel'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->authorize('update')
                    ->visible(fn (): bool => $this->getRecord()->status->canMoveTo(AppointmentStatus::Canceled))
                    ->schema([TextInput::make('reason')->label(__('agenda.fields.cancel_reason'))->maxLength(250)])
                    ->action(function (array $data): void {
                        $this->run(fn () => app(AppointmentBook::class)->cancel($this->getRecord(), $data['reason'] ?? null, auth()->user()), __('agenda.appointments.canceled'));
                    }),
            ])->label(__('agenda.actions.more'))->button()->color('gray'),
        ];
    }

    /** Only the next one or two steps show as buttons (the desk may skip "confirmed" or "arrived"). */
    protected function isNextStep(AppointmentStatus $target): bool
    {
        $current = $this->getRecord()->status;

        if ($current === AppointmentStatus::Scheduled) {
            return in_array($target, [AppointmentStatus::Confirmed, AppointmentStatus::Arrived], true);
        }

        return $target->position() === $current->position() + 1;
    }

    public function moveTo(AppointmentStatus $status): void
    {
        $this->run(fn () => app(AppointmentBook::class)->setStatus($this->getRecord(), $status, auth()->user()), $status->getLabel());

        if ($status === AppointmentStatus::Ready && filled($this->getRecord()->client->phone)) {
            Notification::make()->success()->persistent()
                ->title(__('agenda.appointments.ready_title', ['name' => $this->getRecord()->title()]))
                ->body(__('agenda.appointments.ready_body'))
                ->actions([
                    Action::make('send')->label(__('agenda.actions.whatsapp_ready'))->button()
                        ->url(app(AgendaMessages::class)->ready($this->getRecord()), shouldOpenInNewTab: true),
                ])
                ->send();
        }
    }

    public function markSent(string $what): void
    {
        abort_unless(static::getResource()::can('work', $this->getRecord()), 403);
        app(AppointmentBook::class)->markSent($this->getRecord(), $what === 'ready' ? 'ready' : 'reminder', auth()->user());
    }

    protected function run(callable $change, string $done): void
    {
        try {
            $change();
        } catch (AgendaException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        $this->getRecord()->refresh();
        Notification::make()->success()->title($done)->send();
    }
}
