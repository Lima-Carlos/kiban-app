<?php

namespace App\Modules\Pedidos\Filament\MenuItems\Pages;

use App\Filament\Support\ModuleRecordsPage;
use App\Modules\Pedidos\Filament\MenuItems\MenuItemResource;

class ManageMenuItems extends ModuleRecordsPage
{
    protected static string $resource = MenuItemResource::class;
}
