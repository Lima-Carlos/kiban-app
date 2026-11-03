<?php

namespace App\Modules\Stock\Filament\Movements;

use App\Filament\Support\ModuleResource;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Movement;
use App\Modules\Stock\Services\StockFormat;
use App\Modules\Stock\StockModule;
use BackedEnum;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every movement of the stock, newest first (never edited).
 */
class MovementResource extends ModuleResource
{
    protected static string $moduleKey = StockModule::KEY;

    protected static ?string $model = Movement::class;

    protected static ?string $langGroup = 'stock.movements';

    protected static ?string $slug = 'estoque/movimentacoes';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?int $navigationSort = 50;

    public static function getModelLabel(): string
    {
        return __('stock.movements.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('stock.movements.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['item', 'from', 'to', 'unit', 'workOrder', 'user']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('moved_at', 'desc')
            ->columns([
                TextColumn::make('moved_at')->label(__('stock.fields.date'))->dateTime(__('app.datetime_format'))->sortable(),
                TextColumn::make('kind')->label(__('stock.fields.kind'))->badge()->formatStateUsing(fn (string $state): string => __('stock.movements.kinds.'.$state)),
                TextColumn::make('item.name')->label(__('stock.fields.item'))->weight('bold')->searchable()
                    ->description(fn (Movement $record): ?string => $record->unit?->serial),
                TextColumn::make('quantity')->label(__('stock.fields.quantity'))->formatStateUsing(fn ($state, Movement $record): string => StockFormat::quantity((float) $state, $record->item->unit)),
                TextColumn::make('route')->label(__('stock.fields.route'))
                    ->state(fn (Movement $record): string => collect([$record->from?->name, $record->to?->name ?? $record->customer_name])->filter()->implode(' → ') ?: '—')
                    ->description(fn (Movement $record): ?string => $record->workOrder?->label() ?? $record->note),
                TextColumn::make('user.name')->label(__('stock.fields.user'))->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('kind')->label(__('stock.fields.kind'))->options(collect(Movement::KINDS)->mapWithKeys(fn (string $kind): array => [$kind => __('stock.movements.kinds.'.$kind)])->all()),
                SelectFilter::make('item_id')->label(__('stock.fields.item'))->options(fn (): array => Item::options())->searchable(),
            ])
            ->emptyStateHeading(__('stock.movements.empty'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListMovements::route('/')];
    }
}
