<?php

namespace App\Modules\Expenses\Filament\Categories\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Expenses\Filament\Categories\CategoryResource;
use App\Modules\Expenses\Services\ExpenseService;
use Filament\Actions\CreateAction;

class ManageCategories extends ModuleRecordsPage
{
    protected static string $resource = CategoryResource::class;

    public function mount(): void
    {
        parent::mount();
        app(ExpenseService::class)->ensureCategories();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data): array => CategoryResource::withLimit($data, (array) ($this->mountedActions[0]['data'] ?? []))),
        ];
    }
}
