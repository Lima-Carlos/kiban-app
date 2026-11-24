<?php

namespace App\Modules\Pedidos\Filament\Clients\Pages;

use App\Modules\Pedidos\Filament\Clients\ClientResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListClients extends ListRecords
{
    protected static string $resource = ClientResource::class;

    /** Says why a customer cannot be added (read-only, no access to change). */
    public function getSubheading(): string|Htmlable|null
    {
        $response = ClientResource::getCreateAuthorizationResponse();

        return $response->denied() && $response->message() !== __('pedidos.denied.manager') ? $response->message() : null;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
