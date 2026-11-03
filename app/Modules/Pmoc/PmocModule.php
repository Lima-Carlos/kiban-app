<?php

namespace App\Modules\Pmoc;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Field\Enums\FieldRole;
use App\Modules\Field\FieldModule;
use App\Modules\Field\Services\FieldAccess;
use App\Modules\Module;
use App\Modules\Pmoc\Filament\Plans\PlanResource;
use App\Modules\Pmoc\Models\Equipment;
use App\Modules\Pmoc\Models\Plan;
use App\Modules\Pmoc\Models\Visit;
use App\Modules\Pmoc\Policies\PlanPolicy;
use App\Modules\Step;

/**
 * Kiban Core PMOC ("Preventive Maintenance" outside Brazil): maintenance plans of the customers' air conditioning
 * (or any equipment), with the equipment list, the responsible technician, visits that become Campo work orders
 * by themselves (with the activities of the month, quarter, half-year or year) and the PMOC document in PDF.
 * An add-on of the Campo: it uses its customers, addresses, technicians and work orders.
 * Product code in the Core: "pmoc"; plan limit: active equipment.
 */
class PmocModule extends Module
{
    public const KEY = 'pmoc';

    public const LIMIT_EQUIPMENT = 'equipment';

    public const MANAGER = 'manager';

    public const VIEWER = 'viewer';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('pmoc.module');
    }

    public function icon(): string
    {
        return 'heroicon-o-clipboard-document-list';
    }

    public function steps(): array
    {
        return [
            new Step(
                key: 'plan',
                labelKey: 'pmoc.steps.plan',
                descriptionKey: 'pmoc.steps.plan_help',
                done: fn (Tenant $tenant): bool => Plan::query()->exists(),
                url: fn (): string => PlanResource::getUrl('create'),
            ),
            new Step(
                key: 'equipment',
                labelKey: 'pmoc.steps.equipment',
                descriptionKey: 'pmoc.steps.equipment_help',
                requires: ['plan'],
                done: fn (Tenant $tenant): bool => Equipment::query()->exists(),
                url: fn (): string => PlanResource::getUrl('index'),
            ),
            new Step(
                key: 'visit',
                labelKey: 'pmoc.steps.visit',
                descriptionKey: 'pmoc.steps.visit_help',
                requires: ['equipment'],
                done: fn (Tenant $tenant): bool => Visit::query()->exists(),
                url: fn (): string => PlanResource::getUrl('index'),
            ),
        ];
    }

    public function resources(): array
    {
        return [PlanResource::class];
    }

    public function roles(): array
    {
        return [self::MANAGER => __('pmoc.roles.manager'), self::VIEWER => __('pmoc.roles.viewer')];
    }

    /** Who runs the Campo office runs the plans; who only looks at the Campo only looks here. */
    public function implicitRole(User $user): ?string
    {
        if (! app(\App\Tenancy\TenantContext::class)->get()->license()->hasModule(FieldModule::KEY)) {
            return null;
        }

        return match (app(FieldAccess::class)->role($user)) {
            FieldRole::Manager, FieldRole::Dispatcher => self::MANAGER,
            FieldRole::Viewer => self::VIEWER,
            default => null,
        };
    }

    public function showsSetupTo(User $user): bool
    {
        return app(Services\PmocAccess::class)->isManager($user);
    }

    public function policies(): array
    {
        return [Plan::class => PlanPolicy::class];
    }
}
