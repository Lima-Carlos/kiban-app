<?php

namespace App\Modules\Expenses\Filament;

use App\Models\User;
use App\Modules\Concerns\InModuleMenu;
use App\Modules\Expenses\ExpensesModule;
use App\Modules\Expenses\Models\Claim;
use App\Modules\Expenses\Services\ExpenseAccess;
use App\Modules\Expenses\Services\ExpenseService;
use App\Support\Spreadsheet;
use App\Tenancy\TenantContext;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * "To pay back" (manager): the approved claims by person, one payment each ("Mark as paid"), and the spreadsheet
 * of the month for the accountant.
 */
class PaymentsPage extends Page
{
    use InModuleMenu;

    protected static string $moduleKey = ExpensesModule::KEY;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 20;

    protected static ?string $slug = 'despesas/reembolsos';

    protected string $view = 'expenses.payments';

    public static function canAccess(): bool
    {
        $tenant = app(TenantContext::class)->current();

        return $tenant !== null && $tenant->license()->hasModule(ExpensesModule::KEY) && app(ExpenseAccess::class)->isManager();
    }

    public static function getNavigationLabel(): string
    {
        return __('expenses.payments.title');
    }

    public function getTitle(): string
    {
        return __('expenses.payments.title');
    }

    /** @return Collection<int, array{user: User, count: int, total: int, currency: string}> */
    public function rows(): Collection
    {
        return app(ExpenseService::class)->toPay();
    }

    public function pay(int $user): void
    {
        abort_unless(auth()->user()?->can('decide', Claim::class), 403);
        $person = User::query()->findOrFail($user);
        $paid = app(ExpenseService::class)->payPerson($person, auth()->user());
        Notification::make()->success()->title(trans_choice('expenses.payments.paid', $paid, ['count' => $paid, 'name' => $person->name]))->send();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('export')
                ->label(__('expenses.payments.export'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->action(function (): BinaryFileResponse {
                    $month = CarbonImmutable::now()->startOfMonth();
                    $rows = [[__('expenses.fields.spent_on'), __('expenses.fields.person'), __('expenses.fields.category'), __('expenses.fields.description'), __('expenses.fields.amount'), __('expenses.fields.status')]];

                    foreach (Claim::query()->with(['user', 'category'])->whereBetween('spent_on', [$month->toDateString(), $month->endOfMonth()->toDateString()])->orderBy('spent_on')->get() as $claim) {
                        $rows[] = [$claim->spent_on->format('Y-m-d'), $claim->user?->name, $claim->category?->name, $claim->description, round($claim->amount_cents / 100, 2), __('expenses.statuses.'.$claim->status)];
                    }

                    return response()->download(Spreadsheet::write(__('expenses.payments.file', ['month' => $month->format('Y-m')]), [__('expenses.claims.plural') => $rows]), __('expenses.payments.file', ['month' => $month->format('Y-m')]).'.xlsx')->deleteFileAfterSend();
                }),
        ];
    }
}
