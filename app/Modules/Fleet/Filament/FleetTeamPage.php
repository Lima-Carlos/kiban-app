<?php

namespace App\Modules\Fleet\Filament;

use App\Models\User;
use App\Modules\Fleet\Enums\FleetRole;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\FleetMember;
use App\Modules\Fleet\Services\FleetAccess;
use App\Modules\ModuleRegistry;
use App\Tenancy\TenantContext;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Fleet team": the role of each person of the company in the Frota. Owners and administrators are always
 * managers; drivers come from the driver records (same e-mail); here the manager gives "manager",
 * "mechanic" or "viewer" to the others. Only people who already signed in once appear.
 */
class FleetTeamPage extends Page implements HasActions, HasSchemas, HasTable
{
    use InteractsWithActions, InteractsWithSchemas, InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedIdentification;

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'frota/equipe';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->hasModule(FleetModule::KEY) && app(FleetAccess::class)->isManager();
    }

    public static function getNavigationGroup(): ?string
    {
        return app(ModuleRegistry::class)->get(FleetModule::KEY)->name();
    }

    public static function getNavigationLabel(): string
    {
        return __('fleet.team.title');
    }

    public function getTitle(): string
    {
        return __('fleet.team.title');
    }

    public function getSubheading(): ?string
    {
        return __('fleet.team.help');
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        $tenant = app(TenantContext::class)->get();
        $access = app(FleetAccess::class);

        return $table
            ->query(fn (): Builder => User::query()->whereHas('tenants', fn (Builder $query) => $query->whereKey($tenant->getKey())))
            ->defaultSort('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('fleet.fields.name'))
                    ->description(fn (User $record): string => $record->email)
                    ->searchable(['name', 'email']),
                TextColumn::make('fleet_role')
                    ->label(__('fleet.team.role'))
                    ->state(fn (User $record): FleetRole => $access->fleetRole($record))
                    ->badge()
                    ->description(fn (User $record): ?string => match (true) {
                        $record->canManageTenant() => __('fleet.team.company_admin'),
                        $access->fleetRole($record) === FleetRole::Driver => __('fleet.team.linked_driver', ['name' => $access->driverFor($record)?->name]),
                        default => null,
                    }),
                TextColumn::make('last_login_at')
                    ->label(__('fleet.team.last_access'))
                    ->dateTime(__('app.datetime_format'))
                    ->placeholder('—')
                    ->visibleFrom('md'),
            ])
            ->recordActions([
                Action::make('changeRole')
                    ->label(__('fleet.team.change_role'))
                    ->icon(Heroicon::OutlinedPencilSquare)
                    ->visible(fn (User $record): bool => ! $record->canManageTenant())
                    ->authorize(fn (): bool => static::canAccess())
                    ->fillForm(fn (User $record): array => ['role' => FleetMember::query()->where('user_id', $record->getKey())->value('role')?->value ?? 'default'])
                    ->schema([
                        Select::make('role')
                            ->label(__('fleet.team.role'))
                            ->options(['default' => __('fleet.team.default_role')] + collect(FleetRole::assignable())->mapWithKeys(fn (FleetRole $role): array => [$role->value => $role->getLabel()])->all())
                            ->helperText(__('fleet.team.default_help'))
                            ->required(),
                    ])
                    ->action(function (User $record, array $data): void {
                        if ($data['role'] === 'default') {
                            FleetMember::query()->where('user_id', $record->getKey())->delete();
                        } else {
                            FleetMember::query()->updateOrCreate(['user_id' => $record->getKey()], ['role' => FleetRole::from($data['role'])]);
                        }

                        Notification::make()->success()->title(__('fleet.notifications.saved'))->send();
                    }),
            ])
            ->emptyStateHeading(__('fleet.team.empty'));
    }
}
