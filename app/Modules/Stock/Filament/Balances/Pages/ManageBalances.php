<?php

namespace App\Modules\Stock\Filament\Balances\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Stock\Filament\Balances\BalanceResource;
use App\Modules\Stock\Filament\StockForms;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Services\StockService;
use Filament\Actions\Action;
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
                ->schema([StockForms::item(), StockForms::location(), StockForms::quantity(), TextInput::make('note')->label(__('stock.fields.note'))->placeholder(__('stock.helpers.entry_note'))->maxLength(255)])
                ->action(fn (array $data, Action $action) => StockForms::run(
                    fn () => app(StockService::class)->receive(Item::query()->findOrFail($data['item_id']), Location::query()->findOrFail($data['location_id']), (float) $data['quantity'], $data['note'] ?? null),
                    __('stock.actions.entry_done'), $action)),
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
}
