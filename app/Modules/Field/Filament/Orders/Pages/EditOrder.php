<?php

namespace App\Modules\Field\Filament\Orders\Pages;

use App\Modules\Field\Filament\Orders\OrderResource;
use App\Modules\Field\Models\OrderEvent;
use App\Modules\Field\Models\Site;
use App\Modules\Field\Models\WorkOrder;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/**
 * Editing an order changes only what was asked (address, priority, report, internal notes); the customer and
 * the type stay. The schedule changes with "Schedule" on the order's page. Closed orders do not change.
 */
class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;

    /**
     * @param  WorkOrder  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        if ($record->status->isClosed()) {
            Notification::make()->danger()->title(__('field.errors.order_closed'))->send();

            $this->halt();
        }

        $siteId = filled($data['site_id'] ?? null) && Site::query()->where('customer_id', $record->customer_id)->whereKey($data['site_id'])->exists() ? (int) $data['site_id'] : $record->site_id;

        $record->forceFill([
            'site_id' => $siteId,
            'priority' => $data['priority'] ?? $record->priority,
            'reported' => $data['reported'] ?? null,
            'internal_notes' => $data['internal_notes'] ?? null,
        ]);

        if ($record->isDirty()) {
            $record->save();
            OrderEvent::create(['order_id' => $record->getKey(), 'type' => 'edited', 'user_id' => auth()->id()]);
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return OrderResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
