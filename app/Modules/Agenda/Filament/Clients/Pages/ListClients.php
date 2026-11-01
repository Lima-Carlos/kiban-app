<?php

namespace App\Modules\Agenda\Filament\Clients\Pages;

use App\Modules\Agenda\Filament\Clients\ClientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    /** Says why a client cannot be added (read-only, no access to change). */
    public function getSubheading(): string|Htmlable|null
    {
        $response = ClientResource::getCreateAuthorizationResponse();

        return $response->denied() && $response->message() !== __('agenda.denied.manager') ? $response->message() : null;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
