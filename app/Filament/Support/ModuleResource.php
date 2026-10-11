<?php

namespace App\Filament\Support;

use App\Modules\ModuleRegistry;
use Filament\Resources\Resource;

/**
 * Base for the screens of a module: grouped in the menu under the module name. Access, read-only,
 * first steps and limits come from the module's policy (ModulePolicy).
 */
abstract class ModuleResource extends Resource
{
    /** Module key (product code in the Core). */
    protected static string $moduleKey;

    public static function getNavigationGroup(): ?string
    {
        return app(ModuleRegistry::class)->get(static::$moduleKey)->name();
    }

    public static function getModuleKey(): string
    {
        return static::$moduleKey;
    }
}
