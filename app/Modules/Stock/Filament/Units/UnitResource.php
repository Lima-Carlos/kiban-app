<?php

namespace App\Modules\Stock\Filament\Units;

use App\Filament\Support\ModuleResource;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\Customer;
use App\Modules\Stock\Filament\StockForms;
use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Models\Unit;
use App\Modules\Stock\Services\StockService;
use App\Modules\Stock\StockModule;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Equipment tracked by serial number (comodato): where each unit is (a place or a customer), since when, and
 * the actions to send it to a technician, lend it to a customer, get it back, or write it off.
 */
class UnitResource extends ModuleResource
{
    protected static string $moduleKey = StockModule::KEY;

    protected static ?string $model = Unit::class;

    protected static ?string $langGroup = 'stock.units';

    protected static ?string $slug = 'estoque/comodato';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQrCode;

    protected static ?int $navigationSort = 20;

    public static function getModelLabel(): string
    {
        return __('stock.units.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('stock.units.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['item', 'location', 'customer']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $can = fn (): bool => auth()->user()?->can('update', new Item) ?? false;
        $hasField = fn (): bool => app(TenantContext::class)->get()->license()->hasModule(FieldModule::KEY);

        return $table
            ->defaultSort('since_at', 'desc')
            ->columns([
                TextColumn::make('serial')->label(__('stock.fields.serial'))->weight('bold')->searchable()->copyable()
                    ->description(fn (Unit $record): string => $record->item->name),
                TextColumn::make('status')->label(__('stock.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('stock.units.statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) { Unit::IN_STOCK => 'success', Unit::WITH_CUSTOMER => 'info', default => 'danger' }),
                TextColumn::make('whereabouts')->label(__('stock.fields.where'))->state(fn (Unit $record): string => $record->whereabouts())->searchable(query: fn (Builder $query, string $search) => $query->where('customer_name', 'ilike', "%{$search}%")),
                TextColumn::make('since_at')->label(__('stock.fields.since'))->date(__('app.date_format'))->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('stock.fields.status'))->options(collect(Unit::STATUSES)->mapWithKeys(fn (string $status): array => [$status => __('stock.units.statuses.'.$status)])->all()),
                SelectFilter::make('item_id')->label(__('stock.fields.item'))->options(fn (): array => Item::options(true)),
                SelectFilter::make('location_id')->label(__('stock.fields.location'))->options(fn (): array => Location::options()),
            ])
            ->recordActions([
                ActionGroup::make([
                    Action::make('move')
                        ->label(__('stock.units.move'))
                        ->icon(Heroicon::OutlinedArrowsRightLeft)
                        ->visible(fn (Unit $record): bool => $can() && $record->status === Unit::IN_STOCK)
                        ->schema([StockForms::location('to_id', __('stock.fields.to'))])
                        ->action(fn (Unit $record, array $data, Action $action) => StockForms::run(fn () => app(StockService::class)->moveUnit($record, Location::query()->findOrFail($data['to_id'])), __('stock.units.moved'), $action)),
                    Action::make('lend')
                        ->label(__('stock.units.lend'))
                        ->icon(Heroicon::OutlinedHome)
                        ->visible(fn (Unit $record): bool => $can() && $record->status === Unit::IN_STOCK)
                        ->schema(fn (): array => $hasField()
                            ? [Select::make('customer_id')->label(__('stock.fields.customer'))->options(fn (): array => Customer::query()->where('is_active', true)->orderBy('name')->get()->mapWithKeys(fn (Customer $customer): array => [$customer->getKey() => $customer->displayName()])->all())->searchable()->required()]
                            : [TextInput::make('customer_name')->label(__('stock.fields.customer'))->required()->maxLength(160)])
                        ->action(fn (Unit $record, array $data, Action $action) => StockForms::run(fn () => app(StockService::class)->lend($record, isset($data['customer_id']) ? (int) $data['customer_id'] : null, $data['customer_name'] ?? null), __('stock.units.lent'), $action)),
                    Action::make('giveBack')
                        ->label(__('stock.units.give_back'))
                        ->icon(Heroicon::OutlinedArrowUturnLeft)
                        ->visible(fn (Unit $record): bool => $can() && $record->status !== Unit::IN_STOCK)
                        ->schema([StockForms::location('to_id', __('stock.fields.to'))])
                        ->action(fn (Unit $record, array $data, Action $action) => StockForms::run(fn () => app(StockService::class)->giveBack($record, Location::query()->findOrFail($data['to_id'])), __('stock.units.given_back'), $action)),
                    Action::make('defective')
                        ->label(__('stock.units.mark_defective'))
                        ->icon(Heroicon::OutlinedWrench)
                        ->color('danger')
                        ->visible(fn (Unit $record): bool => $can() && $record->status !== Unit::DEFECTIVE)
                        ->requiresConfirmation()
                        ->action(fn (Unit $record, Action $action) => StockForms::run(fn () => app(StockService::class)->writeOff($record, Unit::DEFECTIVE), __('stock.units.written_off'), $action)),
                    Action::make('lost')
                        ->label(__('stock.units.mark_lost'))
                        ->icon(Heroicon::OutlinedExclamationTriangle)
                        ->color('danger')
                        ->visible(fn (Unit $record): bool => $can() && $record->status !== Unit::LOST)
                        ->requiresConfirmation()
                        ->action(fn (Unit $record, Action $action) => StockForms::run(fn () => app(StockService::class)->writeOff($record, Unit::LOST), __('stock.units.written_off'), $action)),
                ]),
            ])
            ->emptyStateHeading(__('stock.units.empty'))
            ->emptyStateDescription(__('stock.units.empty_help'));
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageUnits::route('/')];
    }
}
