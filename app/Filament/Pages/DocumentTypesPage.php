<?php

namespace App\Filament\Pages;

use App\Modules\Field\FieldModule;
use App\Modules\Fleet\FleetModule;
use App\Modules\ModuleRegistry;
use App\People\Documents\DocumentType;
use App\People\Documents\PersonDocumentService;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The kinds of document the company follows (ready ones by country + its own): validity, warning days, health
 * data and the system each one matters to. A type is turned off, never deleted (documents point to it).
 */
class DocumentTypesPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $slug = 'empresa/tipos-de-documento';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return DocumentsPage::canAccess();
    }

    public function getTitle(): string
    {
        return __('documents.types.title');
    }

    public function getSubheading(): ?string
    {
        return __('documents.types.help');
    }

    public function mount(): void
    {
        app(PersonDocumentService::class)->types();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /**
     * Systems a type can matter to (only the ones that use documents and the company has).
     *
     * @return array<string, string>
     */
    protected static function moduleOptions(): array
    {
        $enabled = app(ModuleRegistry::class)->enabledFor(app(TenantContext::class)->get());

        return collect([FleetModule::KEY, FieldModule::KEY])
            ->filter(fn (string $key): bool => isset($enabled[$key]))
            ->mapWithKeys(fn (string $key): array => [$key => __('documents.types.module_'.$key)])
            ->all();
    }

    /**
     * @return array<int, mixed>
     */
    protected static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('documents.types.name'))
                ->required()
                ->maxLength(80),
            Grid::make(['default' => 1, 'sm' => 2])->components([
                TextInput::make('validity_months')
                    ->label(__('documents.types.validity'))
                    ->helperText(__('documents.types.validity_help'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(240)
                    ->suffix(__('documents.types.months')),
                TextInput::make('alert_days')
                    ->label(__('documents.types.alert_days'))
                    ->helperText(__('documents.types.alert_days_help'))
                    ->integer()
                    ->required()
                    ->default(30)
                    ->minValue(0)
                    ->maxValue(365)
                    ->suffix(__('documents.types.days')),
            ]),
            Select::make('module')
                ->label(__('documents.types.module'))
                ->helperText(__('documents.types.module_help'))
                ->options(fn (): array => static::moduleOptions())
                ->placeholder(__('documents.types.module_none')),
            Toggle::make('is_health')
                ->label(__('documents.types.is_health'))
                ->helperText(__('documents.types.is_health_help')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected static function values(array $data, ?DocumentType $type = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));

        return [
            // A ready type keeps its translated name while it is not renamed.
            'name' => $type?->key !== null && $name === __('documents.templates.'.$type->key) ? null : $name,
            'validity_months' => filled($data['validity_months'] ?? null) ? (int) $data['validity_months'] : null,
            'alert_days' => (int) ($data['alert_days'] ?? 30),
            'module' => filled($data['module'] ?? null) ? $data['module'] : null,
            'is_health' => (bool) ($data['is_health'] ?? false),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('documents.expiry.title'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => DocumentsPage::getUrl()),
            Action::make('create')
                ->label(__('documents.types.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize(fn (): bool => static::canAccess())
                ->schema(static::fields())
                ->action(function (array $data): void {
                    DocumentType::query()->create(static::values($data) + ['sort' => (int) DocumentType::query()->max('sort') + 10]);
                    Notification::make()->success()->title(__('documents.saved'))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => DocumentType::query()->withCount(['documents' => fn (Builder $query) => $query->whereNull('replaced_at')]))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('sort')->orderBy('id'))
            ->columns([
                TextColumn::make('label')
                    ->label(__('documents.types.name'))
                    ->state(fn (DocumentType $record): string => $record->label())
                    ->weight('bold')
                    ->description(fn (DocumentType $record): ?string => $record->module ? __('documents.types.module_'.$record->module) : null),
                TextColumn::make('validity_months')
                    ->label(__('documents.types.validity'))
                    ->formatStateUsing(fn (?int $state): string => trans_choice('documents.types.months_count', (int) $state, ['count' => $state]))
                    ->placeholder(__('documents.types.typed')),
                TextColumn::make('alert_days')
                    ->label(__('documents.types.alert_days'))
                    ->formatStateUsing(fn (int $state): string => trans_choice('documents.types.days_count', $state, ['count' => $state])),
                IconColumn::make('is_health')
                    ->label(__('documents.types.is_health_short'))
                    ->boolean()
                    ->trueIcon(Heroicon::OutlinedHeart)
                    ->trueColor('danger')
                    ->falseIcon(Heroicon::OutlinedMinus)
                    ->falseColor('gray'),
                TextColumn::make('documents_count')
                    ->label(__('documents.types.in_use'))
                    ->alignEnd()
                    ->visibleFrom('md'),
                ToggleColumn::make('is_active')
                    ->label(__('documents.types.active')),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('documents.actions.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->authorize(fn (): bool => static::canAccess())
                    ->fillForm(fn (DocumentType $record): array => [
                        'name' => $record->label(),
                        'validity_months' => $record->validity_months,
                        'alert_days' => $record->alert_days,
                        'module' => $record->module,
                        'is_health' => $record->is_health,
                    ])
                    ->schema(static::fields())
                    ->action(function (DocumentType $record, array $data): void {
                        $record->update(static::values($data, $record));
                        Notification::make()->success()->title(__('documents.saved'))->send();
                    }),
            ])
            ->paginated(false);
    }
}
