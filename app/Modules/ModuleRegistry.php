<?php

namespace App\Modules;

use App\Models\Tenant;
use InvalidArgumentException;

/**
 * The modules this app knows (config kiban.modules) and which of them a company can use.
 */
class ModuleRegistry
{
    /** @var array<string, Module>|null */
    protected ?array $modules = null;

    /**
     * @return array<string, Module>
     */
    public function all(): array
    {
        return $this->modules ??= collect(config('kiban.modules'))
            ->map(fn (string $class): Module => app($class))
            ->keyBy(fn (Module $module): string => $module->key())
            ->all();
    }

    public function get(string $key): Module
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown module [{$key}].");
    }

    /**
     * Modules the company's license gives access to.
     *
     * @return array<string, Module>
     */
    public function enabledFor(Tenant $tenant): array
    {
        return array_filter($this->all(), fn (Module $module): bool => $tenant->license()->hasModule($module->key()));
    }
}
