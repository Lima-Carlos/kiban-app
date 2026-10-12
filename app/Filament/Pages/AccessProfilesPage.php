<?php

namespace App\Filament\Pages;

use App\Access\AccessManager;
use App\Models\AccessProfile;
use App\Models\User;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Access profiles" (sectors): the role of each module in one name ("Logistics", "HR", "Driver"...).
 * Chosen when adding a person in "People". Owners and administrators of the company manage them;
 * the app suggests some ready profiles the first time.
 */
class AccessProfilesPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = -4;

    protected static ?string $slug = 'perfis-de-acesso';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();
        $user = auth()->user();

        return $tenant !== null && $user instanceof User && $tenant->license()->isActive() && $user->canManageTenant();
    }

    public static function getNavigationLabel(): string
    {
        return __('app.profiles.title');
    }

    public function getTitle(): string
    {
        return __('app.profiles.title');
    }

    public function getSubheading(): ?string
    {
        return __('app.profiles.help');
    }

    public function mount(): void
    {
        app(AccessManager::class)->ensureSuggestedProfiles();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    /**
     * @return array<string, Module>
     */
    protected static function modules(): array
    {
        return array_filter(
            app(ModuleRegistry::class)->enabledFor(app(TenantContext::class)->get()),
            fn (Module $module): bool => $module->roles() !== [],
        );
    }

    /**
     * Name, description and one "role" per module of the company ("no access" by default).
     *
     * @return array<int, mixed>
     */
    public static function formFields(): array
    {
        return [
            TextInput::make('name')
                ->label(__('app.profiles.name'))
                ->required()
                ->maxLength(255),
            TextInput::make('description')
                ->label(__('app.profiles.description'))
                ->maxLength(255),
            Section::make(__('app.profiles.roles'))
                ->description(__('app.profiles.roles_help'))
                ->columns(2)
                ->components(collect(static::modules())->map(fn (Module $module, string $key): Select => Select::make("roles.{$key}")
                    ->label($module->name())
                    ->options([AccessManager::NONE => __('app.profiles.no_access')] + $module->roles())
                    ->default(AccessManager::NONE)
                    ->required())->values()->all()),
        ];
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('app.profiles.create'))
                ->icon(Heroicon::OutlinedPlus)
                ->authorize(fn (): bool => static::canAccess())
                ->schema(fn (): array => static::formFields())
                ->action(function (array $data): void {
                    AccessProfile::query()->create(['name' => $data['name'], 'description' => $data['description'] ?? null, 'roles' => (array) ($data['roles'] ?? [])]);
                    Notification::make()->success()->title(__('app.people.saved'))->send();
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => AccessProfile::query())
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('app.profiles.name'))
                    ->weight('bold')
                    ->description(fn (AccessProfile $record): ?string => $record->description),
                TextColumn::make('access')
                    ->label(__('app.profiles.roles'))
                    ->state(fn (AccessProfile $record): array => collect(static::modules())
                        ->map(fn (Module $module, string $key): ?string => ($role = $record->roleFor($key)) ? $module->name().': '.($module->roles()[$role] ?? $role) : null)
                        ->filter()->values()->all())
                    ->badge()
                    ->placeholder(__('app.profiles.no_access')),
                TextColumn::make('people')
                    ->label(__('app.profiles.people'))
                    ->state(fn (AccessProfile $record): int => DB::table('tenant_user')->where('tenant_id', $record->tenant_id)->where('access_profile_id', $record->getKey())->count())
                    ->alignEnd(),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('app.people.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->authorize(fn (): bool => static::canAccess())
                    ->fillForm(fn (AccessProfile $record): array => [
                        'name' => $record->name,
                        'description' => $record->description,
                        'roles' => collect(static::modules())->mapWithKeys(fn (Module $module, string $key): array => [$key => $record->roleFor($key) ?? AccessManager::NONE])->all(),
                    ])
                    ->modalDescription(__('app.profiles.edit_help'))
                    ->schema(fn (): array => static::formFields())
                    ->action(function (AccessProfile $record, array $data): void {
                        $record->update(['name' => $data['name'], 'description' => $data['description'] ?? null, 'roles' => (array) ($data['roles'] ?? [])]);
                        Notification::make()->success()->title(__('app.people.saved'))->send();
                    }),
                Action::make('delete')
                    ->label(__('app.profiles.delete'))
                    ->icon(Heroicon::OutlinedTrash)
                    ->iconButton()
                    ->color('danger')
                    ->authorize(fn (): bool => static::canAccess())
                    ->requiresConfirmation()
                    ->modalDescription(__('app.profiles.delete_help'))
                    ->action(function (AccessProfile $record): void {
                        // People keep their access; they just no longer show this profile.
                        DB::transaction(function () use ($record): void {
                            DB::table('tenant_user')->where('tenant_id', $record->tenant_id)->where('access_profile_id', $record->getKey())->update(['access_profile_id' => null]);
                            $record->delete();
                        });
                    }),
            ])
            ->emptyStateHeading(__('app.profiles.empty'));
    }
}
