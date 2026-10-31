<?php

namespace App\Filament\Support;

use Illuminate\Contracts\Support\Htmlable;

/**
 * Title of a module's create screen: "Novo veículo" (ModuleResource::getNewRecordLabel), not "Criar Veículo".
 */
trait NewRecordTitle
{
    public function getTitle(): string|Htmlable
    {
        return static::getResource()::getNewRecordLabel();
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return __('app.created');
    }
}
