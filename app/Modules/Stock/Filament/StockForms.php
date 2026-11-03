<?php

namespace App\Modules\Stock\Filament;

use App\Modules\Stock\Models\Item;
use App\Modules\Stock\Models\Location;
use App\Modules\Stock\Services\StockException;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Pieces shared by the stock screens: the item and place pickers, the quantity, and running a rule while
 * showing its message when it is refused.
 */
class StockForms
{
    public static function item(string $name = 'item_id', bool $serialized = false): Select
    {
        return Select::make($name)->label(__('stock.fields.item'))->options(fn (): array => Item::options($serialized))->searchable()->required();
    }

    public static function location(string $name = 'location_id', ?string $label = null): Select
    {
        return Select::make($name)->label($label ?? __('stock.fields.location'))->options(fn (): array => Location::options())->searchable()->required();
    }

    public static function quantity(string $name = 'quantity', ?string $label = null): TextInput
    {
        return TextInput::make($name)->label($label ?? __('stock.fields.quantity'))->numeric()->minValue(0)->required();
    }

    /** Runs a stock rule; a refusal becomes a red notice and the window stays open. */
    public static function run(Closure $rule, string $done, $action = null): bool
    {
        try {
            $rule();
        } catch (StockException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();
            $action?->halt();

            return false;
        }

        Notification::make()->success()->title($done)->send();

        return true;
    }
}
