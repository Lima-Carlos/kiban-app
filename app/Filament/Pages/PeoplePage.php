<?php

namespace App\Filament\Pages;

use App\Access\AccessManager;
use App\Licensing\CoreUnavailableException;
use App\Licensing\CoreValidationException;
use App\Models\AccessProfile;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\People\AccessNotice;
use App\People\PeopleService;
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
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * "People": everyone of the company in the Kiban systems, in one place. The manager adds a driver or a
 * mechanic here (name, mobile, optional e-mail, what they do in each system) and sends the access by
 * WhatsApp or e-mail. Nobody needs to go to the customer area for this.
 */
class PeoplePage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = -5;

    protected static ?string $slug = 'pessoas';

    /** Status of each person in the Core (invited / active / blocked), by Core user id. */
    public array $statuses = [];

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();
        $user = auth()->user();

        if ($tenant === null || ! $user instanceof User || ! $tenant->license()->isActive()) {
            return false;
        }

        return $user->canManageTenant() || collect(static::modules())->contains(fn (Module $module): bool => $module->managesPeople($user));
    }

    public static function getNavigationLabel(): string
    {
        return __('app.people.title');
    }

    public function getTitle(): string
    {
        return __('app.people.title');
    }

    public function getSubheading(): ?string
    {
        return __('app.people.help');
    }

    public function mount(): void
    {
        $this->statuses = app(PeopleService::class)->statuses($this->tenant());
    }

    /**
     * @return array<string, Module>
     */
    protected static function modules(): array
    {
        return app(ModuleRegistry::class)->enabledFor(app(TenantContext::class)->get());
    }

    protected function tenant(): Tenant
    {
        return app(TenantContext::class)->get();
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('add')
                ->label(__('app.people.add'))
                ->icon(Heroicon::OutlinedUserPlus)
                ->authorize(fn (): bool => static::canAccess() && $this->tenantCanWrite())
                ->modalWidth('2xl')
                ->schema(fn (): array => [
                    TextInput::make('name')
                        ->label(__('app.people.name'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('phone')
                        ->label(__('app.people.phone'))
                        ->tel()
                        ->helperText(__('app.people.phone_help'))
                        ->required(fn (Get $get): bool => blank($get('email')))
                        ->maxLength(30),
                    TextInput::make('email')
                        ->label(__('app.people.email'))
                        ->email()
                        ->helperText(__('app.people.email_help'))
                        ->maxLength(255),
                    $this->profileField(),
                    ...$this->moduleSections(),
                ])
                ->action(function (array $data): void {
                    try {
                        $result = app(PeopleService::class)->add($this->tenant(), $data['name'], $data['phone'] ?? null, $data['email'] ?? null, (array) ($data['modules'] ?? []), auth()->user());
                    } catch (CoreValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors)->mapWithKeys(fn ($messages, $field): array => ["mountedActions.0.data.{$field}" => $messages])->all());
                    } catch (CoreUnavailableException) {
                        Notification::make()->danger()->title(__('app.failed.core'))->send();

                        return;
                    }

                    app(AccessManager::class)->rememberProfile($result['user'], AccessProfile::query()->find($data['profile_id'] ?? null));
                    $this->statuses = app(PeopleService::class)->statuses($this->tenant());
                    AccessNotice::send($this->tenant(), $result['user'], $result['invitation_url']);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        $tenant = $this->tenant();

        return $table
            ->query(fn (): Builder => User::query()->whereHas('tenants', fn (Builder $query) => $query->whereKey($tenant->getKey())))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('app.people.name'))
                    ->weight('bold')
                    ->description(fn (User $record): ?string => collect([static::formatPhone($record->phone), $record->email])->filter()->implode(' · ') ?: null)
                    ->searchable(['name', 'email', 'phone']),
                TextColumn::make('company_role')
                    ->label(__('app.people.company_role'))
                    ->state(fn (User $record): string => __('app.people.roles.'.($record->roleIn($tenant) ?? 'member')))
                    ->badge()
                    ->color(fn (User $record): string => $record->roleIn($tenant) === User::ROLE_MEMBER ? 'gray' : 'primary')
                    ->visibleFrom('md'),
                TextColumn::make('profile')
                    ->label(__('app.people.profile'))
                    ->state(fn (User $record): ?string => app(AccessManager::class)->profileOf($record)?->name)
                    ->placeholder('—')
                    ->visibleFrom('lg'),
                TextColumn::make('systems')
                    ->label(__('app.people.systems'))
                    ->state(fn (User $record): array => collect(static::modules())
                        ->map(fn (Module $module): ?string => ($label = $module->personRoleLabel($record)) ? $module->name().': '.$label : null)
                        ->filter()->values()->all())
                    ->badge()
                    ->color('success'),
                TextColumn::make('status')
                    ->label(__('app.people.status'))
                    ->state(fn (User $record): string => $this->statusOf($record))
                    ->formatStateUsing(fn (string $state): string => __('app.people.statuses.'.$state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'invited' => 'warning',
                        default => 'danger',
                    }),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('app.people.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->visible(fn (User $record): bool => ! $record->canManageTenant() && $this->moduleSections() !== [])
                    ->authorize(fn (): bool => static::canAccess() && $this->tenantCanWrite())
                    ->fillForm(fn (User $record): array => [
                        'profile_id' => app(AccessManager::class)->profileOf($record)?->getKey(),
                        'modules' => collect(static::modules())->map(fn (Module $module): array => $module->personData($record))->all(),
                    ])
                    ->schema(fn (): array => [$this->profileField(), ...$this->moduleSections()])
                    ->action(function (User $record, array $data): void {
                        app(PeopleService::class)->saveModules($record, (array) ($data['modules'] ?? []));
                        app(AccessManager::class)->rememberProfile($record, AccessProfile::query()->find($data['profile_id'] ?? null));
                        Notification::make()->success()->title(__('app.people.saved'))->send();
                    }),
                Action::make('access')
                    ->label(__('app.people.send_access'))
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->iconButton()
                    ->visible(fn (User $record): bool => $this->statusOf($record) === 'invited')
                    ->authorize(fn (): bool => static::canAccess() && $this->tenantCanWrite())
                    ->requiresConfirmation()
                    ->modalDescription(__('app.people.send_access_help'))
                    ->action(function (User $record): void {
                        try {
                            $url = app(PeopleService::class)->invitationLink($this->tenant(), $record, auth()->user());
                        } catch (CoreUnavailableException) {
                            Notification::make()->danger()->title(__('app.failed.core'))->send();

                            return;
                        }

                        AccessNotice::send($this->tenant(), $record, $url);
                    }),
                Action::make('remove')
                    ->label(__('app.people.remove'))
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->iconButton()
                    ->color('danger')
                    ->visible(fn (User $record): bool => $record->roleIn($tenant) === User::ROLE_MEMBER && ! $record->is(auth()->user()))
                    ->authorize(fn (): bool => static::canAccess() && $this->tenantCanWrite())
                    ->requiresConfirmation()
                    ->modalDescription(__('app.people.remove_help'))
                    ->action(function (User $record): void {
                        try {
                            app(PeopleService::class)->remove($this->tenant(), $record);
                        } catch (CoreUnavailableException) {
                            Notification::make()->danger()->title(__('app.failed.core'))->send();

                            return;
                        }

                        Notification::make()->success()->title(__('app.people.removed'))->send();
                    }),
            ])
            ->emptyStateHeading(__('app.people.empty'));
    }

    /**
     * Access profile: choosing it fills the role of each module (it can still be adjusted below).
     */
    protected function profileField(): Select
    {
        app(AccessManager::class)->ensureSuggestedProfiles();

        return Select::make('profile_id')
            ->label(__('app.people.profile'))
            ->helperText(__('app.people.profile_help'))
            ->options(fn (): array => AccessProfile::query()->orderBy('name')->pluck('name', 'id')->all())
            ->live()
            ->afterStateUpdated(function (?string $state, Set $set): void {
                $profile = $state ? AccessProfile::query()->find($state) : null;

                if ($profile === null) {
                    return;
                }

                foreach (array_keys(static::modules()) as $key) {
                    $set("modules.{$key}.role", $profile->roleFor($key) ?? AccessManager::NONE);
                }
            });
    }

    /**
     * One section per system the company uses (e.g. "Frota: role, vehicles, driver's license").
     *
     * @return array<int, Section>
     */
    protected function moduleSections(): array
    {
        return collect(static::modules())
            ->filter(fn (Module $module): bool => $module->personFields() !== [])
            ->map(fn (Module $module, string $key): Section => Section::make($module->name())
                ->icon($module->icon())
                ->statePath("modules.{$key}")
                ->components($module->personFields()))
            ->values()
            ->all();
    }

    protected function statusOf(User $user): string
    {
        return $this->statuses[$user->core_id] ?? ($user->last_login_at !== null ? 'active' : 'invited');
    }

    protected function tenantCanWrite(): bool
    {
        return collect(static::modules())->contains(fn (Module $module): bool => $this->tenant()->license()->canWrite($module->key()))
            || static::modules() === [];
    }

    public static function formatPhone(?string $digits): ?string
    {
        if ($digits === null) {
            return null;
        }

        if (str_starts_with($digits, '55') && in_array(strlen($digits), [12, 13], true)) {
            $local = substr($digits, 4);

            return '('.substr($digits, 2, 2).') '.substr($local, 0, -4).'-'.substr($local, -4);
        }

        return '+'.$digits;
    }
}
