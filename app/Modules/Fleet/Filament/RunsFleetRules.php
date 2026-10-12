<?php

namespace App\Modules\Fleet\Filament;

use App\Modules\Fleet\Services\FleetException;
use Filament\Notifications\Notification;

/**
 * Runs a Frota rule from a screen: "saved" when it works, the rule's message when it is refused.
 */
class RunsFleetRules
{
    public static function attempt(callable $callback, ?string $success = null): bool
    {
        try {
            $callback();
            Notification::make()->success()->title($success ?? __('fleet.notifications.saved'))->send();

            return true;
        } catch (FleetException $exception) {
            Notification::make()->danger()->title($exception->getMessage())->send();

            return false;
        }
    }
}
