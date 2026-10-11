<?php

namespace App\Modules\Demo\Filament\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Demo\Filament\TaskResource;
use Filament\Actions\CreateAction;

class ManageTasks extends ModuleRecordsPage
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->mutateDataUsing(fn (array $data): array => TaskResource::prepareData($data) + ['created_by' => auth()->id()]),
        ];
    }
}
