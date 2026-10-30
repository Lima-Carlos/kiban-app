<?php

namespace App\Modules\Field;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Filament\Dashboard\FieldDashboard;
use App\Modules\Field\Filament\Dashboard\FieldMonthWidget;
use App\Modules\Field\Filament\Dashboard\FieldNowWidget;
use App\Modules\Field\Filament\ExecuteOrderPage;
use App\Modules\Field\Filament\FieldSettingsPage;
use App\Modules\Field\Filament\Materials\MaterialResource;
use App\Modules\Field\Filament\MyOrdersPage;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Filament\Quotes\QuoteResource;
use App\Modules\Field\Filament\ServiceTypes\ServiceTypeResource;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\FieldSetting;
use App\Modules\Field\Models\Material;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\ServiceType;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Policies\CustomerPolicy;
use App\Modules\Field\Policies\MaterialPolicy;
use App\Modules\Field\Policies\QuotePolicy;
use App\Modules\Field\Policies\ServiceTypePolicy;
use App\Modules\Field\Policies\WorkOrderPolicy;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\Field\Services\FieldPeople;
use App\Modules\Field\Services\FieldReports;
use App\Modules\Module;
use App\Modules\Step;
use App\People\Documents\PersonDocumentService;
use App\People\Person;

/**
 * Kiban Campo (Kiban Field in English): work orders for teams in the field. Part 1: the customers and their
 * service addresses with the point on the map. Next parts: types of service and models per business (O2),
 * quotes approved by link (O2b), the day's schedule and map (O3), the work on the phone, also without signal
 * (O4), and the report by link or WhatsApp (O5). Product code in the Core: "campo"; plan limit: active technicians.
 */
class FieldModule extends Module
{
    public const KEY = 'campo';

    /** Plan limit in the Core: number of active technicians. */
    public const LIMIT_TECHNICIANS = 'technicians';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('field.module');
    }

    public function icon(): string
    {
        return 'heroicon-o-wrench-screwdriver';
    }

    public function steps(): array
    {
        return [
            new Step(
                key: 'business',
                labelKey: 'field.steps.business',
                descriptionKey: 'field.steps.business_help',
                done: fn (Tenant $tenant): bool => FieldSetting::query()->whereNotNull('configured_at')->exists(),
                url: fn (): string => FieldSettingsPage::getUrl(),
            ),
            new Step(
                key: 'service_types',
                labelKey: 'field.steps.service_types',
                descriptionKey: 'field.steps.service_types_help',
                requires: ['business'],
                done: fn (Tenant $tenant): bool => ServiceType::query()->active()->exists(),
                url: fn (): string => ServiceTypeResource::getUrl('index'),
            ),
            new Step(
                key: 'customers',
                labelKey: 'field.steps.customers',
                descriptionKey: 'field.steps.customers_help',
                done: fn (Tenant $tenant): bool => Customer::query()->exists(),
                url: fn (): string => CustomerResource::getUrl('index'),
            ),
        ];
    }

    public function resources(): array
    {
        return [OrderResource::class, QuoteResource::class, CustomerResource::class, ServiceTypeResource::class, MaterialResource::class];
    }

    public function pages(): array
    {
        return [FieldDashboard::class, MyOrdersPage::class, ExecuteOrderPage::class, FieldSettingsPage::class];
    }

    /** Managers, the service desk and viewers: where each technician is now and the month, on the start page. */
    public function homeWidgets(User $user): array
    {
        return in_array(app(FieldAccess::class)->role($user), [FieldRole::Manager, FieldRole::Dispatcher, FieldRole::Viewer], true) ? [FieldNowWidget::class, FieldMonthWidget::class] : [];
    }

    /**
     * The office: a technician who has not arrived, orders handed back, urgent orders without a technician and
     * technicians with an expired safety training (NR-10, NR-35...; only "expired", the document stays with HR).
     */
    public function attention(User $user): array
    {
        if (! in_array(app(FieldAccess::class)->role($user), [FieldRole::Manager, FieldRole::Dispatcher], true)) {
            return [];
        }

        $technicians = app(FieldAccess::class)->technicians()->keyBy(fn (User $technician): int => $technician->getKey());
        $expired = app(PersonDocumentService::class)->expiredTexts(self::KEY, $technicians->keys()->all());

        return array_merge(app(FieldReports::class)->attention(), collect($expired)
            ->map(fn (string $documents, int $userId): array => [
                'text' => __('documents.attention.technician', ['name' => $technicians->get($userId)?->name, 'documents' => $documents]),
                'url' => null,
                'color' => 'danger',
            ])->values()->all());
    }

    /** The technician: today's services and the button to open them, on the start page. */
    public function personalHome(User $user): ?array
    {
        if (! MyOrdersPage::canAccess()) {
            return null;
        }

        return [
            'view' => 'field.home.technician',
            'data' => ['today' => app(MyOrdersPage::class)->groups()['today'], 'url' => MyOrdersPage::getUrl()],
        ];
    }

    public function showsSetupTo(User $user): bool
    {
        return app(FieldAccess::class)->isManager($user);
    }

    public function roles(): array
    {
        return collect(FieldRole::assignable())->mapWithKeys(fn (FieldRole $role): array => [$role->value => $role->getLabel()])->all();
    }

    public function managesPeople(User $user): bool
    {
        return app(FieldAccess::class)->isManager($user);
    }

    public function personFields(): array
    {
        return app(FieldPeople::class)->fields();
    }

    public function personData(Person $person): array
    {
        return app(FieldPeople::class)->data($person);
    }

    public function savePerson(Person $person, array $data): void
    {
        app(FieldPeople::class)->save($person, $data);
    }

    /** Plan limit: a new technician only while the company has room for one more. */
    public function checkPerson(?Person $person, array $data, array $details): void
    {
        app(FieldPeople::class)->check($person, $data);
    }

    /** A role in Kiban Campo only makes sense with a login (the technician works on the phone). */
    public function personNeedsLogin(): bool
    {
        return true;
    }

    public function removePerson(Person $person): void
    {
        app(FieldPeople::class)->remove($person);
    }

    public function personRoleLabel(Person $person): ?string
    {
        return app(FieldPeople::class)->roleLabel($person);
    }

    public function policies(): array
    {
        return [
            Customer::class => CustomerPolicy::class,
            ServiceType::class => ServiceTypePolicy::class,
            Material::class => MaterialPolicy::class,
            WorkOrder::class => WorkOrderPolicy::class,
            Quote::class => QuotePolicy::class,
        ];
    }
}
