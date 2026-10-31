<?php

namespace App\Filament\Pages;

use App\Filament\Support\ManagesEpi;
use App\People\Epi\EpiDeliveryLine;
use App\People\Epi\EpiService;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Url;

/**
 * The PPE and tools of one person (opened from People): what is with them now, the history, "New delivery",
 * return, replacement and the record in PDF. Who handles PPE sees everything; the Frota/Campo managers only the
 * tools.
 */
class PersonEpiPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable, ManagesEpi;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $slug = 'pessoas/epi';

    protected static bool $shouldRegisterNavigation = false;

    #[Url(as: 'pessoa')]
    public ?int $personId = null;

    public static function canAccess(): bool
    {
        return app(EpiService::class)->canHandle(auth()->user());
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
        return __('epi.person.title', ['name' => $this->person()?->name]);
    }

    public function getSubheading(): ?string
    {
        $pending = $this->epi()->pendingFor($this->person());

        return $pending > 0 ? trans_choice('epi.person.pending', $pending, ['count' => $pending]) : __('epi.person.help');
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
            $this->newDeliveryAction($person)->visible($person?->is_active ?? false),
            $this->recordAction($person)
                ->visible(fn (): bool => $this->epi()->canManage(auth()->user()) && $person->epiDeliveries()->exists()),
        ];
    }

    public function table(Table $table): Table
    {
        $kinds = $this->kinds();

        return $table
            ->query(fn (): Builder => EpiDeliveryLine::query()->ofKinds($kinds)->with(['delivery.person.team', 'item'])
                ->whereHas('delivery', fn (Builder $query) => $query->where('person_id', $this->personId)))
            // Still with the person first (the replacement closest on top), then the history.
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('epi_delivery_lines.returned_quantity >= epi_delivery_lines.quantity')
                ->orderByRaw('epi_delivery_lines.replace_on asc nulls last')->orderByDesc('epi_delivery_lines.id'))
            ->columns($this->lineColumns(withPerson: false))
            ->filters([
                SelectFilter::make('show')
                    ->label(__('epi.person.show'))
                    ->options(['now' => __('epi.person.show_now'), 'all' => __('epi.person.show_all')])
                    ->default('now')
                    ->selectablePlaceholder(false)
                    ->query(fn (Builder $query, array $data): Builder => ($data['value'] ?? 'now') === 'all' ? $query : $query->open()),
            ])
            ->recordActions($this->lineRecordActions())
            ->paginated(false)
            ->emptyStateHeading(__('epi.person.empty'))
            ->emptyStateDescription(__('epi.person.empty_help'))
            ->emptyStateIcon(Heroicon::OutlinedWrenchScrewdriver);
    }
}
