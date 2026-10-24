<?php

namespace App\People;

use App\Auth\CoreSignIn;
use App\Licensing\CoreClient;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Module;
use App\Modules\ModuleRegistry;
use App\Support\Country;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * People of the company, managed inside the app ("People" screen): the manager adds a driver, a mechanic
 * or an employee here and never needs the customer area. With a login, the Core creates it and the
 * invitation (sent by e-mail or WhatsApp); without one, the person exists only here (e.g. clocks in on the
 * company tablet) and can get the login later, keeping the same record and history.
 */
class PeopleService
{
    public function __construct(
        protected CoreClient $core,
        protected CoreSignIn $signIn,
        protected ModuleRegistry $modules,
    ) {}

    /**
     * Adds a person and, for each module, what they do there (role, vehicles, employee data...).
     *
     * @param  array{name: string, phone?: ?string, email?: ?string, document?: ?string, document_type?: ?string, team_id?: ?int}  $details
     * @param  array<string, array<string, mixed>>  $modules  data of each module's section, by module key
     * @return array{person: Person, user: ?User, invitation_url: ?string}
     */
    public function add(Tenant $tenant, array $details, array $modules, User $by, bool $withLogin = true): array
    {
        // The modules' rules first: a refusal must not leave a login behind in the Core.
        $this->checkModules(null, $details, $modules, $withLogin);

        $answer = $withLogin ? $this->createLogin($tenant, $details['name'], $details['phone'] ?? null, $details['email'] ?? null, $by) : null;

        return DB::transaction(function () use ($answer, $tenant, $details, $modules): array {
            if ($answer !== null) {
                $user = $this->signIn->syncMember($answer['member'], $tenant);
                $person = Person::forUser($user);
            } else {
                $user = null;
                $person = new Person([
                    'name' => $details['name'],
                    'phone' => Country::phone($details['phone'] ?? null),
                    'email' => $details['email'] ?? null,
                ]);
            }

            $person->setDocument($details['document'] ?? null, $details['document_type'] ?? null);
            $person->team_id = $details['team_id'] ?? null;
            $person->save();
            $this->saveModules($person, $modules);

            return ['person' => $person, 'user' => $user, 'invitation_url' => $answer['invitation_url'] ?? null];
        });
    }

    /**
     * Gives a login to someone who had none (the history stays with the same record).
     *
     * @return array{person: Person, user: User, invitation_url: ?string}
     */
    public function grantLogin(Tenant $tenant, Person $person, ?string $phone, ?string $email, User $by): array
    {
        $answer = $this->createLogin($tenant, $person->name, $phone, $email, $by);

        return DB::transaction(function () use ($answer, $tenant, $person): array {
            $user = $this->signIn->syncMember($answer['member'], $tenant);
            // syncMember may have created a record for this login; the existing one wins.
            Person::query()->where('user_id', $user->getKey())->whereKeyNot($person->getKey())->delete();
            $person->forceFill(['user_id' => $user->getKey(), 'phone' => $user->phone, 'email' => $user->email])->save();

            foreach ($this->modules->all() as $module) {
                $module->personGotLogin($person);
            }

            return ['person' => $person, 'user' => $user, 'invitation_url' => $answer['invitation_url'] ?? null];
        });
    }

    /**
     * Name, mobile, e-mail, document and team. With a login, name, mobile and e-mail follow the Core.
     *
     * @param  array{name?: ?string, phone?: ?string, email?: ?string, document?: ?string, document_type?: ?string, team_id?: ?int}  $details
     */
    public function updateDetails(Person $person, array $details, bool $withDocument): void
    {
        if (! $person->hasLogin()) {
            $person->fill([
                'name' => $details['name'] ?? $person->name,
                'phone' => Country::phone($details['phone'] ?? null),
                'email' => $details['email'] ?? null,
            ]);
        }

        if ($withDocument) {
            $person->setDocument($details['document'] ?? null, $details['document_type'] ?? null);
        }

        $person->team_id = $details['team_id'] ?? null;
        $person->save();
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, array<string, mixed>>  $modules
     */
    public function checkModules(?Person $person, array $details, array $modules, bool $withLogin): void
    {
        foreach ($this->modules->enabledFor(app(TenantContext::class)->get()) as $key => $module) {
            if (array_key_exists($key, $modules) && ($withLogin || ! $module->personNeedsLogin())) {
                $module->checkPerson($person, (array) $modules[$key], $details);
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     */
    public function saveModules(Person $person, array $modules): void
    {
        foreach ($this->modules->all() as $key => $module) {
            if (array_key_exists($key, $modules) && ($person->hasLogin() || ! $module->personNeedsLogin())) {
                $module->savePerson($person, (array) $modules[$key]);
            }
        }
    }

    /**
     * A new invitation link (the previous one stops working). null when the person already has a password.
     */
    public function invitationLink(Tenant $tenant, User $user, User $by): ?string
    {
        return $this->core->memberInvitation($tenant->subdomain, $user->core_id, $tenant->url('/auth/login'), $by->name)['invitation_url'] ?? null;
    }

    /**
     * "Deactivate": the person leaves the company (and loses the login to it in the Core).
     * Their record and history stay, inactive.
     */
    public function deactivate(Tenant $tenant, Person $person): void
    {
        $user = $person->user;

        if ($user !== null) {
            $this->core->removeMember($tenant->subdomain, $user->core_id);
        }

        DB::transaction(function () use ($tenant, $person, $user): void {
            foreach ($this->modules->all() as $module) {
                $module->removePerson($person);
            }

            $user?->tenants()->detach($tenant->getKey());
            $person->forceFill(['is_active' => false, 'user_id' => null])->save();
        });
    }

    /**
     * Everyone of the company with a login gets a record (e.g. joined through the customer area).
     */
    public function ensureRecords(Tenant $tenant): void
    {
        $missing = $tenant->users()->whereNotIn('users.id', Person::query()->whereNotNull('user_id')->select('user_id'))->get();

        foreach ($missing as $user) {
            Person::forUser($user);
        }
    }

    /** CPF and other documents: owners, administrators and who a module allows (e.g. the time clock manager). */
    public function canSeeDocuments(User $user, Tenant $tenant): bool
    {
        return $user->canManageTenant()
            || collect($this->modules->enabledFor($tenant))->contains(fn (Module $module): bool => $module->seesPersonDocuments($user));
    }

    /**
     * Status of each login from the Core (invited / active / blocked), by Core user id.
     * Empty when the Core cannot be reached (the screen still works).
     *
     * @return array<int, string>
     */
    public function statuses(Tenant $tenant): array
    {
        try {
            return collect($this->core->members($tenant->subdomain))->mapWithKeys(fn (array $member): array => [(int) $member['id'] => (string) $member['status']])->all();
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * "Send by WhatsApp" link with a ready message (no paid integration: opens the manager's WhatsApp).
     */
    public static function whatsappUrl(?string $phone, string $message): string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);

        return 'https://wa.me/'.($digits !== '' ? $digits : '').'?text='.rawurlencode($message);
    }

    /**
     * @return array{member: array<string, mixed>, invitation_url?: ?string}
     */
    protected function createLogin(Tenant $tenant, string $name, ?string $phone, ?string $email, User $by): array
    {
        return $this->core->addMember($tenant->subdomain, array_filter([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'next' => $tenant->url('/auth/login'),
            'invited_by' => $by->name,
        ]));
    }
}
