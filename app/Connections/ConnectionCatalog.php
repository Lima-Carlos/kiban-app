<?php

namespace App\Connections;

use App\Access\AccessManager;
use App\Access\CurrentModule;
use App\Filament\Pages\DocumentsPage;
use App\Filament\Pages\Home;
use App\Filament\Pages\PeoplePage;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Filament\Dashboard\FieldDashboard;
use App\Modules\Field\Filament\MyOrdersPage;
use App\Modules\Fleet\Filament\Inspections\InspectionResource;
use App\Modules\Fleet\Filament\Vehicles\VehicleResource;
use App\Modules\Fleet\FleetModule;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\Modules\TimeClock\Filament\Punches\PunchResource;
use App\Modules\TimeClock\Filament\PunchPage;
use App\Modules\TimeClock\Services\TimeClockAccess;
use App\Modules\TimeClock\TimeClockModule;
use App\People\Documents\PersonDocumentService;
use App\Tenancy\TenantContext;
use Carbon\CarbonImmutable;

/**
 * The connections between the Kiban systems ("your systems now work together"): the list, which ones work for a
 * company (it has the systems), which ones matter to a person (role) and which ones the person still has to see.
 *
 * Rule of the project: every new feature that links two systems gets an entry here (key, systems, who it matters
 * to, "See where" and the texts in lang/<locale>/connections.php). The window and the "Systems together" page read
 * only from here.
 */
class ConnectionCatalog
{
    /** Session key: "Got it" without "Do not show again" hides the window until the next day or sign-in. */
    public const SNOOZE = 'kiban.connections.snoozed';

    /** @var array<string, Connection>|null */
    protected ?array $connections = null;

    public function __construct(
        protected ModuleRegistry $modules,
        protected AccessManager $access,
        protected TenantContext $context,
    ) {}

    /**
     * Every connection, in the order they are shown.
     *
     * @return array<string, Connection>
     */
    public function all(): array
    {
        if ($this->connections !== null) {
            return $this->connections;
        }

        $field = FieldModule::KEY;
        $fleet = FleetModule::KEY;
        $clock = TimeClockModule::KEY;

        $list = [
            // Campo + Ponto (O7): "you have not clocked in yet" at the first order, "clock out" after the last one.
            new Connection('punch_reminder', [$field, $clock], 'heroicon-o-finger-print',
                audience: fn (User $user): bool => ($this->is($user, $field, 'technician') && $this->clocksIn($user))
                    || $this->is($user, $field, 'manager') || $this->is($user, $clock, 'manager'),
                url: fn (User $user): string => $this->is($user, $field, 'technician') ? MyOrdersPage::getUrl() : PunchResource::getUrl('index'),
            ),
            // Campo + Ponto: hours on the time clock × hours at the customers, per technician.
            new Connection('utilization', [$field, $clock], 'heroicon-o-chart-pie',
                audience: fn (User $user): bool => $this->is($user, $field, 'manager'),
                url: fn (): string => FieldDashboard::getUrl(),
            ),
            // Campo + Frota: the km typed at arrival goes to the vehicle's km history.
            new Connection('field_km_to_fleet', [$field, $fleet], 'heroicon-o-truck',
                audience: fn (User $user): bool => ($this->is($user, $field, 'technician') && $this->is($user, $fleet, 'driver'))
                    || $this->is($user, $fleet, 'manager', 'viewer') || $this->is($user, $field, 'manager'),
                url: fn (User $user): string => $this->is($user, $field, 'technician') ? MyOrdersPage::getUrl() : VehicleResource::getUrl('index'),
            ),
            // Campo + Frota: km driven to the customer × the vehicle's cost per km = estimated travel cost.
            new Connection('travel_cost', [$field, $fleet], 'heroicon-o-banknotes',
                audience: fn (User $user): bool => $this->is($user, $field, 'manager'),
                url: fn (): string => FieldDashboard::getUrl(),
            ),
            // Frota + Ponto: after clocking in, "do the vehicle's inspection now" (only a suggestion).
            new Connection('inspection_after_punch', [$fleet, $clock], 'heroicon-o-clipboard-document-check',
                audience: fn (User $user): bool => ($this->is($user, $fleet, 'driver') && $this->clocksIn($user)) || $this->is($user, $fleet, 'manager'),
                url: fn (User $user): string => $this->clocksIn($user) && $this->is($user, $fleet, 'driver') ? PunchPage::getUrl() : InspectionResource::getUrl('index'),
            ),
            // Ponto + Frota: the driver's license HR registers in People warns the Frota and updates the driver.
            new Connection('documents_license', [$clock, $fleet], 'heroicon-o-identification',
                audience: fn (User $user): bool => $this->is($user, $clock, 'manager') || $this->is($user, $fleet, 'manager', 'viewer'),
                url: fn (User $user): string => $this->documentsUrl($user, $fleet),
            ),
            // Ponto + Campo: the safety trainings (NR) HR registers warn the office when scheduling a technician.
            new Connection('documents_training', [$clock, $field], 'heroicon-o-academic-cap',
                audience: fn (User $user): bool => $this->is($user, $clock, 'manager') || $this->is($user, $field, 'manager', 'dispatcher'),
                url: fn (User $user): string => $this->documentsUrl($user, $field),
            ),
            // Any pair: one register of people, one login, profiles that give two systems at once.
            new Connection('one_person', [$fleet, $clock, $field], 'heroicon-o-users',
                audience: fn (User $user): bool => $user->canManageTenant()
                    || collect($this->modules->enabledFor($this->context->get()))->contains(fn (Module $module): bool => $module->managesPeople($user)),
                url: fn (): string => PeoplePage::getUrl(),
                needs: 2,
            ),
        ];

        return $this->connections = collect($list)->keyBy(fn (Connection $connection): string => $connection->key)->all();
    }

