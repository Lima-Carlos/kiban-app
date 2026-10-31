<?php

namespace App\Modules\Fleet\Filament\Vehicles\RelationManagers;

use App\Modules\Fleet\Models\Expense;
use App\Modules\Fleet\Services\FleetFormat;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Other expenses of the vehicle (only for who sees costs: managers and viewers).
 */
class ExpensesRelationManager extends RelationManager
{
    protected static string $relationship = 'expenses';

    /** Loaded with the page: no empty white box while the tab loads. */
    protected static bool $isLazy = false;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('fleet.expenses.plural_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('viewAny', Expense::class) ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('spent_on', 'desc')
            ->columns([
                TextColumn::make('spent_on')->label(__('fleet.fields.date'))->date(__('app.date_format')),
                TextColumn::make('category')->label(__('fleet.fields.category'))->badge()->color('gray')
                    ->description(fn (Expense $record): ?string => $record->description),
                TextColumn::make('amount_cents')->label(__('fleet.fields.amount'))->formatStateUsing(fn (int $state): string => FleetFormat::money($state))->alignEnd(),
            ])
            ->emptyStateHeading(__('fleet.expenses.empty'));
    }
}
