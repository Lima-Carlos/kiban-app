<?php

namespace App\Modules\Field\Filament\Quotes\Pages;

use App\Modules\Field\Filament\Quotes\QuoteResource;
use App\Modules\Field\Models\Quote;
use App\Modules\Field\Models\QuoteItem;
use App\Modules\Field\Services\FieldException;
use App\Modules\Field\Services\QuoteService;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

/** A quote changes only before the customer answers (a sent quote shows the new values on the link). */
class EditQuote extends EditRecord
{
    protected static string $resource = QuoteResource::class;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var Quote $quote */
        $quote = $this->getRecord();

        return $data + [
            'items' => $quote->items->map(fn (QuoteItem $item): array => $item->only(['service_type_id', 'material_id', 'description', 'unit', 'unit_price_cents']) + ['quantity' => (float) $item->quantity])->all(),
        ];
    }

    /**
     * @param  Quote  $record
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        try {
            return app(QuoteService::class)->update($record, $data, (array) ($data['items'] ?? []));
        } catch (FieldException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            $this->halt();
        }
    }

    protected function getHeaderActions(): array
    {
        return [ViewAction::make()];
    }

    protected function getRedirectUrl(): string
    {
        return QuoteResource::getUrl('view', ['record' => $this->getRecord()]);
    }
}
