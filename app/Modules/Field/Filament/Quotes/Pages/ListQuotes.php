<?php

namespace App\Modules\Field\Filament\Quotes\Pages;

use App\Modules\Field\Filament\Quotes\QuoteResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

class ListQuotes extends ListRecords
{
    protected static string $resource = QuoteResource::class;

    public function getSubheading(): string|Htmlable|null
    {
        $response = QuoteResource::getCreateAuthorizationResponse();

        return $response->denied() && $response->message() !== __('field.denied.manager') ? $response->message() : null;
    }

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
