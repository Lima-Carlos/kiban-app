<?php

namespace App\Modules\Concerns;

use App\Access\CurrentModule;

/**
 * A screen of a system (module) appears in the menu only while that system is the one chosen with the icons at
 * the top of the menu. The screen sets `protected static string $moduleKey`.
 */
trait InModuleMenu
{
    public static function shouldRegisterNavigation(): bool
    {
        return parent::shouldRegisterNavigation() && app(CurrentModule::class)->is(static::$moduleKey);
    }
}
