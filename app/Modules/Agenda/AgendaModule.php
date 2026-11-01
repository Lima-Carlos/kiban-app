<?php

namespace App\Modules\Agenda;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Agenda\Enums\AgendaRole;
use App\Modules\Agenda\Filament\AgendaSettingsPage;
use App\Modules\Agenda\Filament\Appointments\AppointmentResource;
use App\Modules\Agenda\Filament\CalendarPage;
use App\Modules\Agenda\Filament\Clients\ClientResource;
use App\Modules\Agenda\Filament\CommissionsPage;
use App\Modules\Agenda\Filament\Packages\ClientPackageResource;
use App\Modules\Agenda\Filament\Packages\PackageResource;
use App\Modules\Agenda\Filament\Professionals\ProfessionalResource;
use App\Modules\Agenda\Filament\RemindersPage;
use App\Modules\Agenda\Filament\Services\ServiceResource;
use App\Modules\Agenda\Filament\TodayWidget;
use App\Modules\Agenda\Models\AgendaSetting;
use App\Modules\Agenda\Models\Appointment;
use App\Modules\Agenda\Models\Client;
use App\Modules\Agenda\Models\ClientPackage;
use App\Modules\Agenda\Models\Package;
use App\Modules\Agenda\Models\Professional;
use App\Modules\Agenda\Models\Service;
use App\Modules\Agenda\Policies\AppointmentPolicy;
use App\Modules\Agenda\Policies\ClientPackagePolicy;
use App\Modules\Agenda\Policies\ClientPolicy;
use App\Modules\Agenda\Policies\PackagePolicy;
use App\Modules\Agenda\Policies\ProfessionalPolicy;
use App\Modules\Agenda\Policies\ServicePolicy;
use App\Modules\Agenda\Services\AgendaAccess;
use App\Modules\Agenda\Services\AgendaPeople;
use App\Modules\Agenda\Services\AgendaReports;
use App\Modules\Module;
use App\Modules\Step;
use App\People\Person;

/**
 * Kiban Core Agenda ("Kiban Core Appointments" in English): online appointments, first sector pet shops (bath and
 * grooming, with pick-up and drop-off). Clients are the company's customers (the same register as the Campo) with
 * their pets; professionals are people of the "People" screen (hours from the Ponto when there is one); the public
 * booking page, the client's link, WhatsApp buttons, packages, no-shows and commissions.
 * Product code in the Core: "agenda"; plan limit: active professionals.
 */
class AgendaModule extends Module
{
    public const KEY = 'agenda';

    /** Plan limit in the Core: number of active professionals. */
    public const LIMIT_PROFESSIONALS = 'professionals';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('agenda.module');
    }

    public function icon(): string
    {
        return 'heroicon-o-calendar-days';
    }

    public function steps(): array
    {
        return [
            new Step(
                key: 'business',
                labelKey: 'agenda.steps.business',
                descriptionKey: 'agenda.steps.business_help',
                done: fn (Tenant $tenant): bool => AgendaSetting::query()->whereNotNull('configured_at')->exists(),
                url: fn (): string => AgendaSettingsPage::getUrl(),
            ),
            new Step(
                key: 'services',
                labelKey: 'agenda.steps.services',
                descriptionKey: 'agenda.steps.services_help',
                requires: ['business'],
                done: fn (Tenant $tenant): bool => Service::query()->active()->where('is_addon', false)->exists(),
                url: fn (): string => ServiceResource::getUrl('index'),
            ),
            new Step(
                key: 'professionals',
                labelKey: 'agenda.steps.professionals',
                descriptionKey: 'agenda.steps.professionals_help',
                done: fn (Tenant $tenant): bool => Professional::query()->active()->exists(),
                url: fn (): string => ProfessionalResource::getUrl('index'),
            ),
        ];
    }

    public function resources(): array
    {
        return [AppointmentResource::class, ClientResource::class, ServiceResource::class, ProfessionalResource::class, PackageResource::class, ClientPackageResource::class];
    }

    public function pages(): array
    {
        return [CalendarPage::class, RemindersPage::class, CommissionsPage::class, AgendaSettingsPage::class];
    }

    /** The desk, managers and viewers: today's counts on the start page. */
    public function homeWidgets(User $user): array
    {
        return in_array(app(AgendaAccess::class)->role($user), [AgendaRole::Manager, AgendaRole::Desk, AgendaRole::Viewer], true) ? [TodayWidget::class] : [];
    }

    /** The desk: packages about to expire with sessions left, clients who miss a lot and are coming soon. */
    public function attention(User $user): array
    {
        return app(AgendaAccess::class)->runsTheDesk($user) ? app(AgendaReports::class)->attention() : [];
    }

    /** The professional: today's appointments and the button to the agenda. */
    public function personalHome(User $user): ?array
    {
        $professional = app(AgendaAccess::class)->professionalFor($user);

        if ($professional === null || app(AgendaAccess::class)->runsTheDesk($user)) {
            return null;
        }

        return [
            'view' => 'agenda.home.professional',
            'data' => ['appointments' => CalendarPage::dayOf($professional), 'url' => CalendarPage::getUrl()],
        ];
    }

    public function showsSetupTo(User $user): bool
    {
        return app(AgendaAccess::class)->isManager($user);
    }

    public function roles(): array
    {
        return collect(AgendaRole::assignable())->mapWithKeys(fn (AgendaRole $role): array => [$role->value => $role->getLabel()])->all();
    }

    /** A professional with a login sees their own agenda by themselves. */
    public function implicitRole(User $user): ?string
    {
        return app(AgendaAccess::class)->professionalFor($user) !== null ? AgendaRole::Professional->value : null;
    }

    public function managesPeople(User $user): bool
    {
        return app(AgendaAccess::class)->isManager($user);
    }

    public function personFields(): array
    {
        return app(AgendaPeople::class)->fields();
    }

    public function personData(Person $person): array
    {
        return app(AgendaPeople::class)->data($person);
    }

    public function savePerson(Person $person, array $data): void
    {
        app(AgendaPeople::class)->save($person, $data);
    }

    /** Plan limit: a new professional only while the company has room for one more. */
    public function checkPerson(?Person $person, array $data, array $details): void
    {
        app(AgendaPeople::class)->check($person, $data);
    }

    public function removePerson(Person $person): void
    {
        app(AgendaPeople::class)->remove($person);
    }

    public function personRoleLabel(Person $person): ?string
    {
        return app(AgendaPeople::class)->roleLabel($person);
    }

    public function exportPerson(Person $person): array
    {
        $professional = Professional::query()->where('person_id', $person->getKey())->first();

        return $professional === null ? [] : [
            'professional' => $professional->is_active,
            'commission_percent' => $professional->commission_bp / 100,
            'week' => $professional->week,
        ];
    }

    public function policies(): array
    {
        return [
            Client::class => ClientPolicy::class,
            Service::class => ServicePolicy::class,
            Professional::class => ProfessionalPolicy::class,
            Appointment::class => AppointmentPolicy::class,
            Package::class => PackagePolicy::class,
            ClientPackage::class => ClientPackagePolicy::class,
        ];
    }
}
