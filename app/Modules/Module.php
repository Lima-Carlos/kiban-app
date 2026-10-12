<?php

namespace App\Modules;

use App\Models\Tenant;
use App\Models\User;

/**
 * A system sold by the Kiban Core (Frota, Ponto...). The key is the product code in the Core:
 * the company's license says if the module is available, read-only, and its limits.
 *
 * Each module lists its first steps (with their dependencies) and its Filament screens.
 */
abstract class Module
{
    /** Product code in the Kiban Core (e.g. "frota"). */
    abstract public function key(): string;

    /** Translated name shown in the menu. */
    abstract public function name(): string;

    /**
     * First steps, in order. A step only opens after the steps it requires are done.
     *
     * @return array<int, Step>
     */
    abstract public function steps(): array;

    public function icon(): string
    {
        return 'heroicon-o-squares-2x2';
    }

    /**
     * Filament screens of the module (shown only to companies whose license includes it).
     *
     * @return array<int, class-string>
     */
    public function resources(): array
    {
        return [];
    }

    /**
     * Extra Filament pages of the module (e.g. its settings).
     *
     * @return array<int, class-string>
     */
    public function pages(): array
    {
        return [];
    }

    /**
     * Dashboard widgets of the module (registered in the panel).
     *
     * @return array<int, class-string>
     */
    public function widgets(): array
    {
        return [];
    }

    /** Who sees the first-steps assistant of this module (by default: owners and administrators). */
    public function showsSetupTo(User $user): bool
    {
        return $user->canManageTenant();
    }

    /**
     * A start-page block made for this person (e.g. the driver's buttons), or null.
     *
     * @return array{view: string, data: array<string, mixed>}|null
     */
    public function personalHome(User $user): ?array
    {
        return null;
    }

    /**
     * Access rules per model (usually subclasses of ModulePolicy).
     *
     * @return array<class-string, class-string>
     */
    public function policies(): array
    {
        return [];
    }

    /**
     * @return array<int, array{key: string, label: string, done: bool, available: bool, missing: array<int, string>, url: ?string, description: ?string}>
     */
    public function progress(Tenant $tenant): array
    {
        $steps = $this->steps();
        $done = [];

        foreach ($steps as $step) {
            $done[$step->key] = $step->isDone($tenant);
        }

        $labels = collect($steps)->keyBy('key');

        return array_map(function (Step $step) use ($done, $labels): array {
            $missing = array_values(array_filter($step->requires, fn (string $key): bool => ! ($done[$key] ?? false)));

            return [
                'key' => $step->key,
                'label' => $step->label(),
                'description' => $step->description(),
                'done' => $done[$step->key],
                'available' => $missing === [],
                'missing' => array_map(fn (string $key): string => $labels->get($key)?->label() ?? $key, $missing),
                'url' => $step->url(),
            ];
        }, $steps);
    }

    public function isStepDone(Tenant $tenant, string $key): bool
    {
        foreach ($this->steps() as $step) {
            if ($step->key === $key) {
                return $step->isDone($tenant);
            }
        }

        return false;
    }
}
