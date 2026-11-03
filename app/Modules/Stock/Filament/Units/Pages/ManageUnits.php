<?php

namespace App\Modules\Stock\Filament\Units\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Stock\Filament\StockForms;
use App\Modules\Stock\Filament\Units\UnitResource;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Services\StockException;
use App\Modules\Stock\Services\StockService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

class ManageUnits extends ModuleRecordsPage
{
    protected static string $resource = UnitResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Many at once: one serial number per line (or read by a barcode reader, which types and presses Enter).
            Action::make('addUnits')
                ->label(__('stock.units.add'))
                ->icon(Heroicon::OutlinedQrCode)
                ->visible(fn (): bool => auth()->user()?->can('update', new Item) ?? false)
                ->schema([
                    StockForms::item('item_id', true)->helperText(__('stock.units.add_item_help')),
                    StockForms::location(),
                    Textarea::make('serials')->label(__('stock.units.serials'))->helperText(__('stock.units.serials_help'))->rows(6)->required(),
                ])
                ->action(function (array $data, Action $action): void {
                    try {
                        $units = app(StockService::class)->addUnits(Item::query()->findOrFail($data['item_id']), Location::query()->findOrFail($data['location_id']), preg_split('/[\r\n,;]+/', (string) $data['serials']) ?: []);
                    } catch (StockException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $action->halt();

                        return;
                    }

                    Notification::make()->success()->title(trans_choice('stock.units.added', $units->count(), ['count' => $units->count()]))->send();
                }),
        ];
    }
}
