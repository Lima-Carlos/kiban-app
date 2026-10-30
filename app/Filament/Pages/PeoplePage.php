<?php

namespace App\Filament\Pages;

use App\Access\AccessManager;
use App\Licensing\CoreUnavailableException;
use App\Licensing\CoreValidationException;
use App\Models\AccessProfile;
use App\Models\Team;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\People\AccessNotice;
use App\People\Documents\DocumentStatus;
use App\People\Documents\PersonDocument;
use App\People\Documents\PersonDocumentService;
use App\People\PeopleService;
use App\People\Person;
use App\People\PersonRuleException;
use App\Rules\Cpf;
use App\Support\Country;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Components\Grid;
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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "People": everyone of the company in the Kiban systems, in one place. The manager adds a driver, a
 * mechanic or an employee here (name, mobile, optional e-mail, document, team, what they do in each
 * system). With a login, the access goes by WhatsApp or e-mail; without one (e.g. clocks in only on the
 * company tablet), the person can get it later. "Deactivate" keeps the record and the history.
 */
class PeoplePage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUsers;

    protected static ?int $navigationSort = -5;

    protected static ?string $slug = 'pessoas';

    /** Status of each login in the Core (invited / active / blocked), by Core user id. */
    public array $statuses = [];

    /** Fixed at the end of every system's menu ("Company"). */
    public static function getNavigationGroup(): ?string
    {
        return __('app.menu.company');
    }

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
        app(PeopleService::class)->ensureRecords($this->tenant());
        $this->statuses = app(PeopleService::class)->statuses($this->tenant());

        // Opened from another screen (e.g. "Add employee"): the form is already open.
        if (request()->boolean('add')) {
            $this->mountAction('add');
        }
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
                    Toggle::make('with_login')
                        ->label(__('app.people.with_login'))
                        ->helperText(__('app.people.with_login_help'))
                        ->default(true)
                        ->live(),
                    ...$this->contactFields(),
                    ...$this->detailFields(),
                    $this->profileField()->visible(fn (Get $get): bool => (bool) $get('with_login')),
                    ...$this->moduleSections(),
                ])
                ->action(function (array $data, Action $action): void {
                    $withLogin = (bool) ($data['with_login'] ?? false);

                    try {
                        $result = app(PeopleService::class)->add($this->tenant(), $this->details($data), (array) ($data['modules'] ?? []), auth()->user(), $withLogin);
                    } catch (PersonRuleException $exception) {
                        // A module refused (e.g. plan limit, CPF missing): nothing was saved, no login was created.
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $action->halt();
                    } catch (CoreValidationException $exception) {
                        throw ValidationException::withMessages(collect($exception->errors)->mapWithKeys(fn ($messages, $field): array => ["mountedActions.0.data.{$field}" => $messages])->all());
                    } catch (CoreUnavailableException) {
                        Notification::make()->danger()->title(__('app.failed.core'))->send();

                        return;
                    }

                    if ($result['user'] === null) {
                        Notification::make()->success()->title(__('app.people.added_without_login', ['name' => $result['person']->name]))->send();

                        return;
                    }

                    app(AccessManager::class)->rememberProfile($result['user'], AccessProfile::query()->find($data['profile_id'] ?? null));
                    $this->statuses = app(PeopleService::class)->statuses($this->tenant());
                    AccessNotice::send($this->tenant(), $result['user'], $result['invitation_url']);
                }),
            Action::make('teams')
                ->label(__('app.teams.title'))
                ->icon(Heroicon::OutlinedUserGroup)
                ->color('gray')
                ->url(fn (): string => TeamsPage::getUrl()),
        ];
    }

    public function table(Table $table): Table
    {
        $tenant = $this->tenant();

        return $table
            ->query(fn (): Builder => Person::query()->with(['user', 'team'])
                ->when($this->canManageDocuments(), fn (Builder $query) => $query->with(['documents' => fn ($documents) => $documents->current()->with('type')])))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('app.people.name'))
                    ->weight('bold')
                    ->description(fn (Person $record): ?string => collect([static::formatPhone($record->phone), $record->email])->filter()->implode(' · ') ?: null)
                    ->searchable(['name', 'email', 'phone']),
                TextColumn::make('team.name')
                    ->label(__('app.teams.team'))
                    ->placeholder('—')
                    ->visibleFrom('md'),
                TextColumn::make('company_role')
                    ->label(__('app.people.company_role'))
                    ->state(fn (Person $record): ?string => $record->user ? __('app.people.roles.'.($record->user->roleIn($tenant) ?? 'member')) : null)
                    ->placeholder('—')
                    ->badge()
                    ->color(fn (Person $record): string => $record->user?->roleIn($tenant) === User::ROLE_MEMBER ? 'gray' : 'primary')
                    ->visibleFrom('lg'),
                TextColumn::make('systems')
                    ->label(__('app.people.systems'))
                    ->state(fn (Person $record): array => collect(static::modules())
                        ->map(fn (Module $module): ?string => ($label = $module->personRoleLabel($record)) ? $module->name().': '.$label : null)
                        ->filter()->values()->all())
                    ->badge()
                    ->color('success'),
                // Expired or expiring documents (only for who takes care of them).
                TextColumn::make('documents_alert')
                    ->label(__('documents.person.column'))
                    ->state(fn (Person $record): ?string => $this->documentsAlert($record)[0])
                    ->color(fn (Person $record): string => $this->documentsAlert($record)[1])
                    ->badge()
                    ->placeholder('—')
                    ->visible(fn (): bool => $this->canManageDocuments()),
                TextColumn::make('status')
                    ->label(__('app.people.status'))
                    ->state(fn (Person $record): string => $this->statusOf($record))
                    ->formatStateUsing(fn (string $state): string => __('app.people.statuses.'.$state))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'active' => 'success',
                        'invited' => 'warning',
                        'no_login', 'inactive' => 'gray',
                        default => 'danger',
                    }),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('app.people.situation'))
                    ->trueLabel(__('app.people.active_only'))
                    ->falseLabel(__('app.people.inactive_only'))
                    ->default(true),
                SelectFilter::make('team_id')
                    ->label(__('app.teams.team'))
                    ->options(fn (): array => Team::query()->orderBy('name')->pluck('name', 'id')->all()),
            ])
            ->recordActions([
                Action::make('edit')
                    ->label(__('app.people.edit'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->iconButton()
                    ->visible(fn (Person $record): bool => $record->is_active)
                    ->authorize(fn (): bool => static::canAccess() && $this->tenantCanWrite())
                    ->modalWidth('2xl')
                    ->fillForm(fn (Person $record): array => [
                        'with_login' => $record->hasLogin(),
                        'name' => $record->name,
                        'phone' => static::formatPhone($record->phone),
                        'email' => $record->email,
                        'document' => $record->document_type === Person::CPF ? Cpf::format($record->document) : $record->document,
                        'document_type' => $record->document_type === Person::CPF ? null : $record->document_type,
                        'team_id' => $record->team_id,
                        'profile_id' => $record->user ? app(AccessManager::class)->profileOf($record->user)?->getKey() : null,
                        'modules' => collect(static::modules())->map(fn (Module $module): array => $module->personData($record))->all(),
                    ])
                    ->schema(fn (Person $record): array => [
                        Hidden::make('with_login'),
                        TextInput::make('name')
                            ->label(__('app.people.name'))
                            ->required()
                            ->maxLength(255)
                            ->disabled($record->hasLogin())
                            ->helperText($record->hasLogin() ? __('app.people.from_login') : null),
                        ...$this->contactFields($record),
                        ...$this->detailFields($record),
                        $this->profileField()->visible($record->hasLogin() && ! $record->user->canManageTenant()),
                        ...$this->moduleSections(),
                    ])
                    ->action(function (Person $record, array $data, Action $action): void {
                        try {
                            DB::transaction(function () use ($record, $data): void {
                                app(PeopleService::class)->checkModules($record, array_merge($this->details($data), ['document' => $this->canSeeDocuments() ? ($data['document'] ?? null) : $record->document]), (array) ($data['modules'] ?? []), $record->hasLogin());
                                app(PeopleService::class)->updateDetails($record, $this->details($data), $this->canSeeDocuments());
                                app(PeopleService::class)->saveModules($record, (array) ($data['modules'] ?? []));
                            });
                        } catch (PersonRuleException $exception) {
                            Notification::make()->danger()->title($exception->getMessage())->send();
                            $action->halt();
                        }

                        if ($record->user !== null && ! $record->user->canManageTenant()) {
                            app(AccessManager::class)->rememberProfile($record->user, AccessProfile::query()->find($data['profile_id'] ?? null));
                        }

                        Notification::make()->success()->title(__('app.people.saved'))->send();
                    }),
                Action::make('grantLogin')
                    ->label(__('app.people.grant_login'))
                    ->icon(Heroicon::OutlinedKey)
                    ->iconButton()
                    ->visible(fn (Person $record): bool => $record->is_active && ! $record->hasLogin())
                    ->authorize(fn (): bool => static::canAccess() && $this->tenantCanWrite())
                    ->modalDescription(__('app.people.grant_login_help'))
                    ->fillForm(fn (Person $record): array => ['phone' => static::formatPhone($record->phone), 'email' => $record->email])
                    ->schema([
                        TextInput::make('phone')
                            ->label(__('app.people.phone'))
                            ->tel()
                            ->helperText(__('app.people.phone_help'))
                            ->required(fn (Get $get): bool => blank($get('email')))
                            ->maxLength(30),
                        TextInput::make('email')
                            ->label(__('app.people.email'))
                            ->email()
                            ->maxLength(255),
                    ])
                    ->action(function (Person $record, array $data): void {
                        try {
                            $result = app(PeopleService::class)->grantLogin($this->tenant(), $record, $data['phone'] ?? null, $data['email'] ?? null, auth()->user());
                        } catch (CoreValidationException $exception) {
                            throw ValidationException::withMessages(collect($exception->errors)->mapWithKeys(fn ($messages, $field): array => ["mountedActions.0.data.{$field}" => $messages])->all());
                        } catch (CoreUnavailableException) {
                            Notification::make()->danger()->title(__('app.failed.core'))->send();

                            return;
                        }

                        $this->statuses = app(PeopleService::class)->statuses($this->tenant());
                        AccessNotice::send($this->tenant(), $result['user'], $result['invitation_url']);
                    }),
                Action::make('access')
                    ->label(__('app.people.send_access'))
                    ->icon(Heroicon::OutlinedPaperAirplane)
                    ->iconButton()
                    ->visible(fn (Person $record): bool => $record->hasLogin() && $this->statusOf($record) === 'invited')
                    ->authorize(fn (): bool => static::canAccess() && $this->tenantCanWrite())
                    ->requiresConfirmation()
                    ->modalDescription(__('app.people.send_access_help'))
                    ->action(function (Person $record): void {
                        try {
                            $url = app(PeopleService::class)->invitationLink($this->tenant(), $record->user, auth()->user());
                        } catch (CoreUnavailableException) {
                            Notification::make()->danger()->title(__('app.failed.core'))->send();

                            return;
                        }

                        AccessNotice::send($this->tenant(), $record->user, $url);
                    }),
                Action::make('deactivate')
                    ->label(__('app.people.deactivate'))
                    ->icon(Heroicon::OutlinedUserMinus)
                    ->iconButton()
                    ->color('danger')
                    ->visible(fn (Person $record): bool => $record->is_active
                        && ($record->user === null || ($record->user->roleIn($tenant) === User::ROLE_MEMBER && ! $record->user->is(auth()->user()))))
                    ->authorize(fn (): bool => static::canAccess() && $this->tenantCanWrite())
                    ->requiresConfirmation()
                    ->modalDescription(__('app.people.deactivate_help'))
                    ->action(function (Person $record): void {
                        try {
                            app(PeopleService::class)->deactivate($this->tenant(), $record);
                        } catch (CoreUnavailableException) {
                            Notification::make()->danger()->title(__('app.failed.core'))->send();

                            return;
                        }

                        Notification::make()->success()->title(__('app.people.deactivated'))->send();
                    }),
                // Documents with a validity (driver's license, medical exam, trainings): owners, administrators and HR.
                Action::make('documents')
                    ->label(__('documents.person.open'))
                    ->icon(Heroicon::OutlinedIdentification)
                    ->iconButton()
                    ->visible(fn (): bool => app(PersonDocumentService::class)->canManage(auth()->user()))
                    ->url(fn (Person $record): string => PersonDocumentsPage::getUrl(['pessoa' => $record->getKey()])),
                // LGPD (art. 18): the company, controller of its employees' data, hands the person a copy of it.
                Action::make('exportData')
                    ->label(__('app.people.export_data'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->iconButton()
                    ->authorize(fn (): bool => static::canAccess())
                    ->requiresConfirmation()
                    ->modalDescription(__('app.people.export_data_help'))
                    ->action(function (Person $record) {
                        $data = [
                            'generated_at_utc' => now()->toIso8601ZuluString(),
                            'company' => $this->tenant()->name,
                            'person' => [
                                'name' => $record->name, 'phone' => $record->phone, 'email' => $record->email,
                                'document' => app(PeopleService::class)->canSeeDocuments(auth()->user(), $this->tenant()) ? $record->document : null,
                                'team' => $record->team?->name, 'active' => (bool) $record->is_active, 'has_login' => $record->hasLogin(),
                            ],
                            'systems' => collect(static::modules())->mapWithKeys(fn (Module $module): array => [$module->key() => $module->exportPerson($record)])
                                ->filter(fn (array $part): bool => $part !== [])->all(),
                            // Driver's license, medical exam, trainings (health data only for who may see it; no files).
                            'documents' => app(PersonDocumentService::class)->exportPerson($record, auth()->user()),
                        ];

                        return response()->streamDownload(
                            fn () => print (json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                            'dados-'.str($record->name)->slug().'-'.now()->format('Y-m-d').'.json',
                            ['Content-Type' => 'application/json; charset=utf-8'],
                        );
                    }),
                Action::make('reactivate')
                    ->label(__('app.people.reactivate'))
                    ->icon(Heroicon::OutlinedArrowUturnLeft)
                    ->iconButton()
                    ->visible(fn (Person $record): bool => ! $record->is_active)
                    ->authorize(fn (): bool => static::canAccess() && $this->tenantCanWrite())
                    ->requiresConfirmation()
                    ->modalDescription(__('app.people.reactivate_help'))
                    ->action(function (Person $record): void {
                        $record->forceFill(['is_active' => true])->save();
                        Notification::make()->success()->title(__('app.people.saved'))->send();
                    }),
            ])
            ->emptyStateHeading(__('app.people.empty'));
    }

    /**
     * Mobile and e-mail. With a login they come from the Core (changed by the person in their account).
     *
     * @return array<int, mixed>
     */
    protected function contactFields(?Person $person = null): array
    {
        $locked = $person?->hasLogin() ?? false;

        return [
            Grid::make(['default' => 1, 'sm' => 2])->components([
                TextInput::make('phone')
                    ->label(__('app.people.phone'))
                    ->tel()
                    ->helperText(fn (Get $get): string => $get('with_login') ? __('app.people.phone_help') : __('app.people.phone_help_no_login'))
                    ->required(fn (Get $get): bool => ! $locked && (bool) $get('with_login') && blank($get('email')))
                    ->disabled($locked)
                    ->maxLength(30),
                TextInput::make('email')
                    ->label(__('app.people.email'))
                    ->email()
                    ->helperText(fn (Get $get): ?string => $get('with_login') ? __('app.people.email_help') : null)
                    ->disabled($locked)
                    ->maxLength(255),
            ]),
        ];
    }

    /**
     * Document (CPF in Brazil, a generic one elsewhere; only for who may see it) and team.
     *
     * @return array<int, mixed>
     */
    protected function detailFields(?Person $person = null): array
    {
        $brazil = Country::isBrazil();

        $document = $brazil
            ? [TextInput::make('document')
                ->label(__('app.people.cpf'))
                ->placeholder('000.000.000-00')
                ->rule(new Cpf)
                ->rule(fn (): \Closure => function (string $attribute, mixed $value, \Closure $fail) use ($person): void {
                    $taken = Person::query()->where('document_type', Person::CPF)->where('document', Cpf::digits((string) $value))
                        ->when($person, fn (Builder $query) => $query->whereKeyNot($person->getKey()))->exists();

                    if ($taken) {
                        $fail(__('app.validation.cpf_taken'));
                    }
                })
                ->maxLength(14)]
            : [Grid::make(['default' => 1, 'sm' => 2])->components([
                TextInput::make('document_type')
                    ->label(__('app.people.document_type'))
                    ->placeholder(__('app.people.document_type_placeholder'))
                    ->maxLength(20),
                TextInput::make('document')
                    ->label(__('app.people.document'))
                    ->maxLength(40),
            ])];

        return [
            Grid::make(['default' => 1, 'sm' => 2])->components([
                ...($this->canSeeDocuments() ? $document : []),
                Select::make('team_id')
                    ->label(__('app.teams.team'))
                    ->options(fn (): array => Team::query()->where('is_active', true)->orderBy('name')->pluck('name', 'id')->all())
                    ->searchable()
                    ->createOptionForm([TextInput::make('name')->label(__('app.teams.name'))->required()->maxLength(80)])
                    ->createOptionUsing(fn (array $data): int => Team::query()->firstOrCreate(['name' => trim($data['name'])])->getKey()),
            ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{name: string, phone: ?string, email: ?string, document: ?string, document_type: ?string, team_id: ?int}
     */
    protected function details(array $data): array
    {
        return [
            'name' => (string) ($data['name'] ?? ''),
            'phone' => $data['phone'] ?? null,
            'email' => $data['email'] ?? null,
            'document' => $data['document'] ?? null,
            'document_type' => $data['document_type'] ?? null,
            'team_id' => filled($data['team_id'] ?? null) ? (int) $data['team_id'] : null,
        ];
    }

    protected function canManageDocuments(): bool
    {
        return app(PersonDocumentService::class)->canManage(auth()->user());
    }

    /**
     * "1 vencido" (red) or "2 vencendo" (yellow) from the person's current documents.
     *
     * @return array{0: ?string, 1: string}
     */
    protected function documentsAlert(Person $person): array
    {
        if (! $person->relationLoaded('documents')) {
            return [null, 'gray'];
        }

        $statuses = $person->documents->filter(fn (PersonDocument $document): bool => $document->type->is_active)
            ->map(fn (PersonDocument $document): DocumentStatus => $document->status());
        $overdue = $statuses->filter(fn (DocumentStatus $status): bool => $status === DocumentStatus::Overdue)->count();
        $soon = $statuses->filter(fn (DocumentStatus $status): bool => $status === DocumentStatus::Soon)->count();

        return match (true) {
            $overdue > 0 => [trans_choice('documents.attention.overdue', $overdue, ['count' => $overdue]), 'danger'],
            $soon > 0 => [trans_choice('documents.attention.soon', $soon, ['count' => $soon]), 'warning'],
            $person->documents->isNotEmpty() => [__('documents.status.ok'), 'success'],
            default => [null, 'gray'],
        };
    }

    protected function canSeeDocuments(): bool
    {
        return app(PeopleService::class)->canSeeDocuments(auth()->user(), $this->tenant());
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
     * One section per system the company uses (e.g. "Frota: role, vehicles, driver's license"), only for who
     * manages that system (owners and administrators see all). Sections that only make sense with a login
     * (e.g. Frota) hide when the person has none.
     *
     * @return array<int, Section>
     */
    protected function moduleSections(): array
    {
        $user = auth()->user();

        return collect(static::modules())
            ->filter(fn (Module $module): bool => $module->personFields() !== [] && ($user->canManageTenant() || $module->managesPeople($user)))
            ->map(fn (Module $module, string $key): Section => Section::make($module->name())
                ->icon($module->icon())
                ->statePath("modules.{$key}")
                ->visible(fn (Get $get): bool => ! $module->personNeedsLogin() || (bool) $get('with_login'))
                ->components($module->personFields()))
            ->values()
            ->all();
    }

    protected function statusOf(Person $person): string
    {
        if (! $person->is_active) {
            return 'inactive';
        }

        if ($person->user === null) {
            return 'no_login';
        }

        return $this->statuses[$person->user->core_id] ?? ($person->user->last_login_at !== null ? 'active' : 'invited');
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
