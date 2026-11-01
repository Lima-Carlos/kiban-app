<?php

namespace App\Modules;

use App\Models\Tenant;
use Closure;

/**
 * One item of a module's first-steps assistant, e.g. "Register your vehicles".
 *
 * "done" looks at the company's real data (nothing to tick by hand), and "requires" lists the steps
 * that must be done first: the screen of a step stays closed until then.
 */
final class Step
{
    /**
     * @param  Closure(Tenant): bool  $done
     * @param  array<int, string>  $requires
     * @param  (Closure(): ?string)|null  $url
     */
    public function __construct(
        public readonly string $key,
        public readonly string $labelKey,
        public readonly Closure $done,
        public readonly array $requires = [],
        public readonly ?Closure $url = null,
        public readonly ?string $descriptionKey = null,
    ) {}

    public function label(): string
    {
        return __($this->labelKey);
    }

    public function description(): ?string
    {
        return $this->descriptionKey ? __($this->descriptionKey) : null;
    }

    public function isDone(Tenant $tenant): bool
    {
        return (bool) ($this->done)($tenant);
    }

    public function url(): ?string
    {
        return $this->url ? ($this->url)() : null;
    }

    /**
     * Where a list of steps stands: done (real data), available (the steps it depends on are done) and, when
     * locked, which ones are missing. Used by each module and by the company's combined first steps.
     *
     * @param  array<int, Step>  $steps
     * @return array<int, array{key: string, label: string, done: bool, available: bool, missing: array<int, string>, url: ?string, description: ?string}>
     */
    public static function progress(array $steps, Tenant $tenant): array
    {
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
}
