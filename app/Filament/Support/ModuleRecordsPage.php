<?php

namespace App\Filament\Support;

use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ManageRecords;
use Illuminate\Contracts\Support\Htmlable;

/**
 * List screen of a module. When creating is not allowed, it says why (read-only, a first step missing,
 * plan limit reached) instead of silently hiding the button.
 */
abstract class ModuleRecordsPage extends ManageRecords
{
    public function getSubheading(): string|Htmlable|null
    {
        $response = static::getResource()::getCreateAuthorizationResponse();

        return $response->denied() ? $response->message() : null;
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
