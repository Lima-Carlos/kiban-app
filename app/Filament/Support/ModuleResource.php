<?php

namespace App\Filament\Support;

use App\Modules\Concerns\InModuleMenu;
use Filament\Resources\Resource;

/**
 * Base for the screens of a module: in the menu only while that system is the one chosen at the top of the menu
 * (InModuleMenu). Access, read-only, first steps and limits come from the module's policy (ModulePolicy).
 */
abstract class ModuleResource extends Resource
{
    use InModuleMenu;

    /** Module key (product code in the Core). */
    protected static string $moduleKey;

    public static function getModuleKey(): string
    {
        return static::$moduleKey;
    }
}
