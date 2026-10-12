<?php

namespace App\People;

use App\Auth\CoreSignIn;
use App\Licensing\CoreClient;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\ModuleRegistry;
use Illuminate\Support\Facades\DB;

/**
 * People of the company, managed inside the app ("People" screen): the manager adds a driver or a mechanic
 * here and never needs the customer area. The Core creates the login and the invitation; the link can be
 * sent by e-mail (when there is one) or by WhatsApp.
 */
class PeopleService
{
    public function __construct(
        protected CoreClient $core,
        protected CoreSignIn $signIn,
        protected ModuleRegistry $modules,
    ) {}

    /**
     * Adds a person and, for each module, what they do there (role, vehicles...).
     *
     * @param  array<string, array<string, mixed>>  $modules  data of each module's section, by module key
     * @return array{user: User, invitation_url: ?string}
     */
    public function add(Tenant $tenant, string $name, ?string $phone, ?string $email, array $modules, User $by): array
    {
        $answer = $this->core->addMember($tenant->subdomain, array_filter([
            'name' => $name,
            'phone' => $phone,
            'email' => $email,
            'next' => $tenant->url('/auth/login'),
            'invited_by' => $by->name,
        ]));

        return DB::transaction(function () use ($answer, $tenant, $modules): array {
            $user = $this->signIn->syncMember($answer['member'], $tenant);
            $this->saveModules($user, $modules);

            return ['user' => $user, 'invitation_url' => $answer['invitation_url'] ?? null];
        });
    }

    /**
     * @param  array<string, array<string, mixed>>  $modules
     */
    public function saveModules(User $user, array $modules): void
    {
        foreach ($this->modules->all() as $key => $module) {
            if (array_key_exists($key, $modules)) {
                $module->savePerson($user, (array) $modules[$key]);
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
     * Takes the person out of the company (Core and here). Their history stays.
     */
    public function remove(Tenant $tenant, User $user): void
    {
        $this->core->removeMember($tenant->subdomain, $user->core_id);

        DB::transaction(function () use ($tenant, $user): void {
            foreach ($this->modules->all() as $module) {
                $module->removePerson($user);
            }

            $user->tenants()->detach($tenant->getKey());
        });
    }

    /**
     * Status of each person from the Core (invited / active / blocked), by Core user id.
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
}
