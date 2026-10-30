<?php

namespace App\Filament\Pages;

use App\Filament\Support\ManagesPersonDocuments;
use App\Models\User;
use App\People\Documents\PersonDocument;
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
use Illuminate\Support\Facades\Schema as DatabaseSchema;

/**
 * "My documents" (user menu): the person's own documents and their expiry, read only. The company keeps them;
 * the person sees them (and opens the file) to know when to renew.
 */
class MyDocumentsPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable, ManagesPersonDocuments;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?string $slug = 'meus-documentos';

    protected static bool $shouldRegisterNavigation = false;

    public static function canAccess(): bool
    {
        return static::myPerson() !== null;
    }

    /** Shown in the user menu only when the person has documents. */
    public static function hasDocuments(): bool
    {
        $person = static::myPerson();

        return $person !== null && PersonDocument::query()->current()->where('person_id', $person->getKey())->exists();
    }

    protected static function myPerson(): ?Person
    {
        $user = auth()->user();
        $tenant = app(TenantContext::class)->current();

        if (! $user instanceof User || $tenant === null || ! $user->belongsToTenant($tenant) || ! DatabaseSchema::hasTable('person_documents')) {
            return null;
        }

        return Person::query()->where('user_id', $user->getKey())->first();
    }

    public function getTitle(): string
    {
        return __('documents.mine.title');
    }

    public function getSubheading(): ?string
    {
        return __('documents.mine.help');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        $personId = static::myPerson()?->getKey();

        return $table
            ->query(fn (): Builder => PersonDocument::query()->current()->with(['person', 'type'])->where('person_id', $personId))
            ->defaultSort(fn (Builder $query): Builder => $query->orderByRaw('expires_on asc nulls last')->orderBy('id'))
            ->columns($this->documentColumns(withPerson: false))
            ->recordActions([$this->documentRecordActions()[0]])
            ->paginated(false)
            ->emptyStateHeading(__('documents.mine.empty'))
            ->emptyStateIcon(Heroicon::OutlinedIdentification);
    }
}
