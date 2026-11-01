<?php

namespace App\Modules\Agenda\Filament\Clients\Pages;

use App\Filament\Support\NewRecordTitle;
use App\Modules\Agenda\Filament\Clients\ClientResource;
use App\Modules\Agenda\Models\Client;
use App\Modules\Field\Services\CustomerRules;
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
        // A tutor is a person (the Campo also lists companies).
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
