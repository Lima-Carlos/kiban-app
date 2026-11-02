<?php

namespace App\Onboarding;

use App\Filament\Pages\Home;
use App\Models\Tenant;
use App\Models\User;

/**
 * Guide bar for the company first steps: a step opened from the start list carries `?passo=1`, and the page shows
 * "Step N of M · Back to the steps · Next step" so the customer goes straight on without returning to the start page.
 */
class StepGuide
{
    public const PARAM = 'passo';

    public function __construct(protected CompanyFirstSteps $steps) {}

    /** Adds the guide flag to a step link. */
    public static function link(string $url): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').self::PARAM.'=1';
    }

    /**
     * @return array{number: int, total: int, label: string, home: string, next: ?string, next_label: ?string}|null
     */
    public function for(Tenant $tenant, ?User $user, string $url): ?array
    {
        if ($user === null || ! $this->steps->applies($tenant, $user)) {
            return null;
        }

        $steps = collect($this->steps->groups($tenant, $user))->flatMap(fn (array $group): array => $group['steps'])->values();
        $index = $steps->search(fn (array $step): bool => $step['url'] && rtrim(strtok($step['url'], '?'), '/') === rtrim($url, '/'));

        if ($index === false) {
            return null;
        }

        $current = $steps[$index];
        $next = $steps->slice($index + 1)->first(fn (array $step): bool => ! $step['done'] && $step['available'] && $step['url']);

        return [
            'number' => $index + 1,
            'total' => $steps->count(),
            'label' => $current['label'],
            'home' => Home::getUrl(),
            'next' => $next ? self::link($next['url']) : null,
            'next_label' => $next['label'] ?? null,
        ];
    }
}
