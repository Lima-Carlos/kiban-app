<?php

namespace App\Filament\Pages;

use App\Filament\Support\ManagesPersonDocuments;
use App\People\Documents\PersonDocument;
use App\People\Person;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * The documents of one person (opened from People): the current ones and the history (renewed ones), with
 * "Add document". Same people as "Expiry dates" (owners, administrators, HR).
 */
class PersonDocumentsPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable, ManagesPersonDocuments;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $slug = 'pessoas/documentos';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'pessoa')]
    public ?int $personId = null;

    public static function canAccess(): bool
    {
        return DocumentsPage::canAccess();
    }

    public function mount(): void
    {
        abort_if($this->person() === null, 404);
    }

    public function person(): ?Person
    {
        return $this->personId ? Person::query()->find($this->personId) : null;
    }

    public function getTitle(): string
    {
        return __('documents.person.title', ['name' => $this->person()?->name]);
    }

    public function getSubheading(): ?string
    {
        return __('documents.person.help');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        $person = $this->person();

        return [
            Action::make('back')
                ->label(__('app.people.title'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => PeoplePage::getUrl()),
            $this->addDocumentAction($person)->visible($person?->is_active ?? false),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => PersonDocument::query()->with(['person.team', 'type'])->where('person_id', $this->personId))
            // Current ones first (soonest expiry), then the history.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('replaced_at is not null')->orderByRaw('expires_on asc nulls last')->orderByDesc('id'))
            ->columns($this->documentColumns(withPerson: false))
            ->recordActions($this->documentRecordActions())
            ->paginated(false)
            ->emptyStateHeading(__('documents.person.empty'))
            ->emptyStateDescription(__('documents.expiry.empty_help'))
            ->emptyStateIcon(Heroicon::OutlinedIdentification);
    }
}
