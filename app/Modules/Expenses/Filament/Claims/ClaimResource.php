<?php

namespace App\Modules\Expenses\Filament\Claims;

use App\Filament\Support\ModuleResource;
use App\Modules\Expenses\ExpensesModule;
use App\Modules\Expenses\Models\Category;
use App\Modules\Expenses\Models\Claim;
use App\Modules\Expenses\Services\ExpenseAccess;
use App\Modules\Expenses\Services\ExpenseException;
use App\Modules\Expenses\Services\ExpenseService;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Vehicle;
use App\Support\Country;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;

/**
 * Expense claims: each person sees and claims their own (photo of the receipt on the phone); the manager sees
 * everyone's, approves or refuses with the reason. Over the category's limit is flagged, never blocked.
 */
class ClaimResource extends ModuleResource
{
    protected static string $moduleKey = ExpensesModule::KEY;

    protected static ?string $model = Claim::class;

    protected static ?string $langGroup = 'expenses.claims';

    protected static ?string $slug = 'despesas';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptPercent;

    protected static ?int $navigationSort = 10;

    public static function getModelLabel(): string
    {
        return __('expenses.claims.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('expenses.claims.plural');
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery()->with(['user', 'category', 'workOrder', 'vehicle']);

        return app(ExpenseAccess::class)->isManager() ? $query : $query->where('user_id', auth()->id());
    }

    /** The form of a new claim (also on the phone: the receipt can be a photo taken right there). */
    public static function form(Schema $schema): Schema
    {
        $license = fn () => app(TenantContext::class)->get()->license();

        return $schema
            ->columns(['default' => 1, 'sm' => 2])
            ->components([
                DatePicker::make('spent_on')->label(__('expenses.fields.spent_on'))->default(now()->toDateString())->maxDate(now())->required(),
                Select::make('category_id')->label(__('expenses.fields.category'))
                    ->options(fn (): array => Category::query()->active()->orderBy('sort')->get()->pluck('name', 'id')->all())
                    ->live()
                    ->required(),
                TextInput::make('amount')->label(__('expenses.fields.amount'))->numeric()->minValue(0.01)->step(0.01)->prefix(fn (): string => Country::currency())
                    ->required(fn (Get $get): bool => ! static::mileage($get('category_id')))
                    ->hidden(fn (Get $get): bool => static::mileage($get('category_id'))),
                TextInput::make('km')->label(__('expenses.fields.km'))->numeric()->minValue(0.1)->step(0.1)->suffix('km')
                    ->helperText(fn (Get $get): ?string => ($rate = Category::query()->find($get('category_id'))?->per_km_cents) ? __('expenses.helpers.km', ['value' => app(ExpenseService::class)->money($rate)]) : null)
                    ->required(fn (Get $get): bool => static::mileage($get('category_id')))
                    ->visible(fn (Get $get): bool => static::mileage($get('category_id'))),
                TextInput::make('description')->label(__('expenses.fields.description'))->placeholder(__('expenses.helpers.description'))->required()->maxLength(255),
                FileUpload::make('receipt_path')->label(__('expenses.fields.receipt'))->helperText(__('expenses.helpers.receipt'))
                    ->disk('local')->visibility('private')
                    ->directory(fn (): string => 'tenants/'.app(TenantContext::class)->get()->getKey().'/expenses')
                    ->acceptedFileTypes(['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/heic'])
                    ->automaticallyResizeImagesMode('contain')->automaticallyResizeImagesToWidth('1600')->automaticallyResizeImagesToHeight('1600')
                    ->maxSize(8192)
                    ->columnSpanFull(),
                Select::make('work_order_id')->label(__('expenses.fields.work_order'))->helperText(__('expenses.helpers.work_order'))
                    ->options(fn (): array => WorkOrder::query()->where('technician_id', auth()->id())->orWhere('opened_by', auth()->id())->latest('id')->limit(30)->get()->mapWithKeys(fn (WorkOrder $order): array => [$order->getKey() => $order->label()])->all())
                    ->searchable()
                    ->visible(fn (): bool => $license()->hasModule(FieldModule::KEY)),
                Select::make('vehicle_id')->label(__('expenses.fields.vehicle'))->helperText(__('expenses.helpers.vehicle'))
                    ->options(fn (): array => Vehicle::query()->orderBy('plate')->get()->mapWithKeys(fn (Vehicle $vehicle): array => [$vehicle->getKey() => $vehicle->displayName()])->all())
                    ->searchable()
                    ->visible(fn (): bool => $license()->hasModule(FleetModule::KEY)),
            ]);
    }

    public static function mileage(mixed $category): bool
    {
        return filled($category) && (Category::query()->find($category)?->isMileage() ?? false);
    }

    public static function table(Table $table): Table
    {
        $manager = fn (): bool => app(ExpenseAccess::class)->isManager();
        $can = fn (): bool => auth()->user()?->can('decide', Claim::class) ?? false;

        return $table
            ->defaultSort('spent_on', 'desc')
            ->columns([
                TextColumn::make('spent_on')->label(__('expenses.fields.spent_on'))->date(__('app.date_format'))->sortable(),
                TextColumn::make('user.name')->label(__('expenses.fields.person'))->searchable()->visible($manager),
                TextColumn::make('description')->label(__('expenses.fields.description'))->weight('bold')->searchable()->limit(60)->wrap()
                    ->description(fn (Claim $record): string => collect([$record->category?->name, $record->workOrder?->label(), $record->vehicle?->displayName()])->filter()->implode(' · ')),
                TextColumn::make('amount_cents')->label(__('expenses.fields.amount'))
                    ->formatStateUsing(fn (int $state, Claim $record): string => app(ExpenseService::class)->money($state, $record->currency))
                    ->color(fn (Claim $record): ?string => $record->overLimit() ? 'warning' : null)
                    ->description(fn (Claim $record): ?string => $record->overLimit() ? __('expenses.claims.over_limit') : ($record->km ? number_format($record->km, 1, ',', '.').' km' : null)),
                TextColumn::make('status')->label(__('expenses.fields.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('expenses.statuses.'.$state))
                    ->color(fn (string $state): string => match ($state) { Claim::PENDING => 'warning', Claim::APPROVED => 'info', Claim::PAID => 'success', default => 'danger' })
                    ->description(fn (Claim $record): ?string => $record->decision_note),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('expenses.fields.status'))->options(collect(Claim::STATUSES)->mapWithKeys(fn (string $status): array => [$status => __('expenses.statuses.'.$status)])->all()),
                SelectFilter::make('category_id')->label(__('expenses.fields.category'))->options(fn (): array => Category::query()->orderBy('sort')->get()->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('receipt')
                    ->label(__('expenses.claims.see_receipt'))
                    ->icon(Heroicon::OutlinedPaperClip)
                    // Icon only (with the name on hover), so the row fits a 1280 px laptop next to Approve/Reject.
                    ->iconButton()
                    ->tooltip(__('expenses.claims.see_receipt'))
                    ->color('gray')
                    ->visible(fn (Claim $record): bool => filled($record->receipt_path))
                    ->url(fn (Claim $record): string => Storage::disk('local')->temporaryUrl($record->receipt_path, now()->addMinutes(10)))
                    ->openUrlInNewTab(),
                Action::make('approve')
                    ->label(__('expenses.claims.approve'))
                    ->icon(Heroicon::OutlinedCheck)
                    ->color('success')
                    ->visible(fn (Claim $record): bool => $record->status === Claim::PENDING && $can())
                    ->requiresConfirmation()
                    ->modalDescription(fn (Claim $record): string => __('expenses.claims.approve_help', ['amount' => app(ExpenseService::class)->money($record->amount_cents, $record->currency), 'name' => $record->user?->name]))
                    ->action(fn (Claim $record) => static::run(fn () => app(ExpenseService::class)->approve($record, auth()->user()), __('expenses.claims.approved'))),
                Action::make('reject')
                    ->label(__('expenses.claims.reject'))
                    ->icon(Heroicon::OutlinedXMark)
                    ->color('danger')
                    ->visible(fn (Claim $record): bool => $record->status === Claim::PENDING && $can())
                    ->schema([Textarea::make('note')->label(__('expenses.fields.reason'))->required()->maxLength(255)->rows(2)])
                    ->action(fn (Claim $record, array $data) => static::run(fn () => app(ExpenseService::class)->reject($record, auth()->user(), (string) $data['note']), __('expenses.claims.rejected'))),
                DeleteAction::make()->iconButton()->tooltip(__('filament-actions::delete.single.label')),
            ])
            ->emptyStateHeading(__('expenses.claims.empty'))
            ->emptyStateDescription(__('expenses.claims.empty_help'));
    }

    public static function run(\Closure $rule, string $done): void
    {
        try {
            $rule();
        } catch (ExpenseException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return;
        }

        Notification::make()->success()->title($done)->send();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ManageClaims::route('/')];
    }
}
