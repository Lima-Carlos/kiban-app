<?php

namespace App\Filament\Pages;

use App\Filament\Support\ManagesEpi;
use App\Models\Team;
use App\People\Epi\EpiDeliveryLine;
use App\People\Epi\EpiExporter;
use App\People\Epi\EpiItem;
use App\People\Epi\EpiService;
use App\People\Epi\EpiSetting;
use App\People\Epi\LineStatus;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\View;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "PPE and tools" (in the Company group): what is with whom, what to replace (useful life), the CA expiring, the
 * deliveries waiting for the signature and the low stock, with filters and the Excel spreadsheet. Owners,
 * administrators and HR (everything); the Frota and Campo managers (tools only).
 */
class EpiPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable, ManagesEpi;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = -3;

    protected static ?string $slug = 'empresa/epi';

    public static function getNavigationGroup(): ?string
    {
        return __('app.menu.company');
    }

    public static function canAccess(): bool
    {
        return app(EpiService::class)->canHandle(auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('epi.title');
    }

    public function getTitle(): string
    {
        return __('epi.title');
    }

    public function getSubheading(): ?string
    {
        return $this->kinds() === ['tool'] ? __('epi.help_tools') : __('epi.help');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.epi.summary')->viewData(fn (): array => [
                'counts' => $this->epi()->counts($this->kinds()),
                'itemsUrl' => EpiItemsPage::getUrl(),
                'withEpi' => in_array('epi', $this->kinds(), true),
            ]),
            EmbeddedTable::make(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->newDeliveryAction(),
            Action::make('export')
                ->label(__('epi.actions.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->authorize(fn (): bool => static::canAccess())
                ->action(fn (): BinaryFileResponse => $this->download()),
            Action::make('items')
                ->label(__('epi.items.title'))
                ->icon(Heroicon::OutlinedArchiveBox)
                ->color('gray')
                ->url(fn (): string => EpiItemsPage::getUrl()),
            Action::make('terms')
                ->label(__('epi.terms.title'))
                ->icon(Heroicon::OutlinedDocumentText)
                ->color('gray')
                ->visible(fn (): bool => $this->epi()->canManage(auth()->user()))
                ->authorize(fn (): bool => $this->epi()->canManage(auth()->user()) && $this->tenantCanWrite())
                ->modalHeading(__('epi.terms.title'))
                ->modalDescription(__('epi.terms.help'))
                ->modalWidth('2xl')
                ->fillForm(fn (): array => ['terms' => $this->epi()->terms()])
                ->schema([
                    Textarea::make('terms')
                        ->label(__('epi.terms.label'))
                        ->rows(8)
                        ->required()
                        ->maxLength(3000),
                ])
                ->action(function (array $data): void {
                    $setting = EpiSetting::current();
                    $text = trim((string) $data['terms']);
                    $setting->terms = $text === trim(app(EpiService::class)->standardTerms()) ? null : $text;
                    $setting->save();
                    Notification::make()->success()->title(__('epi.terms.saved'))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $service = $this->epi();
        $kinds = $this->kinds();

        return $table
            ->query(fn (): Builder => $service->openQuery($kinds)->with(['delivery.person.team', 'item']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('epi_delivery_lines.replace_on asc nulls last')->orderBy('epi_delivery_lines.id'))
            ->columns($this->lineColumns(withPerson: true))
            ->filters([
                SelectFilter::make('situation')
                    ->label(__('epi.fields.replacement'))
                    ->options(collect([LineStatus::Due, LineStatus::Soon, LineStatus::Ok, LineStatus::NoLife])->mapWithKeys(fn (LineStatus $status): array => [$status->value => $status->getLabel()])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $service->whereStatus($query, $data['value']) : $query),
                SelectFilter::make('signature')
                    ->label(__('epi.fields.signature'))
                    ->options(['pending' => __('epi.sign.pending'), 'signed' => __('epi.sign.signed')])
                    ->query(fn (Builder $query, array $data): Builder => match ($data['value'] ?? null) {
                        'pending' => $query->whereHas('delivery', fn (Builder $deliveries) => $deliveries->whereNull('signed_at')),
                        'signed' => $query->whereHas('delivery', fn (Builder $deliveries) => $deliveries->whereNotNull('signed_at')),
                        default => $query,
                    }),
                SelectFilter::make('kind')
                    ->label(__('epi.fields.kind'))
                    ->options(fn (): array => $this->kindOptions())
                    ->visible(fn (): bool => count($kinds) > 1),
                SelectFilter::make('item_id')
                    ->label(__('epi.fields.item'))
                    ->options(fn (): array => EpiItem::query()->ofKinds($kinds)->ordered()->get()->mapWithKeys(fn (EpiItem $item): array => [$item->getKey() => $item->fullLabel()])->all())
                    ->searchable(),
                SelectFilter::make('team')
                    ->label(__('app.teams.team'))
                    ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('delivery.person', fn (Builder $people) => $people->where('team_id', $data['value']))
                        : $query),
            ])
            ->recordActions($this->lineRecordActions())
            ->emptyStateHeading(__('epi.empty'))
            ->emptyStateDescription(__('epi.empty_help'))
            ->emptyStateIcon(Heroicon::OutlinedWrenchScrewdriver)
            ->emptyStateActions([
                Action::make('emptyNew')
                    ->label(__('epi.actions.new_delivery'))
                    ->icon(Heroicon::OutlinedPlus)
                    ->visible(fn (): bool => $this->canDeliver())
                    ->action(fn () => $this->mountAction('newDelivery')),
            ]);
    }

    /** A number on the top of the screen filters the list (e.g. "3 to replace"). */
    public function showOnly(string $filter, string $value): void
    {
        abort_unless(in_array($filter, ['situation', 'signature'], true), 404);
        $this->tableFilters = [$filter => ['value' => $value]];
        $this->tableDeferredFilters = $this->tableFilters;
        $this->handleTableFilterUpdates();
    }

    /** The list as it is on the screen (filters and order) in an Excel spreadsheet. */
    public function download(): BinaryFileResponse
    {
        abort_unless(static::canAccess(), 403);

        /** @var Builder<EpiDeliveryLine> $query */
        $query = $this->getFilteredSortedTableQuery();
        $path = app(EpiExporter::class)->export($query->with(['delivery.person.team'])->get());

        return response()->download($path, basename(preg_replace('/-[A-Za-z0-9]{6}\.xlsx$/', '.xlsx', $path)))->deleteFileAfterSend();
    }
}
