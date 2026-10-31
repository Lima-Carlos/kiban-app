<?php

namespace App\Filament\Pages;

use App\Filament\Support\ManagesEpi;
use App\People\Epi\EpiException;
use App\People\Epi\EpiItem;
use App\People\Epi\EpiKind;
use App\People\Epi\EpiService;
use App\Support\Country;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Items" of PPE and tools (the company's catalog): ready ones by country + its own. CA and its validity (PPE in
 * Brazil), useful life, size, code, cost, stock and the minimum. An item is turned off, never deleted.
 */
class EpiItemsPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable, ManagesEpi;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?string $slug = 'empresa/epi/itens';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return EpiPage::canAccess();
    }

    public function getTitle(): string
    {
        return __('epi.items.title');
    }

    public function getSubheading(): ?string
    {
        return __('epi.items.help');
    }

    public function mount(): void
    {
        app(EpiService::class)->items();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /**
     * @return array<int, mixed>
     */
    protected function fields(): array
    {
        $brazil = Country::isBrazil();
        $currency = Country::currency();

        return [
            Grid::make(['default' => 1, 'sm' => 2])->components([
                TextInput::make('name')
                    ->label(__('epi.fields.name'))
                    ->required()
                    ->maxLength(120)
                    ->validationMessages(['required' => __('epi.validation.name')])
                    ->columnSpanFull(),
                Select::make('kind')
                    ->label(__('epi.fields.kind'))
                    ->options(fn (): array => $this->kindOptions())
                    ->default(fn (): string => $this->kinds()[0] ?? EpiKind::Tool->value)
                    ->required()
                    ->live()
                    ->selectablePlaceholder(false),
                TextInput::make('size')
                    ->label(__('epi.fields.size'))
                    ->helperText(__('epi.items.size_help'))
                    ->maxLength(20),
                TextInput::make('code')
                    ->label(__('epi.fields.code'))
                    ->maxLength(40),
                TextInput::make('unit_cost')
                    ->label(__('epi.fields.unit_cost'))
                    ->numeric()
                    ->minValue(0)
                    ->prefix($currency),
            ]),
            Section::make(__($brazil ? 'epi.items.ca_section' : 'epi.items.certificate_section'))
                ->description(__($brazil ? 'epi.items.ca_help' : 'epi.items.certificate_help'))
                ->visible(fn (Get $get): bool => $get('kind') === EpiKind::Epi->value)
                ->compact()
                ->columns(['default' => 1, 'sm' => 2])
                ->components([
                    TextInput::make('ca_number')
                        ->label(__($brazil ? 'epi.fields.ca_number' : 'epi.fields.certificate'))
                        ->maxLength(40),
                    DatePicker::make('ca_expires_on')
                        ->label(__($brazil ? 'epi.fields.ca_expires_on' : 'epi.fields.certificate_expires_on')),
                ]),
            Grid::make(['default' => 2])->components([
                TextInput::make('life_amount')
                    ->label(__('epi.fields.life'))
                    ->helperText(__('epi.items.life_help'))
                    ->integer()
                    ->minValue(1)
                    ->maxValue(999),
                Select::make('life_unit')
                    ->label(__('epi.fields.life_unit'))
                    ->options(['days' => __('epi.life.unit_days'), 'months' => __('epi.life.unit_months')])
                    ->default('months')
                    ->selectablePlaceholder(false),
                TextInput::make('stock')
                    ->label(__('epi.fields.stock'))
                    ->helperText(__('epi.items.stock_help'))
                    ->integer()
                    ->minValue(-99999)
                    ->maxValue(99999),
                TextInput::make('min_stock')
                    ->label(__('epi.fields.min_stock'))
                    ->helperText(__('epi.items.min_stock_help'))
                    ->integer()
                    ->minValue(0)
                    ->maxValue(99999),
            ]),
            Toggle::make('reusable')
                ->label(__('epi.fields.reusable'))
                ->helperText(__('epi.items.reusable_help')),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function values(array $data, ?EpiItem $item = null): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        $kind = in_array($data['kind'] ?? null, $this->kinds(), true) ? $data['kind'] : ($item?->kind->value ?? $this->kinds()[0]);
        $epi = $kind === EpiKind::Epi->value;
        $number = fn (string $key): ?int => filled($data[$key] ?? null) ? (int) $data[$key] : null;

        return [
            // A ready item keeps its translated name while it is not renamed.
            'name' => $item?->key !== null && $name === __('epi.templates.'.$item->key) ? null : $name,
            'kind' => $kind,
            'size' => filled($data['size'] ?? null) ? trim((string) $data['size']) : null,
            'code' => filled($data['code'] ?? null) ? trim((string) $data['code']) : null,
            'ca_number' => $epi && filled($data['ca_number'] ?? null) ? trim((string) $data['ca_number']) : null,
            'ca_expires_on' => $epi && filled($data['ca_expires_on'] ?? null) ? $data['ca_expires_on'] : null,
            'life_amount' => $number('life_amount'),
            'life_unit' => in_array($data['life_unit'] ?? null, EpiItem::LIFE_UNITS, true) ? $data['life_unit'] : 'months',
            'unit_cost_cents' => filled($data['unit_cost'] ?? null) ? (int) round(((float) str_replace(',', '.', (string) $data['unit_cost'])) * 100) : null,
            'stock' => $number('stock'),
            'min_stock' => $number('min_stock'),
            'reusable' => (bool) ($data['reusable'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function formData(EpiItem $item): array
    {
        return [
            'name' => $item->label(),
            'kind' => $item->kind->value,
            'size' => $item->size,
            'code' => $item->code,
            'unit_cost' => $item->unit_cost_cents !== null ? number_format($item->unit_cost_cents / 100, 2, '.', '') : null,
            'ca_number' => $item->ca_number,
            'ca_expires_on' => $item->ca_expires_on?->toDateString(),
            'life_amount' => $item->life_amount,
            'life_unit' => $item->life_unit,
            'stock' => $item->stock,
            'min_stock' => $item->min_stock,
            'reusable' => $item->reusable,
        ];
    }

    protected function canEdit(): bool
    {
        return static::canAccess() && $this->tenantCanWrite();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('epi.title'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => EpiPage::getUrl()),
            Action::make('create')
                ->label(__('epi.items.new'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize(fn (): bool => $this->canEdit())
                ->modalHeading(__('epi.items.new'))
                ->modalWidth('2xl')
                ->schema(fn (): array => $this->fields())
                ->action(function (array $data): void {
                    $item = new EpiItem($this->values($data));
                    $item->sort = (int) EpiItem::query()->max('sort') + 10;
                    $item->save();
                    Notification::make()->success()->title(__('epi.items.saved'))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $kinds = $this->kinds();
        $service = $this->epi();

        return $table
            ->query(fn (): Builder => EpiItem::query()->ofKinds($kinds))
            ->defaultSort(fn (Builder $query): Builder => $query->orderBy('sort')->orderBy('id'))
            ->columns([
                TextColumn::make('label')
                    ->label(__('epi.fields.item'))
                    ->state(fn (EpiItem $record): string => $record->fullLabel())
                    ->weight('bold')
                    ->description(fn (EpiItem $record): ?string => collect([$record->kind->getLabel(), $record->code])->filter()->implode(' · '))
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->where(fn (Builder $query) => $query->whereLike('name', "%{$search}%")->orWhereLike('code', "%{$search}%")->orWhereLike('ca_number', "%{$search}%"))),
                TextColumn::make('ca_number')
                    ->label(__(Country::isBrazil() ? 'epi.fields.ca_short' : 'epi.fields.certificate'))
                    ->placeholder('—')
                    ->description(fn (EpiItem $record): ?string => $record->ca_expires_on !== null ? __('epi.items.valid_until', ['date' => $record->ca_expires_on->translatedFormat(__('app.date_format'))]) : null)
                    ->badge(fn (EpiItem $record): bool => $record->caWarning() !== null)
                    ->formatStateUsing(fn (EpiItem $record, ?string $state): string => $record->caWarning() !== null ? $state.' · '.$record->caWarning() : (string) $state)
                    ->color(fn (EpiItem $record): ?string => match (true) {
                        $record->caExpired() => 'danger',
                        $record->caWarning() !== null => 'warning',
                        default => null,
                    }),
                TextColumn::make('life_amount')
                    ->label(__('epi.fields.life'))
                    ->state(fn (EpiItem $record): string => $record->lifeText() ?? __('epi.life.none')),
                TextColumn::make('stock')
                    ->label(__('epi.fields.stock'))
                    ->state(fn (EpiItem $record): string => $record->tracksStock() ? (string) $record->stock : __('epi.stock.untracked'))
                    ->description(fn (EpiItem $record): ?string => $record->min_stock !== null ? __('epi.stock.min', ['count' => $record->min_stock]) : null)
                    ->badge()
                    ->color(fn (EpiItem $record): string => match (true) {
                        ! $record->tracksStock() => 'gray',
                        $record->stock < 0 || $record->isLowStock() => 'danger',
                        default => 'success',
                    }),
                ToggleColumn::make('is_active')
                    ->label(__('epi.fields.active'))
                    ->disabled(fn (): bool => ! $this->canEdit()),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label(__('epi.fields.kind'))
                    ->options(fn (): array => $this->kindOptions())
                    ->visible(fn (): bool => count($kinds) > 1),
                SelectFilter::make('attention')
                    ->label(__('epi.items.attention'))
                    ->options(['low' => __('epi.items.only_low'), 'ca' => __('epi.items.only_ca')])
                    ->default(fn (): ?string => in_array(request()->query('filtro'), ['low', 'ca'], true) ? request()->query('filtro') : null)
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'low' => $query->whereKey($service->lowStockQuery($kinds)->pluck('id')),
                        'ca' => $query->whereKey($service->caQuery()->pluck('id')),
                        default => $query,
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('epi.actions.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->link()
                    ->authorize(fn (): bool => $this->canEdit())
                    ->modalHeading(fn (EpiItem $record): string => $record->fullLabel())
                    ->modalWidth('2xl')
                    ->fillForm(fn (EpiItem $record): array => $this->formData($record))
                    ->schema(fn (): array => $this->fields())
                    ->action(function (EpiItem $record, array $data): void {
                        $record->fill($this->values($data, $record));

                        if (! $record->isLowStock()) {
                            $record->low_stock_notified_at = null;
                        }

                        $record->save();
                        Notification::make()->success()->title(__('epi.items.saved'))->send();
                    }),
                Action::make('addStock')
                    ->label(__('epi.actions.add_stock'))
                    ->icon(Heroicon::OutlinedArrowDownOnSquare)
                    ->link()
                    ->authorize(fn (): bool => $this->canEdit())
                    ->modalHeading(fn (EpiItem $record): string => __('epi.stock.add_heading', ['item' => $record->fullLabel()]))
                    ->modalDescription(fn (EpiItem $record): string => $record->tracksStock() ? __('epi.stock.now', ['count' => $record->stock]) : __('epi.stock.first_entry'))
                    ->schema([
                        TextInput::make('quantity')
                            ->label(__('epi.fields.quantity'))
                            ->integer()
                            ->minValue(1)
                            ->maxValue(99999)
                            ->required(),
                    ])
                    ->action(function (EpiItem $record, array $data): void {
                        try {
                            $this->epi()->addStock($record, (int) $data['quantity']);
                        } catch (EpiException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();

                            return;
                        }

                        Notification::make()->success()->title(__('epi.stock.added', ['count' => $record->stock]))->send();
                    }),
                ActionGroup::make([
                    Action::make('duplicate')
                        ->label(__('epi.actions.duplicate'))
                        ->icon(Heroicon::OutlinedDocumentDuplicate)
                        ->authorize(fn (): bool => $this->canEdit())
                        ->modalHeading(__('epi.items.duplicate_heading'))
                        ->modalDescription(__('epi.items.duplicate_help'))
                        ->modalWidth('2xl')
                        ->fillForm(fn (EpiItem $record): array => ['size' => null, 'code' => null, 'stock' => null] + $this->formData($record))
                        ->schema(fn (): array => $this->fields())
                        ->action(function (array $data): void {
                            $item = new EpiItem($this->values($data));
                            $item->sort = (int) EpiItem::query()->max('sort') + 10;
                            $item->save();
                            Notification::make()->success()->title(__('epi.items.saved'))->send();
                        }),
                ])
                    ->icon(Heroicon::OutlinedEllipsisVertical)
                    ->tooltip(__('app.more_actions'))
                    ->iconButton(),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('epi.items.empty'))
            ->emptyStateIcon(Heroicon::OutlinedArchiveBox);
    }
}
