<?php

namespace App\Modules\Fleet\Filament\Checklist\Pages;

use App\Modules\Fleet\Filament\Checklist\ChecklistItemResource;
use App\Modules\Fleet\Models\ChecklistItem;
use App\Modules\Fleet\Services\InspectionService;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;

class ManageChecklistItems extends ManageRecords
{
    protected static string $resource = ChecklistItemResource::class;

    public function mount(): void
    {
        // The ready items appear the first time the manager opens the list.
        app(InspectionService::class)->ensureDefaultItems();

        parent::mount();
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => $data + ['sort' => (int) ChecklistItem::query()->max('sort') + 10]),
        ];
    }
}
