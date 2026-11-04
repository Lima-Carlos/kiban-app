<?php

namespace App\Modules\Stock\Filament\Balances;

use App\Filament\Support\ModuleResource;
use App\Modules\Stock\Models\Balance;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Services\StockFormat;
use App\Modules\Stock\StockModule;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The stock: how much of each item there is in each place. Entries, transfers and counts are made here.
 */
class BalanceResource extends ModuleResource
{
    protected static string $moduleKey = StockModule::KEY;

    protected static ?string $model = Balance::class;

    protected static ?string $langGroup = 'stock.balances';

    protected static ?string $slug = 'estoque/saldos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('stock.balances.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('stock.balances.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['item', 'location'])->where('quantity', '!=', 0);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('item_id')
            // Value kept per place (quantity x average cost), when the costs are known.
            ->description(function (): ?string {
                $values = app(\App\Modules\Stock\Services\StockService::class)->valueByLocation();

                return $values->isEmpty() ? null : __('stock.balances.value_title').': '.$values->map(fn (array $row): string => $row['location']?->name.' '.StockFormat::money($row['value']))->implode(' · ').' ('.__('stock.balances.value_help').')';
            })
            ->columns([
                TextColumn::make('item.name')->label(__('stock.fields.item'))->weight('bold')->searchable(),
                TextColumn::make('location.name')->label(__('stock.fields.location'))->searchable(),
                TextColumn::make('quantity')->label(__('stock.fields.quantity'))
                    ->formatStateUsing(fn ($state, Balance $record): string => StockFormat::quantity((float) $state, $record->item->unit))
                    ->color(fn ($state): ?string => (float) $state < 0 ? 'danger' : null),
                TextColumn::make('value')->label(__('stock.fields.value'))
                    ->state(fn (Balance $record): ?int => $record->item->avg_cost_cents === null ? null : (int) round(max(0, (float) $record->quantity) * $record->item->avg_cost_cents))
                    ->formatStateUsing(fn (?int $state): string => StockFormat::money($state))
                    ->placeholder('—')
                    ->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('location_id')->label(__('stock.fields.location'))->options(fn (): array => Location::options()),
                SelectFilter::make('item_id')->label(__('stock.fields.item'))->options(fn (): array => Item::options())->searchable(),
            ])
            ->emptyStateHeading(__('stock.balances.empty'))
            ->emptyStateDescription(__('stock.balances.empty_help'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageBalances::route('/')];
    }
}
