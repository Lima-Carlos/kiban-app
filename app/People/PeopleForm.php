<?php

namespace App\People;

use Closure;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Helpers for the modules' fields inside the "People" form (their state is under "modules.<key>").
 */
class PeopleForm
{
    /** Whether the person has (or will get) a login, from inside a module's section. */
    public static function hasLogin(): Closure
    {
        return fn (Get $get): bool => (bool) $get('../../with_login');
    }
}
