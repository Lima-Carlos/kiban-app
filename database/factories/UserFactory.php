<?php

namespace Database\Factories;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'core_id' => fake()->unique()->numberBetween(1, 1_000_000),
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'locale' => 'pt_BR',
        ];
    }

    /**
     * Member of a company with the given role (owner, admin or member).
     */
    public function memberOf(Tenant $tenant, string $role = 'member'): static
    {
        return $this->afterCreating(fn (User $user) => $user->tenants()->attach($tenant, ['role' => $role]));
    }
}
