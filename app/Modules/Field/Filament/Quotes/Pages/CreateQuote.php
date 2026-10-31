<?php

namespace App\Modules\Field\Filament\Quotes\Pages;

use App\Filament\Support\NewRecordTitle;
use App\Modules\Field\Filament\Quotes\QuoteResource;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Services\FieldException;
use App\Modules\Field\Services\QuoteService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateQuote extends CreateRecord
{
    use NewRecordTitle;

    protected static string $resource = QuoteResource::class;

    protected static bool $canCreateAnother = false;

    /** "New quote" from a customer's page comes with the customer chosen. */
    protected function afterFill(): void
    {
        $customer = Customer::query()->active()->find(request()->integer('cliente'));

        if ($customer !== null) {
            $this->form->fill(['customer_id' => $customer->getKey(), 'site_id' => $customer->mainSite?->getKey(), 'items' => [['quantity' => 1, 'unit' => 'un']]]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            return app(QuoteService::class)->create($data, (array) ($data['items'] ?? []), auth()->user());
        } catch (FieldException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            $this->halt();
        }
    }

    protected function getRedirectUrl(): string
    {
        return QuoteResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
