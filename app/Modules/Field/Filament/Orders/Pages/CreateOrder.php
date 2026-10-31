<?php

namespace App\Modules\Field\Filament\Orders\Pages;

use App\Filament\Support\NewRecordTitle;
use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\Customer;
use App\Modules\Field\Models\WorkOrder;
use App\Modules\Field\Services\FieldException;
use App\Modules\Field\Services\WorkOrderService;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;

class CreateOrder extends CreateRecord
{
    use NewRecordTitle;

    protected static string $resource = OrderResource::class;

    protected static bool $canCreateAnother = false;

    /** "Open work order" from a customer's page comes with the customer chosen. */
    protected function afterFill(): void
    {
        $customer = Customer::query()->active()->find(request()->integer('cliente'));

        if ($customer !== null) {
            $this->form->fill(['customer_id' => $customer->getKey(), 'site_id' => $customer->mainSite?->getKey(), 'priority' => 'normal']);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        try {
            $order = app(WorkOrderService::class)->open($data, auth()->user());
        } catch (FieldException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            $this->halt();
        }

        ViewOrder::warnClashes($order);

        return $order;
    }

    protected function getRedirectUrl(): string
    {
        return OrderResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
