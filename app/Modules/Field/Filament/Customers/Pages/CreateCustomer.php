<?php

namespace App\Modules\Field\Filament\Customers\Pages;

use App\Filament\Support\NewRecordTitle;
use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Services\CustomerRules;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomer extends CreateRecord
{
    use NewRecordTitle;

    protected static string $resource = CustomerResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeCreate(array $data): array
    {
        return app(CustomerRules::class)->prepare($data);
    }

    protected function afterCreate(): void
    {
        $this->getRecord()->ensureMainSite();
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
