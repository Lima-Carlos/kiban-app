<?php

namespace App\Filament\Pages;

use App\Models\Team;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rules\Unique;

/**
 * Teams / sectors of the company (e.g. "Installation team", "Office"). Who manages people manages them.
 * A team in use is turned off, not deleted.
 */
class TeamsPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?string $slug = 'equipes';

    protected static ?int $navigationSort = -3;

    /** Fixed at the end of every system's menu ("Company"). */
    public static function getNavigationGroup(): ?string
    {
        return __('app.menu.company');
    }

    public static function canAccess(): bool
    {
        return PeoplePage::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return __('app.teams.title');
    }

    public function getTitle(): string
    {
        return __('app.teams.title');
    }

    public function getSubheading(): ?string
    {
        return __('app.teams.help');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /**
     * @return array<int, mixed>
     */
    protected static function fields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('app.teams.name'))
                ->required()
                ->maxLength(80)
                ->unique(Team::class, 'name', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule): Unique => $rule->where('tenant_id', app(TenantContext::class)->get()->getKey())),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('app.people.title'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => PeoplePage::getUrl()),
            Action::make('create')
                ->label(__('app.teams.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize(fn (): bool => static::canAccess())
                ->schema(static::fields())
                ->action(function (array $data): void {
                    Team::create(['name' => trim($data['name'])]);
                    Notification::make()->success()->title(__('app.people.saved'))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => Team::query()->withCount(['people' => fn (Builder $query) => $query->where('is_active', true)]))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')->label(__('app.teams.name'))->weight('bold')->searchable(),
                TextColumn::make('people_count')->label(__('app.teams.people'))->alignEnd(),
                ToggleColumn::make('is_active')->label(__('app.teams.active')),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('app.people.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->link()
                    ->authorize(fn (): bool => static::canAccess())
                    ->fillForm(fn (Team $record): array => ['name' => $record->name])
                    ->schema(static::fields())
                    ->action(fn (Team $record, array $data) => $record->update(['name' => trim($data['name'])])),
            ])
            ->emptyStateHeading(__('app.teams.empty'));
    }
}
