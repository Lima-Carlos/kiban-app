<?php

namespace App\Filament\Pages;

use App\Filament\Support\ManagesPersonDocuments;
use App\Models\Team;
use App\People\Documents\DocumentExporter;
use App\People\Documents\DocumentStatus;
use App\People\Documents\PersonDocument;
use App\People\Documents\PersonDocumentService;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
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
 * "Expiry dates" (in the Company group): the current documents of everyone in the company (driver's license,
 * medical exam, NR trainings...), the soonest expiry first, with filters (situation, type, team) and the Excel
 * spreadsheet. Owners, administrators and HR only (the same people who see the CPF).
 */
class DocumentsPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable, ManagesPersonDocuments;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = -4;

    protected static ?string $slug = 'empresa/vencimentos';

    public static function getNavigationGroup(): ?string
    {
        return __('app.menu.company');
    }

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->isActive() && app(PersonDocumentService::class)->canManage(auth()->user());
    }

    public static function getNavigationLabel(): string
    {
        return __('documents.expiry.title');
    }

    public function getTitle(): string
    {
        return __('documents.expiry.title');
    }

    public function getSubheading(): ?string
    {
        return __('documents.expiry.help');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            $this->addDocumentAction(),
            Action::make('export')
                ->label(__('documents.actions.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->authorize(fn (): bool => static::canAccess())
                ->action(fn (): BinaryFileResponse => $this->download()),
            Action::make('types')
                ->label(__('documents.types.title'))
                ->icon(Heroicon::OutlinedAdjustmentsHorizontal)
                ->color('gray')
                ->url(fn (): string => DocumentTypesPage::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        $service = $this->documents();

        return $table
            ->query(fn (): Builder => $service->currentQuery()->with(['person.team', 'type']))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('person_documents.expires_on asc nulls last')->orderBy('person_documents.id'))
            ->columns($this->documentColumns(withPerson: true))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('documents.fields.situation'))
                    ->options(collect(DocumentStatus::cases())->mapWithKeys(fn (DocumentStatus $status): array => [$status->value => $status->getLabel()])->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null) ? $service->whereStatus($query, $data['value']) : $query),
                SelectFilter::make('document_type_id')
                    ->label(__('documents.fields.type'))
                    ->options(fn (): array => $service->typeOptions(activeOnly: false)),
                SelectFilter::make('team')
                    ->label(__('app.teams.team'))
                    ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all())
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->whereHas('person', fn (Builder $people) => $people->where('team_id', $data['value']))
                        : $query),
            ])
            ->recordActions($this->documentRecordActions())
            ->emptyStateHeading(__('documents.expiry.empty'))
            ->emptyStateDescription(__('documents.expiry.empty_help'))
            ->emptyStateIcon(Heroicon::OutlinedIdentification);
    }

    /** The list as it is on the screen (filters and order) in an Excel spreadsheet. */
    public function download(): BinaryFileResponse
    {
        abort_unless(static::canAccess(), 403);

        /** @var Builder<PersonDocument> $query */
        $query = $this->getFilteredSortedTableQuery();
        $path = app(DocumentExporter::class)->export($query->with(['person.team', 'type'])->get());

        return response()->download($path, basename(preg_replace('/-[A-Za-z0-9]{6}\.xlsx$/', '.xlsx', $path)))->deleteFileAfterSend();
    }
}
