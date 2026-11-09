<?php

namespace App\Modules\Expenses;

use App\Digest\ModuleWeek;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Expenses\Filament\Categories\CategoryResource;
use App\Modules\Expenses\Filament\Claims\ClaimResource;
use App\Modules\Expenses\Filament\PaymentsPage;
use App\Modules\Expenses\Models\Category;
use App\Modules\Expenses\Models\Claim;
use App\Modules\Expenses\Policies\CategoryPolicy;
use App\Modules\Expenses\Policies\ClaimPolicy;
use App\Modules\Expenses\Services\ExpenseAccess;
use App\Modules\Module;
use App\Modules\Step;
use Carbon\CarbonImmutable;

/**
 * Kiban Core Despesas ("Expenses"): the team claims what they paid for the company (toll, parking, meal, hotel,
 * ride, material...) with the receipt photo on the phone; the manager approves or refuses with the reason and
 * pays back by person. With the Campo, the claim points to a work order; with the Frota, an approved claim of a
 * vehicle becomes an expense of the vehicle. Product code in the Core: "despesas"; plan limit: people claiming.
 */
class ExpensesModule extends Module
{
    public const KEY = 'despesas';

    public const LIMIT_PEOPLE = 'people';

    public const MANAGER = 'manager';

    public const MEMBER = 'member';

    public function key(): string
    {
        return self::KEY;
    }

    public function name(): string
    {
        return __('expenses.module');
    }

    public function icon(): string
    {
        return 'heroicon-o-receipt-percent';
    }

    public function steps(): array
    {
        return [
            new Step(
                key: 'categories',
                labelKey: 'expenses.steps.categories',
                descriptionKey: 'expenses.steps.categories_help',
                done: fn (Tenant $tenant): bool => Category::query()->whereColumn('created_at', '<', 'updated_at')->exists() || Claim::query()->exists(),
                url: fn (): string => CategoryResource::getUrl('index'),
            ),
            new Step(
                key: 'claim',
                labelKey: 'expenses.steps.claim',
                descriptionKey: 'expenses.steps.claim_help',
                done: fn (Tenant $tenant): bool => Claim::query()->exists(),
                url: fn (): string => ClaimResource::getUrl('index'),
            ),
        ];
    }

    public function resources(): array
    {
        return [ClaimResource::class, CategoryResource::class];
    }

    public function pages(): array
    {
        return [PaymentsPage::class];
    }

    public function roles(): array
    {
        return [self::MANAGER => __('expenses.roles.manager'), self::MEMBER => __('expenses.roles.member')];
    }

    /** Everyone of the company can claim their own expenses. */
    public function implicitRole(User $user): ?string
    {
        return self::MEMBER;
    }

    public function showsSetupTo(User $user): bool
    {
        return app(ExpenseAccess::class)->isManager($user);
    }

    /** Claims waiting for a decision, for the manager. */
    public function attention(User $user): array
    {
        if (! app(ExpenseAccess::class)->isManager($user)) {
            return [];
        }

        $pending = Claim::query()->status(Claim::PENDING)->count();

        return $pending === 0 ? [] : [[
            'text' => trans_choice('expenses.attention.pending', $pending, ['count' => $pending]),
            'url' => ClaimResource::getUrl('index'),
            'color' => 'warning',
        ]];
    }

    public function policies(): array
    {
        return [Claim::class => ClaimPolicy::class, Category::class => CategoryPolicy::class];
    }

    /** The week in numbers for the owner's weekly summary. */
    public function weeklySummary(Tenant $tenant, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return app(ModuleWeek::class)->expenses($from, $to);
    }
}
