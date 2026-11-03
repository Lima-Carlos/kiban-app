<?php

namespace App\Modules\Stock\Filament\Movements\Pages;

use App\Modules\Stock\Filament\Movements\MovementResource;
use Filament\Resources\Pages\ListRecords;

class ListMovements extends ListRecords
{
    protected static string $resource = MovementResource::class;
}
