<?php

namespace App\Modules\Expenses\Services;

use App\Models\User;
use App\Modules\Expenses\Filament\Claims\ClaimResource;
use App\Modules\Expenses\Models\Category;
use App\Modules\Expenses\Models\Claim;
use App\Modules\Fleet\Enums\ExpenseCategory;
use App\Modules\Fleet\FleetModule;
use App\Modules\Fleet\Models\Expense as FleetExpense;
use App\Notices\Notice;
use App\Notices\Notifier;
use App\Support\Country;
use App\Tenancy\TenantContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

/**
 * Every rule of the expense claims: the ready categories, claiming (receipt when the category asks, never in the
 * future), approving or refusing (with the reason), paying back by person, the bell, and, with the Frota, the
 * approved claim of a vehicle becoming an expense of that vehicle (so the cost per km includes it).
 */
class ExpenseService
{
    public function __construct(protected TenantContext $context) {}

    /** The ready categories, created once, in the language of who opens the screen first. */
    public function ensureCategories(): void
    {
        // Only the missing ones: a ready category added in a new version also reaches companies already using it.
        $existing = Category::query()->pluck('key')->filter()->all();
        $sort = (int) Category::query()->max('sort');

        foreach (Category::DEFAULTS as $key => [$receipt, $fleet]) {
            if (! in_array($key, $existing, true)) {
                Category::query()->create(['key' => $key, 'name' => __('expenses.defaults.'.$key), 'receipt_required' => $receipt, 'fleet_category' => $fleet, 'sort' => ++$sort]);
            }
        }
    }

    /**
     * Mileage (category with the value per km): send `km` instead of `amount_cents`.
     *
     * @param  array{category_id: int, spent_on: mixed, amount_cents?: int, km?: float|string|null, description: string, receipt_path?: ?string, work_order_id?: ?int, vehicle_id?: ?int}  $data
     */
    public function submit(User $user, array $data): Claim
    {
        $category = Category::query()->active()->find($data['category_id'] ?? null) ?? throw new ExpenseException(__('expenses.errors.category'));

        $km = null;

        if ($category->isMileage()) {
            $km = round((float) ($data['km'] ?? 0), 1);

            if ($category->per_km_cents === null || $category->per_km_cents <= 0) {
                throw new ExpenseException(__('expenses.errors.no_rate'));
            }

            if ($km <= 0) {
                throw new ExpenseException(__('expenses.errors.km'));
            }

            $data['amount_cents'] = (int) round($km * $category->per_km_cents);
        }

        if ((int) ($data['amount_cents'] ?? 0) <= 0) {
            throw new ExpenseException(__('expenses.errors.amount'));
        }

        if ($category->receipt_required && blank($data['receipt_path'] ?? null)) {
            throw new ExpenseException(__('expenses.errors.receipt'));
        }

        if (\Carbon\CarbonImmutable::parse((string) $data['spent_on'])->isAfter(today())) {
            throw new ExpenseException(__('expenses.errors.future'));
        }

        $claim = new Claim([
            'category_id' => $category->getKey(),
            'spent_on' => $data['spent_on'],
            'amount_cents' => (int) $data['amount_cents'],
            'currency' => Country::currency(),
            'description' => mb_substr(trim((string) $data['description']), 0, 255),
            'receipt_path' => $data['receipt_path'] ?? null,
            'work_order_id' => $data['work_order_id'] ?? null,
            'vehicle_id' => $data['vehicle_id'] ?? null,
            'km' => $km,
        ]);
        $claim->forceFill(['user_id' => $user->getKey()])->save();

        app(Notifier::class)->send(app(ExpenseAccess::class)->managers(), new Notice(
            tenantId: $claim->tenant_id,
            titleKey: 'expenses.notices.new',
            titleParams: ['name' => $user->name, 'amount' => $this->money($claim->amount_cents, $claim->currency)],
            url: Notice::path(ClaimResource::getUrl('index')),
            icon: 'heroicon-o-receipt-percent',
            color: $claim->overLimit() ? 'warning' : 'info',
        ), except: $user);

        return $claim;
    }

    public function approve(Claim $claim, User $by, ?string $note = null): Claim
    {
        $this->pending($claim);

        return DB::transaction(function () use ($claim, $by, $note): Claim {
            $claim->forceFill(['status' => Claim::APPROVED, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'decision_note' => $note])->save();
            $this->toFleet($claim);
            $this->tell($claim, 'expenses.notices.approved', 'success');

            return $claim;
        });
    }

