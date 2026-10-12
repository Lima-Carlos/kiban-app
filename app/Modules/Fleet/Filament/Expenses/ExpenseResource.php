<?php

namespace App\Modules\Fleet\Filament\Expenses;

use App\Filament\Support\ModuleResource;
use App\Modules\Fleet\Enums\ExpenseCategory;
use App\Modules\Fleet\Filament\FleetFields;
use App\Modules\Fleet\Filament\WorkForms;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Expense;
use App\Modules\Fleet\Services\FleetFormat;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * Other fleet expenses: vehicle tax, licensing, insurance, fines, tolls, parking... (managers only).
 */
class ExpenseResource extends ModuleResource
{
    protected static string $moduleKey = FleetModule::KEY;

    protected static ?string $model = Expense::class;

    protected static ?string $slug = 'frota/despesas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 7;

    public static function getModelLabel(): string
    {
        return __('fleet.expenses.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('fleet.expenses.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema
            ->columns(2)
            ->components([
                Select::make('category')->label(__('fleet.fields.category'))->options(ExpenseCategory::class)->required(),
                WorkForms::vehicleSelect()->required(false)->helperText(__('fleet.helpers.expense_vehicle')),
                TextInput::make('description')->label(__('fleet.fields.description'))->maxLength(255)->columnSpanFull(),
                WorkForms::money('amount')->label(__('fleet.fields.amount'))->required()->minValue(0.01),
                DatePicker::make('spent_on')->label(__('fleet.fields.date'))->default(now())->required(),
                FleetFields::file('receipt_path', 'expenses')->label(__('fleet.fields.receipt'))->columnSpanFull(),
            ]);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prepare(array $data): array
    {
        $data['amount_cents'] = WorkForms::cents($data['amount'] ?? 0);
        unset($data['amount']);

        return $data;
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('spent_on', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('vehicle'))
            ->columns([
                TextColumn::make('spent_on')->label(__('fleet.fields.date'))->date(__('app.date_format'))->sortable(),
                TextColumn::make('category')->label(__('fleet.fields.category'))->badge()->color('gray')
                    ->description(fn (Expense $record): ?string => $record->description),
                TextColumn::make('vehicle.plate')->label(__('fleet.fields.vehicle'))->placeholder(__('fleet.helpers.whole_fleet')),
                TextColumn::make('amount_cents')->label(__('fleet.fields.amount'))->formatStateUsing(fn (int $state): string => FleetFormat::money($state))->alignEnd(),
            ])
            ->filters([
                SelectFilter::make('category')->label(__('fleet.fields.category'))->options(ExpenseCategory::class),
                SelectFilter::make('vehicle_id')->label(__('fleet.fields.vehicle'))->relationship('vehicle', 'plate')->searchable(),
            ])
            ->recordActions([
                Action::make('receipt')
                    ->label(__('fleet.fields.receipt'))
                    ->icon(Heroicon::OutlinedPaperClip)
                    ->iconButton()
                    ->visible(fn (Expense $record): bool => filled($record->receipt_path))
                    ->url(fn (Expense $record): string => Storage::disk('local')->temporaryUrl($record->receipt_path, now()->addMinutes(10)))
                    ->openUrlInNewTab(),
                EditAction::make()
                    ->iconButton()
                    ->mutateRecordDataUsing(fn (array $data): array => $data + ['amount' => number_format(($data['amount_cents'] ?? 0) / 100, 2, '.', '')])
                    ->mutateDataUsing(fn (array $data): array => static::prepare($data)),
                DeleteAction::make()->iconButton(),
            ])
            ->emptyStateHeading(__('fleet.expenses.empty'));
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ManageExpenses::route('/'),
        ];
    }
}