    public function get(string $key): ?Connection
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * Connections that work for the company (it has the systems).
     *
     * @return array<string, Connection>
     */
    public function active(?Tenant $tenant = null): array
    {
        $enabled = $this->enabled($tenant);

        return array_filter($this->all(), fn (Connection $connection): bool => $connection->activeWith($enabled));
    }

    /**
     * Active connections that matter to the person: their role in the systems involved fits the connection's
     * audience (e.g. the HR manager sees that the driver's license they register warns the Frota, even without
     * opening the Frota; a technician only sees what changes something on their own phone).
     *
     * @return array<string, Connection>
     */
    public function relevant(User $user): array
    {
        return array_filter($this->active(), fn (Connection $connection): bool => $connection->matters($user));
    }

    /**
     * Relevant connections the person has not dismissed ("Do not show again") in this company.
     *
     * @return array<string, Connection>
     */
    public function pending(User $user): array
    {
        $relevant = $this->relevant($user);

        if ($relevant === []) {
            return [];
        }

        $dismissed = ConnectionDismissal::query()->where('user_id', $user->getKey())->pluck('connection')->all();

        return array_diff_key($relevant, array_flip($dismissed));
    }

    /**
     * The window shows up: something pending and not hidden today ("Got it" without the box).
     *
     * @return array<string, Connection>
     */
    public function forWindow(User $user): array
    {
        if (session(self::SNOOZE.'.'.$this->context->get()->getKey()) === $this->today()) {
            return [];
        }

        return $this->pending($user);
    }

    /**
     * "Got it": hides the window until tomorrow (or the next sign-in); with "Do not show again", these
     * connections never show up again for this person in this company.
     *
     * @param  array<int, string>  $keys
     */
    public function dismiss(User $user, array $keys, bool $forever): void
    {
        session()->put(self::SNOOZE.'.'.$this->context->get()->getKey(), $this->today());

        if (! $forever) {
            return;
        }

        foreach (array_intersect($keys, array_keys($this->all())) as $key) {
            ConnectionDismissal::query()->firstOrCreate(['user_id' => $user->getKey(), 'connection' => $key]);
        }
    }

    /**
     * Every connection marked as seen for these people (e.g. the accounts used to record the videos).
     *
     * @param  iterable<User>  $users
     */
    public function dismissAllFor(iterable $users): void
    {
        foreach ($users as $user) {
            foreach (array_keys($this->all()) as $key) {
                ConnectionDismissal::query()->firstOrCreate(['user_id' => $user->getKey(), 'connection' => $key]);
            }
        }
    }

    /**
     * What another system would add: for each system the company does not have, the connections it would
     * switch on ("With the Ponto, you would also have: ...").
     *
     * @return array<string, array{module: Module, connections: array<string, Connection>}>
     */
    public function potential(?Tenant $tenant = null): array
    {
        $enabled = $this->enabled($tenant);
        $result = [];

        foreach ($this->sellable() as $key => $module) {
            if (in_array($key, $enabled, true)) {
                continue;
            }

            $more = array_filter($this->all(), fn (Connection $connection): bool => ! $connection->activeWith($enabled) && $connection->activeWith([...$enabled, $key]));

            if ($more !== []) {
                $result[$key] = ['module' => $module, 'connections' => $more];
            }
        }

        return $result;
    }

    /**
     * Names of the systems of a connection ("Campo + Ponto"), only those the company has when it has more.
     *
     * @param  array<int, string>|null  $enabled
     */
    public function systemsLabel(Connection $connection, ?array $enabled = null): string
    {
        $keys = $enabled !== null && $connection->activeWith($enabled) ? array_values(array_intersect($connection->modules, $enabled)) : $connection->modules;
        $all = $this->modules->all();

        return collect($keys)->filter(fn (string $key): bool => isset($all[$key]))->map(fn (string $key): string => $all[$key]->name())->implode(' + ');
    }

    /**
     * Systems the company has (product codes).
     *
     * @return array<int, string>
     */
    public function enabled(?Tenant $tenant = null): array
    {
        return array_keys($this->modules->enabledFor($tenant ?? $this->context->get()));
    }

    /**
     * The systems sold (the demonstration module is left out).
     *
     * @return array<string, Module>
     */
    protected function sellable(): array
    {
        $keys = collect($this->all())->flatMap(fn (Connection $connection): array => $connection->modules)->unique()->all();

        return array_intersect_key($this->modules->all(), array_flip($keys));
    }

    /** The company's day, for "Got it" (hidden until tomorrow). */
    protected function today(): string
    {
        $tenant = $this->context->get();

        return CarbonImmutable::now($tenant->license()->tenant('timezone') ?: config('kiban.timezone'))->toDateString();
    }

    /** The person's role in a system is one of these. */
    protected function is(User $user, string $module, string ...$roles): bool
    {
        return in_array($this->access->role($user, $module), $roles, true);
    }

    /** The person clocks in (an active employee of the time clock). */
    protected function clocksIn(User $user): bool
    {
        return app(TimeClockAccess::class)->employeeFor($user) !== null;
    }

    /** The documents screen for who keeps the documents; the system's start page ("Attention") for the others. */
    protected function documentsUrl(User $user, string $module): string
    {
        return app(PersonDocumentService::class)->canManage($user) ? DocumentsPage::getUrl() : Home::getUrl([CurrentModule::QUERY => $module]);
    }
}
