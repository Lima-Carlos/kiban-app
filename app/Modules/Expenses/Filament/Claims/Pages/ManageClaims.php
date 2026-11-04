<?php

namespace App\Modules\Expenses\Filament\Claims\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Expenses\Filament\Claims\ClaimResource;
use App\Modules\Expenses\Services\ExpenseException;
use App\Modules\Expenses\Services\ExpenseService;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;

class ManageClaims extends ModuleRecordsPage
{
    protected static string $resource = ClaimResource::class;

    public function mount(): void
    {
        parent::mount();
        app(ExpenseService::class)->ensureCategories();
    }

    protected function getHeaderActions(): array
    {
        return [
            // Every rule (receipt, value, date) lives in ExpenseService: a refusal keeps the window open with the reason.
            CreateAction::make()
                ->using(function (array $data, CreateAction $action) {
                    try {
                        return app(ExpenseService::class)->submit(auth()->user(), [
                            'category_id' => (int) $data['category_id'],
                            'spent_on' => $data['spent_on'],
                            'amount_cents' => (int) round((float) $data['amount'] * 100),
                            'description' => (string) $data['description'],
                            'receipt_path' => $data['receipt_path'] ?? null,
                            'work_order_id' => filled($data['work_order_id'] ?? null) ? (int) $data['work_order_id'] : null,
                            'vehicle_id' => filled($data['vehicle_id'] ?? null) ? (int) $data['vehicle_id'] : null,
                        ]);
                    } catch (ExpenseException $exception) {
                        Notification::make()->danger()->title($exception->getMessage())->send();
                        $action->halt();
                    }
                }),
        ];
    }
}
