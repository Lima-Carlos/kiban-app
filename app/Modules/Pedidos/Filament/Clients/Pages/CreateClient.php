<?php

namespace App\Modules\Pedidos\Filament\Clients\Pages;

use App\Filament\Support\NewRecordTitle;
use App\Modules\Field\Services\CustomerRules;
use App\Modules\Pedidos\Filament\Clients\ClientResource;
use App\Modules\Pedidos\Models\Client;
use Filament\Resources\Pages\CreateRecord;

class CreateClient extends CreateRecord
{
    use NewRecordTitle;

    protected static string $resource = ClientResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // Who orders food is a person (the Campo also lists companies).
        return app(CustomerRules::class)->prepare($data + ['kind' => Client::PERSON]);
    }

    protected function afterCreate(): void
    {
        $this->getRecord()->ensureMainSite();
    }

    protected function getRedirectUrl(): string
    {
        return ClientResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
