<?php

namespace App\Modules\Fleet\Filament\Expenses\Pages;

use App\Modules\Fleet\Filament\Expenses\ExpenseResource;
use App\Modules\Fleet\Filament\RunsFleetRules;
use App\Modules\Fleet\Services\MaintenanceService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageExpenses extends ManageRecords
{
    protected static string $resource = ExpenseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->using(function (array $data) {
                    $expense = null;
                    RunsFleetRules::attempt(fn () => $expense = app(MaintenanceService::class)->recordExpense(ExpenseResource::prepare($data), auth()->user()));

                    return $expense ?? $this->halt();
                })
                ->successNotification(null),
        ];
    }
}
