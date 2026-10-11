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
}
