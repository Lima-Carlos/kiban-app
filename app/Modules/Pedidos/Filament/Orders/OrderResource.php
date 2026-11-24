<?php

namespace App\Modules\Pedidos\Filament\Orders;

use App\Filament\Support\ModuleResource;
use App\Modules\Pedidos\Enums\Fulfillment;
use App\Modules\Pedidos\Enums\OrderSource;
use App\Modules\Pedidos\Enums\OrderStatus;
use App\Modules\Pedidos\Models\Order;
use App\Modules\Pedidos\Models\OrderLine;
use App\Modules\Pedidos\Models\PedidosSetting;
use App\Modules\Pedidos\PedidosModule;
use App\Modules\Pedidos\Services\PedidosFormat;
use App\Support\Country;
use App\Support\Money;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * All the orders (history): number, time, customer, what was ordered, how it is delivered, where it came from,
 * total, status, who prepared and who delivered. New orders are taken on the quick order screen; orders are never
 * deleted (a wrong one is cancelled with the reason).
 */
class OrderResource extends ModuleResource
{
    protected static string $moduleKey = PedidosModule::KEY;

    protected static ?string $model = Order::class;

    protected static ?string $langGroup = 'pedidos.orders';

    protected static ?string $slug = 'pedidos/pedidos';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    protected static ?int $navigationSort = 25;

    public static function getModelLabel(): string
    {
        return __('pedidos.orders.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('pedidos.orders.plural');
    }

    public static function getNavigationLabel(): string
    {
        return __('pedidos.orders.page_title');
    }

    public static function getTitleCasePluralModelLabel(): string
    {
        return __('pedidos.orders.page_title');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['client', 'lines', 'preparer', 'deliverer']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('pedidos.fields.number'))->prefix('#')->weight('bold')
                    ->description(fn (Order $record): string => PedidosFormat::moment($record->created_at))->searchable(),
                TextColumn::make('customer')->label(__('pedidos.fields.customer'))
                    ->state(fn (Order $record): string => $record->customerName())
                    ->description(fn (Order $record): ?string => Country::formatPhone($record->client?->phone))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas('client', fn (Builder $query) => $query->where('name', 'ilike', "%{$search}%"))),
                TextColumn::make('summary')->label(__('pedidos.fields.items'))->state(fn (Order $record): string => $record->summary())->wrap()->visibleFrom('md'),
                TextColumn::make('fulfillment')->label(__('pedidos.fields.fulfillment'))->badge()->color('gray')
                    ->description(fn (Order $record): string => $record->source->getLabel())->visibleFrom('md'),
                TextColumn::make('total_cents')->label(__('pedidos.fields.total'))->formatStateUsing(fn (int $state): string => Money::format($state)),
                TextColumn::make('status')->label(__('pedidos.fields.status'))->badge()
                    ->description(fn (Order $record): ?string => $record->delivered_at ? PedidosFormat::minutes($record->minutes()) : null),
                TextColumn::make('people')->label(__('pedidos.fields.team'))
                    ->state(fn (Order $record): string => collect([$record->preparer?->name, $record->deliverer?->name])->filter()->implode(' · '))
                    ->placeholder('—')->visibleFrom('lg'),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('pedidos.fields.status'))->options(OrderStatus::options()),
                SelectFilter::make('source')->label(__('pedidos.fields.source'))->options(OrderSource::options()),
                SelectFilter::make('fulfillment')->label(__('pedidos.fields.fulfillment'))->options(Fulfillment::options()),
                Filter::make('today')->label(__('pedidos.orders.today'))
                    ->query(fn (Builder $query): Builder => $query->where('created_at', '>=', CarbonImmutable::now(PedidosSetting::timezone())->startOfDay()->utc())),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('ticket')->label(__('pedidos.ticket.button'))->icon(Heroicon::OutlinedPrinter)->color('gray')->link()
                    ->url(fn (Order $record): string => route('pedidos.ticket', ['order' => $record->getKey()]), shouldOpenInNewTab: true),
            ])
            ->emptyStateHeading(__('pedidos.orders.empty'))
            ->emptyStateDescription(__('pedidos.orders.empty_help'));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                Section::make(__('pedidos.orders.sections.order'))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->components([
                        TextEntry::make('status')->label(__('pedidos.fields.status'))->badge(),
                        TextEntry::make('customer')->label(__('pedidos.fields.customer'))->state(fn (Order $record): string => $record->customerName()),
                        TextEntry::make('phone')->label(__('pedidos.fields.phone'))->state(fn (Order $record): ?string => Country::formatPhone($record->client?->phone))->placeholder('—'),
                        TextEntry::make('fulfillment')->label(__('pedidos.fields.fulfillment')),
                        TextEntry::make('address')->label(__('pedidos.fields.address'))->placeholder('—'),
                        TextEntry::make('source')->label(__('pedidos.fields.source')),
                        TextEntry::make('payment_method')->label(__('pedidos.fields.payment_method'))->placeholder('—')
                            ->helperText(fn (Order $record): ?string => $record->change_for_cents ? __('pedidos.orders.change_for', ['value' => Money::format($record->change_for_cents)]) : null),
                        TextEntry::make('total_cents')->label(__('pedidos.fields.total'))->formatStateUsing(fn (int $state): string => Money::format($state))
                            ->helperText(fn (Order $record): ?string => $record->delivery_fee_cents > 0 ? __('pedidos.orders.with_fee', ['value' => Money::format($record->delivery_fee_cents)]) : null),
                        TextEntry::make('notes')->label(__('pedidos.fields.notes'))->placeholder('—'),
                    ]),
                Section::make(__('pedidos.fields.items'))
                    ->components([
                        TextEntry::make('lines_list')->hiddenLabel()->listWithLineBreaks()
                            ->state(fn (Order $record): array => $record->lines->map(fn (OrderLine $line): string => $line->label().' · '.Money::format($line->total_cents))->all()),
                    ]),
                Section::make(__('pedidos.orders.sections.way'))
                    ->columns(['default' => 1, 'sm' => 3])
                    ->components([
                        TextEntry::make('created_at')->label(__('pedidos.fields.ordered_at'))->state(fn (Order $record): string => PedidosFormat::moment($record->created_at)),
                        TextEntry::make('preparer.name')->label(__('pedidos.fields.prepared_by'))->placeholder('—'),
                        TextEntry::make('deliverer.name')->label(__('pedidos.fields.delivered_by'))->placeholder('—'),
                        TextEntry::make('ready_at')->label(__('pedidos.fields.ready_at'))->state(fn (Order $record): ?string => $record->ready_at ? PedidosFormat::time($record->ready_at) : null)->placeholder('—'),
                        TextEntry::make('delivered_at')->label(__('pedidos.fields.delivered_at'))->state(fn (Order $record): ?string => $record->delivered_at ? PedidosFormat::time($record->delivered_at) : null)->placeholder('—'),
                        TextEntry::make('minutes')->label(__('pedidos.fields.took'))->state(fn (Order $record): string => PedidosFormat::minutes($record->minutes())),
                        TextEntry::make('cancel_reason')->label(__('pedidos.fields.cancel_reason'))->visible(fn (Order $record): bool => $record->status === OrderStatus::Cancelled)->columnSpanFull(),
                    ]),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