    public function reject(Claim $claim, User $by, string $note): Claim
    {
        $this->pending($claim);

        if (blank($note)) {
            throw new ExpenseException(__('expenses.errors.reason'));
        }

        $claim->forceFill(['status' => Claim::REJECTED, 'decided_by' => $by->getKey(), 'decided_at' => now(), 'decision_note' => mb_substr(trim($note), 0, 255)])->save();
        $this->tell($claim, 'expenses.notices.rejected', 'danger');

        return $claim;
    }

    /**
     * Pays back every approved claim of a person (one transfer) and tells them.
     *
     * @return int how many claims were paid
     */
    public function payPerson(User $person, User $by): int
    {
        $claims = Claim::query()->status(Claim::APPROVED)->where('user_id', $person->getKey())->get();

        if ($claims->isEmpty()) {
            return 0;
        }

        Claim::query()->whereKey($claims->modelKeys())->update(['status' => Claim::PAID, 'paid_by' => $by->getKey(), 'paid_at' => now()]);

        app(Notifier::class)->send([$person], new Notice(
            tenantId: $claims->first()->tenant_id,
            titleKey: 'expenses.notices.paid',
            titleParams: ['amount' => $this->money((int) $claims->sum('amount_cents'), $claims->first()->currency)],
            url: Notice::path(ClaimResource::getUrl('index')),
            icon: 'heroicon-o-banknotes',
            color: 'success',
        ));

        return $claims->count();
    }

    /**
     * Approved and not paid yet, by person.
     *
     * @return Collection<int, array{user: User, count: int, total: int, currency: string}>
     */
    public function toPay(): Collection
    {
        return Claim::query()->with('user')->status(Claim::APPROVED)->get()->groupBy('user_id')
            ->map(fn (Collection $claims): array => ['user' => $claims->first()->user, 'count' => $claims->count(), 'total' => (int) $claims->sum('amount_cents'), 'currency' => $claims->first()->currency])
            ->sortBy(fn (array $row): string => (string) $row['user']?->name)->values();
    }

    public function money(int $cents, ?string $currency = null): string
    {
        return (string) Number::currency($cents / 100, $currency ?? Country::currency(), str_replace('_', '-', app()->getLocale()));
    }

    /** Frota: the approved claim of a vehicle, in a category linked to the Frota, becomes an expense of the vehicle. */
    protected function toFleet(Claim $claim): void
    {
        $category = $claim->category;
        $fleetCategory = $category?->fleet_category ? ExpenseCategory::tryFrom($category->fleet_category) : null;

        if ($claim->vehicle_id === null || $fleetCategory === null || ! $this->context->get()->license()->hasModule(FleetModule::KEY)) {
            return;
        }

        $receipt = null;

        if ($claim->receipt_path !== null && Storage::disk('local')->exists($claim->receipt_path)) {
            $receipt = dirname($claim->receipt_path).'/fleet-'.basename($claim->receipt_path);
            Storage::disk('local')->copy($claim->receipt_path, $receipt);
        }

        $expense = FleetExpense::query()->create([
            'vehicle_id' => $claim->vehicle_id,
            'category' => $fleetCategory,
            'description' => __('expenses.fleet_description', ['name' => $claim->user?->name, 'description' => $claim->description]),
            'amount_cents' => $claim->amount_cents,
            'spent_on' => $claim->spent_on->toDateString(),
            'receipt_path' => $receipt,
        ]);
        $claim->forceFill(['fleet_expense_id' => $expense->getKey()])->save();
    }

    protected function pending(Claim $claim): void
    {
        if ($claim->status !== Claim::PENDING) {
            throw new ExpenseException(__('expenses.errors.decided'));
        }
    }

    protected function tell(Claim $claim, string $title, string $color): void
    {
        app(Notifier::class)->send([$claim->user], new Notice(
            tenantId: $claim->tenant_id,
            titleKey: $title,
            titleParams: ['amount' => $this->money($claim->amount_cents, $claim->currency), 'description' => $claim->description],
            bodyKey: $claim->decision_note ? 'expenses.notices.note' : null,
            bodyParams: ['note' => (string) $claim->decision_note],
            url: Notice::path(ClaimResource::getUrl('index')),
            icon: 'heroicon-o-receipt-percent',
            color: $color,
        ));
    }
}
