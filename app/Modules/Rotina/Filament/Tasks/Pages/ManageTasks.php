<?php

namespace App\Modules\Rotina\Filament\Tasks\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Rotina\Filament\Tasks\TaskResource;
use Filament\Actions\CreateAction;

class ManageTasks extends ModuleRecordsPage
{
    protected static string $resource = TaskResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->mutateDataUsing(fn (array $data): array => $data + ['user_id' => auth()->id()]),
        ];
    }
}
