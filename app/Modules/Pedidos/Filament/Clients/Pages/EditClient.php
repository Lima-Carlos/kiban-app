<?php

namespace App\Modules\Pedidos\Filament\Clients\Pages;

use App\Modules\Field\Services\CustomerRules;
use App\Modules\Pedidos\Filament\Clients\ClientResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

class EditClient extends EditRecord
{
    protected static string $resource = ClientResource::class;

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
        return [ViewAction::make(), DeleteAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return ClientResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
