<?php

namespace App\Modules\Field\Filament\Customers\Pages;

use App\Modules\Field\Filament\Customers\CustomerResource;
use App\Modules\Field\Services\CustomerRules;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditCustomer extends EditRecord
{
    protected static string $resource = CustomerResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeSave(array $data): array
    {
        return app(CustomerRules::class)->prepare($data);
    }

    protected function afterSave(): void
    {
        $this->getRecord()->ensureMainSite();
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }

    protected function getRedirectUrl(): string
    {
        return CustomerResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
