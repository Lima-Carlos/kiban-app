<?php

namespace App\Modules\Demo\Filament\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Demo\Filament\TaskListResource;

class ManageTaskLists extends ModuleRecordsPage
{
    protected static string $resource = TaskListResource::class;
}
