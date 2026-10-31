<?php

namespace App\Filament\Support;

use App\Modules\Concerns\InModuleMenu;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\Lang;

/**
 * Base for the screens of a module: in the menu only while that system is the one chosen at the top of the menu
 * (InModuleMenu). Access, read-only, first steps and limits come from the module's policy (ModulePolicy).
 */
abstract class ModuleResource extends Resource
{
    use InModuleMenu;

    /** Module key (product code in the Core). */
    protected static string $moduleKey;

    /** Translation group of the screen (e.g. "fleet.vehicles"); its "new" key titles the create screen. */
    protected static ?string $langGroup = null;

    public static function getModuleKey(): string
    {
        return static::$moduleKey;
    }

    /**
     * "Novo veículo", "Nova ordem de serviço": the create button and screen title, written per screen (the
     * gender changes with the word). Without it, "Cadastrar <name>" (never "Criar Horário De Trabalho").
     */
    public static function getNewRecordLabel(): string
    {
        $key = static::$langGroup !== null ? static::$langGroup.'.new' : null;

        return $key !== null && Lang::has($key) ? __($key) : __('app.new_record', ['label' => static::getModelLabel()]);
    }
}
