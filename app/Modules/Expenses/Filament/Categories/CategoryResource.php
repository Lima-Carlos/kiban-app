<?php

namespace App\Modules\Expenses\Filament\Categories;

use App\Filament\Support\ModuleResource;
use App\Modules\Expenses\ExpensesModule;
use App\Modules\Expenses\Models\Category;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Fleet\Enums\ExpenseCategory;
use App\Modules\Fleet\FleetModule;
use App\Support\Money;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** The kinds of expense, with the limit per claim and whether the receipt is required (manager). */
class CategoryResource extends ModuleResource
{
    protected static string $moduleKey = ExpensesModule::KEY;

    protected static ?string $model = Category::class;

    protected static ?string $langGroup = 'expenses.categories';

    protected static ?string $slug = 'despesas/categorias';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 30;

    public static function getModelLabel(): string
    {
        return __('expenses.categories.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('expenses.categories.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(['default' => 1, 'sm' => 2])
            ->components([
                TextInput::make('name')->label(__('expenses.fields.name'))->required()->maxLength(80)->columnSpanFull(),
                TextInput::make('limit')->label(__('expenses.fields.limit'))->helperText(__('expenses.helpers.limit'))
                    ->numeric()->minValue(0)->step(0.01)->prefix(fn (): string => Money::symbol())
                    ->afterStateHydrated(fn (TextInput $component, ?Category $record) => $component->state($record?->limit_cents !== null ? $record->limit_cents / 100 : null))
                    ->dehydrated(false),
                TextInput::make('per_km')->label(__('expenses.fields.per_km'))->helperText(__('expenses.helpers.per_km'))
                    ->numeric()->minValue(0)->step(0.01)->prefix(fn (): string => Money::symbol())
                    ->visible(fn (?Category $record): bool => $record?->isMileage() ?? false)
                    ->afterStateHydrated(fn (TextInput $component, ?Category $record) => $component->state($record?->per_km_cents !== null ? $record->per_km_cents / 100 : null))
                    ->dehydrated(false),
                Toggle::make('receipt_required')->label(__('expenses.fields.receipt_required'))->default(true)->inline(false),
                Select::make('fleet_category')->label(__('expenses.fields.fleet_category'))->helperText(__('expenses.helpers.fleet_category'))
                    ->options(collect(ExpenseCategory::cases())->mapWithKeys(fn (ExpenseCategory $category): array => [$category->value => $category->getLabel()])->all())
                    ->visible(fn (): bool => app(TenantContext::class)->get()->license()->hasModule(FleetModule::KEY)),
                Toggle::make('is_active')->label(__('expenses.fields.active'))->default(true)->inline(false),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    public static function withLimit(array $data, array $state): array
    {
        $limit = $state['limit'] ?? null;
        $data['limit_cents'] = filled($limit) ? (int) round((float) $limit * 100) : null;

        if (array_key_exists('per_km', $state)) {
            $data['per_km_cents'] = filled($state['per_km']) ? (int) round((float) $state['per_km'] * 100) : null;
        }

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort')
            ->columns([
                TextColumn::make('name')->label(__('expenses.fields.name'))->weight('bold'),
                TextColumn::make('limit_cents')->label(__('expenses.fields.limit'))->formatStateUsing(fn (?int $state): string => $state === null ? '—' : app(ExpenseService::class)->money($state))->placeholder('—')
                    ->description(fn (Category $record): ?string => $record->isMileage() ? ($record->per_km_cents ? __('expenses.categories.per_km', ['value' => app(ExpenseService::class)->money($record->per_km_cents)]) : __('expenses.categories.no_rate')) : null),
                IconColumn::make('receipt_required')->label(__('expenses.fields.receipt_required'))->boolean(),
                IconColumn::make('is_active')->label(__('expenses.fields.active'))->boolean(),
            ])
            ->recordActions([
                EditAction::make()->mutateDataUsing(fn (array $data, $livewire): array => static::withLimit($data, (array) ($livewire->mountedActions[0]['data'] ?? []))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageCategories::route('/')];
    }
}
