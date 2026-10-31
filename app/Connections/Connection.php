<?php

namespace App\Connections;

use App\Models\User;
use Closure;

/**
 * One connection between systems: what happens when a company has two (or more) Kiban systems together, e.g.
 * "the technician's km at arrival goes to the Frota" (Campo + Frota).
 *
 * - modules: the systems involved (product codes); "needs" says how many of them the company must have (all, by
 *   default; 2 for "any pair", e.g. one register of people for all systems);
 * - audience: who it matters to (by role), so a technician only sees what changes something for them;
 * - url: "See where" for this person (the screen where it happens).
 * Texts: lang/<locale>/connections.php → items.<key>.title / body.
 */
final class Connection
{
    /**
     * @param  array<int, string>  $modules
     * @param  Closure(User): bool  $audience
     * @param  Closure(User): ?string  $url
     */
    public function __construct(
        public readonly string $key,
        public readonly array $modules,
        public readonly string $icon,
        public readonly Closure $audience,
        public readonly Closure $url,
        public readonly ?int $needs = null,
    ) {}

    public function title(): string
    {
        return __('connections.items.'.$this->key.'.title');
    }

    public function body(): string
    {
        return __('connections.items.'.$this->key.'.body');
    }

    /** How many of its systems the company must have. */
    public function needed(): int
    {
        return $this->needs ?? count($this->modules);
    }

    /**
     * Works with these systems (product codes the company has).
     *
     * @param  array<int, string>  $enabled
     */
    public function activeWith(array $enabled): bool
    {
        return count(array_intersect($this->modules, $enabled)) >= $this->needed();
    }

    public function matters(User $user): bool
    {
        return (bool) ($this->audience)($user);
    }

    public function urlFor(User $user): ?string
    {
        return ($this->url)($user);
    }
}
