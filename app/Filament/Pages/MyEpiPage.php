<?php

namespace App\Filament\Pages;

use App\Filament\Support\ManagesEpi;
use App\Models\User;
use App\People\Epi\EpiDeliveryLine;
use App\People\Epi\EpiService;
use App\People\Person;
use App\Tenancy\TenantContext;
use BackedEnum;
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

/**
 * "My deliveries" (user menu): the PPE and tools with the person, the history, and the deliveries waiting for
 * their signature (signed here with the finger, on their own phone). Also the person's own record in PDF.
 */
class MyEpiPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable, ManagesEpi;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $slug = 'minhas-entregas';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return static::myPerson() !== null;
    }

    /** Shown in the user menu only when the person has deliveries. */
    public static function hasDeliveries(): bool
    {
        $person = static::myPerson();

        return $person !== null && $person->epiDeliveries()->exists();
    }

    protected static function myPerson(): ?Person
    {
        $user = auth()->user();
        $tenant = app(TenantContext::class)->current();

        if (! $user instanceof User || $tenant === null || ! $user->belongsToTenant($tenant)) {
            return null;
        }

        return app(EpiService::class)->personOf($user);
    }

    public function getTitle(): string
    {
        return __('epi.mine.title');
    }

    public function getSubheading(): ?string
    {
        $pending = $this->epi()->pendingFor(static::myPerson());

        return $pending > 0 ? trans_choice('epi.mine.pending', $pending, ['count' => $pending]) : __('epi.mine.help');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        $person = static::myPerson();

        return $person !== null && $person->epiDeliveries()->exists() ? [$this->recordAction($person)->label(__('epi.mine.record'))] : [];
    }

    public function table(Table $table): Table
    {
        $personId = static::myPerson()?->getKey();

        return $table
            ->query(fn (): Builder => EpiDeliveryLine::query()->with(['delivery.person', 'item'])
                ->whereHas('delivery', fn (Builder $query) => $query->where('person_id', $personId)))
            // Waiting for my signature first, then what is with me, then the history.
            ->defaultSort(fn (Builder $query): Builder => $query
                ->orderByRaw('(select signed_at is not null from epi_deliveries where epi_deliveries.id = epi_delivery_lines.delivery_id)')
                ->orderByRaw('epi_delivery_lines.returned_quantity >= epi_delivery_lines.quantity')
                ->orderByRaw('epi_delivery_lines.replace_on asc nulls last')->orderByDesc('epi_delivery_lines.id'))
            ->columns($this->lineColumns(withPerson: false))
            ->recordActions($this->lineRecordActions(manage: false))
            ->paginated(false)
            ->emptyStateHeading(__('epi.mine.empty'))
            ->emptyStateIcon(Heroicon::OutlinedWrenchScrewdriver);
    }
}
