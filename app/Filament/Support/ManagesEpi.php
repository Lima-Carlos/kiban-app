<?php

namespace App\Filament\Support;

use App\Filament\Pages\MyEpiPage;
use App\Models\Tenant;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\People\Epi\DeliveryReason;
use App\People\Epi\EpiDelivery;
use App\People\Epi\EpiDeliveryLine;
use App\People\Epi\EpiException;
use App\People\Epi\EpiItem;
use App\People\Epi\EpiKind;
use App\People\Epi\EpiRecord;
use App\People\Epi\EpiService;
use App\People\Epi\ReturnOutcome;
use App\People\Epi\ReturnReason;
use App\People\PeopleService;
use App\People\Person;
use App\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\ViewField;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Components\View;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Pieces shared by the screens of "PPE and tools" (the company's list, a person's page, "My deliveries"): the
 * delivery form, the signature with the finger, return, replacement, the details of a delivery and the columns.
 */
trait ManagesEpi
{
    protected function epi(): EpiService
    {
        return app(EpiService::class);
    }

    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->get();
    }

    /**
     * Kinds the signed-in person handles ("epi", "tool").
     *
     * @return array<int, string>
     */
    protected function kinds(): array
    {
        return $this->epi()->kindsFor(auth()->user());
    }

    /** Like People: allowed while one of the company's systems is not read-only. */
    protected function tenantCanWrite(): bool
    {
        $modules = app(ModuleRegistry::class)->enabledFor($this->tenant());

        return $modules === [] || collect($modules)->contains(fn (Module $module): bool => $this->tenant()->license()->canWrite($module->key()));
    }

    protected function canDeliver(): bool
    {
        return $this->kinds() !== [] && $this->tenantCanWrite();
    }

    // ---------------------------------------------------------------- new delivery

    /**
     * @return array<int, mixed>
     */
    protected function deliveryFields(?Person $person = null): array
    {
        $options = fn (): array => $this->epi()->itemOptions($this->kinds());
        $personOf = fn (Get $get): ?Person => $person ?? (filled($get('person_id')) ? Person::query()->find($get('person_id')) : null);

        return [
            Select::make('person_id')
                ->label(__('epi.fields.person'))
                ->options(fn (): array => Person::query()->active()->orderBy('name')->pluck('name', 'id')->all())
                ->searchable()
                ->required()
                ->live()
                ->validationMessages(['required' => __('epi.validation.person')])
                ->visible($person === null),
            Repeater::make('lines')
                ->label(__('epi.fields.items'))
                ->schema([
                    Grid::make(['default' => 4])->components([
                        Select::make('item_id')
                            ->label(__('epi.fields.item'))
                            ->options($options)
                            ->searchable()
                            ->required()
                            ->validationMessages(['required' => __('epi.validation.item')])
                            ->columnSpan(['default' => 3]),
                        TextInput::make('quantity')
                            ->label(__('epi.fields.quantity'))
                            ->integer()
                            ->default(1)
                            ->minValue(1)
                            ->maxValue(999)
                            ->required()
                            ->columnSpan(['default' => 1]),
                    ]),
                ])
                ->default([['item_id' => null, 'quantity' => 1]])
                ->minItems(1)
                ->addActionLabel(__('epi.actions.add_line'))
                ->reorderable(false)
                ->hiddenLabel(false),
            Grid::make(['default' => 1, 'sm' => 2])->components([
                DatePicker::make('delivered_on')
                    ->label(__('epi.fields.delivered_on'))
                    ->default(fn (): string => EpiItem::today()->toDateString())
                    ->maxDate(fn (): string => EpiItem::today()->toDateString())
                    ->required(),
                Select::make('reason')
                    ->label(__('epi.fields.reason'))
                    ->options(DeliveryReason::options())
                    ->default(DeliveryReason::First->value)
                    ->required()
                    ->selectablePlaceholder(false),
            ]),
            Textarea::make('notes')
                ->label(__('epi.fields.notes'))
                ->rows(2),
            Radio::make('sign_mode')
                ->label(__('epi.fields.sign_mode'))
                ->options(fn (Get $get): array => [
                    'now' => __('epi.sign.mode_now'),
                    'later' => ($personOf($get)?->hasLogin() ?? true) ? __('epi.sign.mode_later') : __('epi.sign.mode_later_no_login'),
                ])
                ->disableOptionWhen(fn (string $value, Get $get): bool => $value === 'later' && $personOf($get) !== null && ! $personOf($get)->hasLogin())
                ->default('now')
                ->required(),
        ];
    }

    /** "New delivery" (for one person, or choosing the person). Then the signature, now or on the person's phone. */
    protected function newDeliveryAction(?Person $person = null): Action
    {
        return Action::make('newDelivery')
            ->label(__('epi.actions.new_delivery'))
            ->icon(Heroicon::OutlinedPlus)
            ->authorize(fn (): bool => $this->canDeliver())
            ->modalHeading(__('epi.actions.new_delivery'))
            ->modalDescription(fn (): ?string => $person !== null ? $person->name : null)
            ->modalSubmitActionLabel(__('epi.actions.save_delivery'))
            ->modalWidth('2xl')
            ->schema(fn (): array => $this->deliveryFields($person))
            ->action(function (array $data, Action $action) use ($person): void {
                $owner = $person ?? Person::query()->findOrFail($data['person_id']);

                try {
                    $delivery = $this->epi()->deliver($owner, (array) ($data['lines'] ?? []), $data, auth()->user(), $this->kinds());
                } catch (EpiException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->halt();

                    return;
                }

                foreach ($this->epi()->negativeStock($delivery) as $warning) {
                    Notification::make()->warning()->title($warning)->body(__('epi.stock.negative_help'))->persistent()->send();
                }

                if (($data['sign_mode'] ?? 'now') === 'later' && $owner->hasLogin()) {
                    $this->epi()->requestSignature($delivery, auth()->user());
                    $this->signLaterNotice($delivery);

                    return;
                }

                Notification::make()->success()->title(__('epi.saved'))->send();
                $this->replaceMountedAction('signDelivery', ['delivery' => $delivery->getKey()]);
            });
    }

    /** "Sent to Ana's bell", with the WhatsApp button when the person has a mobile. */
    protected function signLaterNotice(EpiDelivery $delivery): void
    {
        $person = $delivery->person;
        $notification = Notification::make()->success()
            ->title(__('epi.sign.later_sent', ['name' => $person->name]))
            ->body(__('epi.sign.later_help', ['name' => $person->name]));

        if (filled($person->phone)) {
            $notification->actions([
                Action::make('whatsapp')
                    ->label(__('epi.actions.whatsapp'))
                    ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                    ->url($this->whatsappUrl($delivery), shouldOpenInNewTab: true),
            ]);
        }

        $notification->persistent()->send();
    }

    /** The message to the person's WhatsApp, with the link to "My deliveries". */
    protected function whatsappUrl(EpiDelivery $delivery): string
    {
        return PeopleService::whatsappUrl($delivery->person->phone, __('epi.sign.whatsapp_message', [
            'name' => Str::before($delivery->person->name, ' '),
            'company' => $this->tenant()->name,
            'link' => MyEpiPage::getUrl(),
        ]));
    }

    // ---------------------------------------------------------------- signature

    /** Who may collect this signature: the person themselves, or who handles every kind in the delivery. */
    protected function canSign(EpiDelivery $delivery): bool
    {
        if ($delivery->isSigned() || ! $delivery->person?->is_active) {
            return false;
        }

        $user = auth()->user();
        $own = $user !== null && $delivery->person->user_id === $user->getKey();

        return $own || ($this->epi()->canSeeDelivery($user, $delivery) && $this->tenantCanWrite());
    }

    /**
     * The delivery, the acknowledgment text, the name and the pad.
     *
     * @return array<int, mixed>
     */
    protected function signFields(EpiDelivery $delivery): array
    {
        return [
            View::make('filament.epi.sign-summary')->viewData([
                'delivery' => $delivery->load('lines'),
                'terms' => $this->epi()->terms(),
            ]),
            TextInput::make('signer_name')
                ->label(__('epi.fields.signer_name'))
                ->required()
                ->maxLength(120)
                ->validationMessages(['required' => __('epi.validation.signer')]),
            ViewField::make('signature')
                ->label(__('epi.fields.signature'))
                ->view('filament.forms.signature-pad')
                ->required()
                ->validationMessages(['required' => __('epi.validation.signature')]),
        ];
    }

    protected function signDeliveryAction(): Action
    {
        return $this->configureSign(Action::make('signDelivery'));
    }

    protected function configureSign(Action $action): Action
    {
        $find = fn (array $arguments, mixed $record = null): ?EpiDelivery => $record instanceof EpiDeliveryLine
            ? $record->delivery
            : EpiDelivery::query()->find($arguments['delivery'] ?? null);

        return $action
            ->label(__('epi.actions.sign'))
            ->icon(Heroicon::OutlinedPencilSquare)
            ->color('primary')
            ->visible(fn (array $arguments, mixed $record = null): bool => ($delivery = $find($arguments, $record)) !== null && ! $delivery->isSigned())
            ->authorize(fn (array $arguments, mixed $record = null): bool => ($delivery = $find($arguments, $record)) !== null && $this->canSign($delivery))
            ->modalHeading(fn (array $arguments, mixed $record = null): string => __('epi.sign.heading', ['name' => $find($arguments, $record)?->person?->name]))
            ->modalSubmitActionLabel(__('epi.sign.submit'))
            ->modalWidth('2xl')
            ->fillForm(fn (array $arguments, mixed $record = null): array => ['signer_name' => $find($arguments, $record)?->person?->name])
            ->schema(fn (array $arguments, mixed $record = null): array => ($delivery = $find($arguments, $record)) !== null ? $this->signFields($delivery) : [])
            ->action(function (array $data, array $arguments, Action $action, mixed $record = null) use ($find): void {
                $delivery = $find($arguments, $record);
                $user = auth()->user();

                try {
                    $this->epi()->sign($delivery, $data['signature'] ?? null, $data['signer_name'] ?? null, $user,
                        ownDevice: $delivery->person->user_id === $user?->getKey(),
                        ip: request()->ip(), userAgent: (string) request()->userAgent());
                } catch (EpiException $exception) {
                    Notification::make()->danger()->title($exception->getMessage())->send();
                    $action->halt();

                    return;
                }

                Notification::make()->success()->title(__('epi.sign.done'))->send();
            });
    }

    // ---------------------------------------------------------------- the list of items with people

    /**
     * @return array<int, TextColumn>
     */
    protected function lineColumns(bool $withPerson): array
    {
        return array_values(array_filter([
            $withPerson ? TextColumn::make('delivery.person.name')
                ->label(__('epi.fields.person'))
                ->weight('bold')
                ->description(fn (EpiDeliveryLine $record): ?string => $record->delivery->person->team?->name)
                ->searchable() : null,
            // "Luva de vaqueta × 2" (the quantity with the item: one column less on the computer).
            TextColumn::make('item_name')
                ->label(__('epi.fields.item'))
                ->state(fn (EpiDeliveryLine $record): string => $record->label().($record->quantity > 1 ? ' × '.$record->quantity : ''))
                ->weight($withPerson ? null : 'bold')
                ->description(fn (EpiDeliveryLine $record): ?string => collect([
                    $record->returned_quantity > 0 ? __('epi.lines.partly', ['open' => $record->openQuantity(), 'total' => $record->quantity]) : null,
                    $record->kind->getLabel(),
                    filled($record->ca_number) ? __('epi.fields.ca_short').' '.$record->ca_number : null,
                ])->filter()->implode(' · ') ?: null)
                ->searchable(),
            TextColumn::make('delivery.delivered_on')
                ->label(__('epi.fields.delivered_on'))
                ->date(__('app.date_format'))
                ->description(fn (EpiDeliveryLine $record): string => $record->delivery->reason->getLabel())
                ->sortable(),
            TextColumn::make('replace_on')
                ->label(__('epi.fields.replacement'))
                ->state(fn (EpiDeliveryLine $record): string => $record->statusText())
                ->description(fn (EpiDeliveryLine $record): ?string => $record->replace_on !== null && $record->openQuantity() > 0 ? $record->replace_on->translatedFormat(__('app.date_format')) : null)
                ->badge()
                ->color(fn (EpiDeliveryLine $record): string => $record->status()->getColor()),
            TextColumn::make('signature_state')
                ->label(__('epi.fields.signature'))
                ->state(fn (EpiDeliveryLine $record): string => $record->delivery->isSigned() ? __('epi.sign.signed') : __('epi.sign.pending'))
                ->badge()
                ->color(fn (EpiDeliveryLine $record): string => $record->delivery->isSigned() ? 'success' : 'warning'),
        ]));
    }

    /**
     * Sign, return, replace, see the delivery, cancel.
     *
     * @return array<int, Action|ActionGroup>
     */
    protected function lineRecordActions(bool $manage = true): array
    {
        $actions = [$this->configureSign(Action::make('sign'))->button()->size('sm')];

        if ($manage) {
            $actions[] = Action::make('return')
                ->label(__('epi.actions.return'))
                ->icon(Heroicon::OutlinedArrowUturnLeft)
                ->link()
                ->visible(fn (EpiDeliveryLine $record): bool => $record->openQuantity() > 0)
                ->authorize(fn (EpiDeliveryLine $record): bool => $this->canHandleLine($record))
                ->modalHeading(fn (EpiDeliveryLine $record): string => __('epi.returns.heading', ['item' => $record->label(), 'name' => $record->delivery->person->name]))
                ->modalDescription(__('epi.returns.help'))
                ->fillForm(fn (EpiDeliveryLine $record): array => [
                    'quantity' => $record->openQuantity(),
                    'returned_on' => EpiItem::today()->toDateString(),
                    'reason' => ReturnReason::Finished->value,
                    'outcome' => ReturnReason::Finished->defaultOutcome($record->item->reusable)->value,
                ])
                ->schema(fn (EpiDeliveryLine $record): array => $this->returnFields($record))
                ->action(function (EpiDeliveryLine $record, array $data, Action $action): void {
                    try {
                        $this->epi()->returnLine($record, $data, auth()->user());
                    } catch (EpiException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title(__('epi.returns.saved'))->send();
                });
        }

        $more = [
            Action::make('details')
                ->label(__('epi.actions.details'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->modalHeading(fn (EpiDeliveryLine $record): string => $record->delivery->title())
                ->modalContent(fn (EpiDeliveryLine $record) => view('filament.epi.delivery-details', $this->detailsData($record->delivery)))
                ->modalSubmitAction(false)
                ->modalCancelActionLabel(__('epi.actions.close')),
        ];

        if ($manage) {
            $more[] = Action::make('exchange')
                ->label(__('epi.actions.exchange'))
                ->icon(Heroicon::OutlinedArrowPath)
                ->visible(fn (EpiDeliveryLine $record): bool => $record->openQuantity() > 0)
                ->authorize(fn (EpiDeliveryLine $record): bool => $this->canHandleLine($record))
                ->modalHeading(fn (EpiDeliveryLine $record): string => __('epi.exchange.heading', ['item' => $record->label(), 'name' => $record->delivery->person->name]))
                ->modalDescription(__('epi.exchange.help'))
                ->modalWidth('2xl')
                ->fillForm(fn (EpiDeliveryLine $record): array => [
                    'item_id' => $record->item->is_active ? $record->item_id : null,
                    'quantity' => $record->openQuantity(),
                    'delivered_on' => EpiItem::today()->toDateString(),
                    'reason' => ReturnReason::Wear->value,
                    'outcome' => ReturnReason::Wear->defaultOutcome($record->item->reusable)->value,
                    'sign_mode' => 'now',
                ])
                ->schema(fn (EpiDeliveryLine $record): array => $this->exchangeFields($record))
                ->action(function (EpiDeliveryLine $record, array $data, Action $action): void {
                    try {
                        $delivery = $this->epi()->exchange($record, $data, auth()->user(), $this->kinds());
                    } catch (EpiException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    if (($data['sign_mode'] ?? 'now') === 'later' && $delivery->person->hasLogin()) {
                        $this->epi()->requestSignature($delivery, auth()->user());
                        $this->signLaterNotice($delivery);

                        return;
                    }

                    Notification::make()->success()->title(__('epi.exchange.saved'))->send();
                    $this->replaceMountedAction('signDelivery', ['delivery' => $delivery->getKey()]);
                });
            $more[] = Action::make('whatsapp')
                ->label(__('epi.actions.whatsapp'))
                ->icon(Heroicon::OutlinedChatBubbleLeftRight)
                ->visible(fn (EpiDeliveryLine $record): bool => ! $record->delivery->isSigned() && $record->delivery->person->hasLogin() && filled($record->delivery->person->phone))
                ->url(fn (EpiDeliveryLine $record): string => $this->whatsappUrl($record->delivery))
                ->openUrlInNewTab();
            $more[] = Action::make('cancel')
                ->label(__('epi.actions.cancel'))
                ->icon(Heroicon::OutlinedTrash)
                ->color('danger')
                ->visible(fn (EpiDeliveryLine $record): bool => ! $record->delivery->isSigned())
                ->authorize(fn (EpiDeliveryLine $record): bool => $this->canHandleLine($record))
                ->requiresConfirmation()
                ->modalHeading(__('epi.actions.cancel_heading'))
                ->modalDescription(__('epi.actions.cancel_help'))
                ->action(function (EpiDeliveryLine $record): void {
                    try {
                        $this->epi()->cancel($record->delivery);
                    } catch (EpiException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();

                        return;
                    }

                    Notification::make()->success()->title(__('epi.canceled'))->send();
                });
        }

        $actions[] = ActionGroup::make($more)
            ->icon(Heroicon::OutlinedEllipsisVertical)
            ->tooltip(__('app.more_actions'))
            ->iconButton();

        return $actions;
    }

    protected function canHandleLine(EpiDeliveryLine $line): bool
    {
        return in_array($line->kind->value, $this->kinds(), true) && $this->tenantCanWrite();
    }

    /**
     * @return array<int, mixed>
     */
    protected function returnFields(EpiDeliveryLine $line): array
    {
        $reusable = (bool) $line->item?->reusable;

        return [
            Grid::make(['default' => 1, 'sm' => 2])->components([
                TextInput::make('quantity')
                    ->label(__('epi.fields.quantity'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue($line->openQuantity())
                    ->required(),
                DatePicker::make('returned_on')
                    ->label(__('epi.fields.returned_on'))
                    ->minDate($line->delivery->delivered_on->toDateString())
                    ->maxDate(fn (): string => EpiItem::today()->toDateString())
                    ->required(),
                Select::make('reason')
                    ->label(__('epi.fields.return_reason'))
                    ->options(ReturnReason::options())
                    ->required()
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('outcome', ReturnReason::tryFrom((string) $state)?->defaultOutcome($reusable)->value)),
                Select::make('outcome')
                    ->label(__('epi.fields.outcome'))
                    ->options(ReturnOutcome::options())
                    ->helperText(fn (Get $get): ?string => $get('outcome') === ReturnOutcome::Stock->value && $line->item?->tracksStock() ? __('epi.returns.back_to_stock') : null)
                    ->live()
                    ->required()
                    ->selectablePlaceholder(false),
            ]),
            Textarea::make('notes')
                ->label(__('epi.fields.notes'))
                ->rows(2),
        ];
    }

    /**
     * @return array<int, mixed>
     */
    protected function exchangeFields(EpiDeliveryLine $line): array
    {
        $reusable = (bool) $line->item?->reusable;

        return [
            Grid::make(['default' => 1, 'sm' => 2])->components([
                Select::make('item_id')
                    ->label(__('epi.fields.new_item'))
                    ->helperText(__('epi.exchange.item_help'))
                    ->options(fn (): array => $this->epi()->itemOptions($this->kinds()))
                    ->searchable()
                    ->required()
                    ->columnSpanFull(),
                TextInput::make('quantity')
                    ->label(__('epi.fields.quantity'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue($line->openQuantity())
                    ->required(),
                DatePicker::make('delivered_on')
                    ->label(__('epi.fields.exchanged_on'))
                    ->minDate($line->delivery->delivered_on->toDateString())
                    ->maxDate(fn (): string => EpiItem::today()->toDateString())
                    ->required(),
                Select::make('reason')
                    ->label(__('epi.fields.exchange_reason'))
                    ->options(collect(ReturnReason::options())->except(ReturnReason::Finished->value)->all())
                    ->required()
                    ->selectablePlaceholder(false)
                    ->live()
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('outcome', ReturnReason::tryFrom((string) $state)?->defaultOutcome($reusable)->value)),
                Select::make('outcome')
                    ->label(__('epi.fields.old_outcome'))
                    ->options(ReturnOutcome::options())
                    ->required()
                    ->selectablePlaceholder(false),
            ]),
            Textarea::make('notes')
                ->label(__('epi.fields.notes'))
                ->rows(2),
            Radio::make('sign_mode')
                ->label(__('epi.fields.sign_mode'))
                ->options([
                    'now' => __('epi.sign.mode_now'),
                    'later' => $line->delivery->person->hasLogin() ? __('epi.sign.mode_later') : __('epi.sign.mode_later_no_login'),
                ])
                ->disableOptionWhen(fn (string $value): bool => $value === 'later' && ! $line->delivery->person->hasLogin())
                ->required(),
        ];
    }

    /**
     * What the details of a delivery show (items, returns, the signature and its record).
     *
     * @return array<string, mixed>
     */
    protected function detailsData(EpiDelivery $delivery): array
    {
        $delivery->loadMissing(['lines.returns', 'person', 'creator']);

        return [
            'delivery' => $delivery,
            'signature' => $this->epi()->signatureDataUrl($delivery),
            'code' => $this->epi()->code($delivery),
            'intact' => $delivery->isSigned() ? $this->epi()->verify($delivery) : null,
            'timezone' => $this->epi()->timezone(),
        ];
    }

    // ---------------------------------------------------------------- record (PDF)

    /** "Record in PDF" (NR-6 format) of a person: who handles the PPE, or the person themselves. */
    protected function recordAction(Person $person): Action
    {
        return Action::make('record')
            ->label(__('epi.actions.record'))
            ->icon(Heroicon::OutlinedPrinter)
            ->color('gray')
            ->action(fn (): StreamedResponse => response()->streamDownload(
                fn () => print (app(EpiRecord::class)->pdf($person)),
                app(EpiRecord::class)->fileName($person),
                ['Content-Type' => 'application/pdf'],
            ));
    }

    /**
     * @return array<string, string>
     */
    protected function kindOptions(): array
    {
        return EpiKind::options($this->kinds());
    }
}
