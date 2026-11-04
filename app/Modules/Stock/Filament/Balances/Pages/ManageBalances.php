<?php

namespace App\Modules\Stock\Filament\Balances\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Stock\Filament\Balances\BalanceResource;
use App\Modules\Stock\Filament\StockForms;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Services\StockService;
use App\Modules\Stock\Services\InvoiceReader;
use App\Modules\Stock\Services\StockException;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Grid;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;

class ManageBalances extends ModuleRecordsPage
{
    protected static string $resource = BalanceResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn (): bool => auth()->user()?->can('update', new Item) ?? false;

        return [
            Action::make('entry')
                ->label(__('stock.actions.entry'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->visible($can)
                ->schema([StockForms::item(), StockForms::location(), StockForms::quantity(), TextInput::make('unit_cost')->label(__('stock.fields.unit_cost'))->helperText(__('stock.helpers.unit_cost'))->numeric()->minValue(0)->step(0.01)->prefix(fn (): string => \App\Support\Country::currency()), TextInput::make('note')->label(__('stock.fields.note'))->placeholder(__('stock.helpers.entry_note'))->maxLength(255)])
                ->action(fn (array $data, Action $action) => StockForms::run(
                    fn () => app(StockService::class)->receive(Item::query()->findOrFail($data['item_id']), Location::query()->findOrFail($data['location_id']), (float) $data['quantity'], $data['note'] ?? null, null, filled($data['unit_cost'] ?? null) ? (int) round((float) $data['unit_cost'] * 100) : null),
                    __('stock.actions.entry_done'), $action)),
            // NF-e: send the XML the supplier emailed, check each line (item suggested or a new one) and it all goes in.
            Action::make('invoice')
                ->label(__('stock.invoice.action'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->visible($can)
                ->modalDescription(__('stock.invoice.help'))
                ->modalSubmitActionLabel(__('stock.invoice.read'))
                ->schema([
                    FileUpload::make('xml')->label(__('stock.invoice.file'))->acceptedFileTypes(['text/xml', 'application/xml'])->maxSize(2048)->storeFiles(false)->required(),
                    StockForms::location(),
                ])
                ->action(function (array $data, Action $action): void {
                    $file = $data['xml'];
                    $xml = $file instanceof TemporaryUploadedFile ? (string) file_get_contents($file->getRealPath()) : (string) Storage::get((string) $file);

                    try {
                        $invoice = app(InvoiceReader::class)->read($xml);
                    } catch (StockException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    if ($invoice['already']) {
                        Notification::make()->danger()->title(__('stock.invoice.errors.already'))->send();
                        $action->halt();

                        return;
                    }

                    $this->replaceMountedAction('invoiceLines', ['invoice' => $invoice, 'location_id' => (int) $data['location_id']]);
                }),
            Action::make('transfer')
                ->label(__('stock.actions.transfer'))
                ->icon(Heroicon::OutlinedArrowsRightLeft)
                ->color('gray')
                ->visible($can)
                ->schema([StockForms::item(), StockForms::location('from_id', __('stock.fields.from')), StockForms::location('to_id', __('stock.fields.to')), StockForms::quantity()])
                ->action(fn (array $data, Action $action) => StockForms::run(
                    fn () => app(StockService::class)->transfer(Item::query()->findOrFail($data['item_id']), Location::query()->findOrFail($data['from_id']), Location::query()->findOrFail($data['to_id']), (float) $data['quantity']),
                    __('stock.actions.transfer_done'), $action)),
            Action::make('adjust')
                ->label(__('stock.actions.adjust'))
                ->icon(Heroicon::OutlinedClipboardDocumentCheck)
                ->color('gray')
                ->visible($can)
                ->modalDescription(__('stock.actions.adjust_help'))
                ->schema([StockForms::item(), StockForms::location(), StockForms::quantity('counted', __('stock.fields.counted')), TextInput::make('note')->label(__('stock.fields.note'))->maxLength(255)])
                ->action(fn (array $data, Action $action) => StockForms::run(
                    fn () => app(StockService::class)->adjust(Item::query()->findOrFail($data['item_id']), Location::query()->findOrFail($data['location_id']), (float) $data['counted'], $data['note'] ?? null),
                    __('stock.actions.adjust_done'), $action)),
        ];
    }

    /** Second step of the invoice: the lines to check. */
    public function invoiceLinesAction(): Action
    {
        $itemOptions = fn (): array => ['new' => __('stock.invoice.new_item'), 'skip' => __('stock.invoice.skip')]
            + Item::query()->active()->where('serialized', false)->orderBy('name')->pluck('name', 'id')->all();

        return Action::make('invoiceLines')
            ->modalHeading(fn (array $arguments): string => __('stock.invoice.check', ['number' => $arguments['invoice']['number'] ?? '', 'supplier' => $arguments['invoice']['supplier'] ?? '']))
            ->modalDescription(__('stock.invoice.check_help'))
            ->modalWidth('4xl')
            ->modalSubmitActionLabel(__('stock.invoice.confirm'))
            ->fillForm(fn (array $arguments): array => ['lines' => collect($arguments['invoice']['lines'] ?? [])->map(fn (array $line): array => array_merge($line, ['item_id' => $line['item_id'] ?? 'new']))->all()])
            ->schema([
                Repeater::make('lines')
                    ->hiddenLabel()
                    ->addable(false)
                    ->deletable(false)
                    ->reorderable(false)
                    ->itemLabel(fn (array $state): string => trim(($state['code'] ?? '').' · '.($state['description'] ?? ''), ' ·'))
                    ->schema([
                        Hidden::make('code'),
                        Hidden::make('description'),
                        Hidden::make('unit'),
                        Hidden::make('unit_cost'),
                        Grid::make(['default' => 1, 'sm' => 3])->components([
                            Select::make('item_id')->label(__('stock.invoice.item'))->options($itemOptions)->searchable()->required()->columnSpan(['sm' => 2]),
                            StockForms::quantity()->suffix(fn ($get): string => (string) $get('unit')),
                        ]),
                    ]),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $invoice = $arguments['invoice'] ?? [];
                $lines = collect($data['lines'] ?? [])->values()->map(fn (array $line, int $index): array => $line + ['code' => $invoice['lines'][$index]['code'] ?? '', 'description' => $invoice['lines'][$index]['description'] ?? '', 'unit' => $invoice['lines'][$index]['unit'] ?? 'un', 'unit_cost' => $invoice['lines'][$index]['unit_cost'] ?? null])->all();

                StockForms::run(function () use ($invoice, $lines, $arguments): void {
                    $count = app(StockService::class)->receiveInvoice($invoice, $lines, Location::query()->findOrFail($arguments['location_id']));
                    Notification::make()->success()->title(trans_choice('stock.invoice.done', $count, ['count' => $count]))->send();
                }, __('stock.actions.entry_done'), $action);
            });
    }
}
