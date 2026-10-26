<?php

namespace App\Access;

use App\Filament\Pages\Home;
use App\Models\User;
use App\Modules\Module;
use App\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * The icons at the top of the menu: one per system of the person, the current one highlighted, and on the
 * others the number of items in their "Attention" box (so nothing goes unnoticed while using another system).
 */
class ModuleSwitcher
{
    /** Seconds the numbers of the other systems are kept (they are recalculated after that). */
    public const COUNT_SECONDS = 120;

    public function __construct(
        protected CurrentModule $current,
        protected TenantContext $context,
    ) {}

    /**
     * @return array<int, array{key: string, name: string, icon: string, url: string, current: bool, attention: int}>
     */
    public function items(): array
    {
        if (! $this->current->hasChoice()) {
            return [];
        }

        $currentKey = $this->current->key();

        return collect($this->current->modules())
            ->map(fn (Module $module): array => [
                'key' => $module->key(),
                'name' => $module->name(),
                'icon' => $module->icon(),
                'url' => Home::getUrl([CurrentModule::QUERY => $module->key()]),
                'current' => $module->key() === $currentKey,
                'attention' => $module->key() === $currentKey ? 0 : $this->attentionCount($module),
            ])
            ->values()
            ->all();
    }

    protected function attentionCount(Module $module): int
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            return 0;
        }

        $key = 'kiban:attention:'.$this->context->get()->getKey().':'.$user->getKey().':'.$module->key();

        return (int) Cache::remember($key, self::COUNT_SECONDS, fn (): int => count($module->attention($user)));
    }
}
