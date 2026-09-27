<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationRole;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => 'customer',
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * A member of `$organization` (a new one by default) with `$role`, working in it.
     */
    public function inOrganization(?Organization $organization = null, OrganizationRole $role = OrganizationRole::Owner): static
    {
        return $this->afterCreating(function (User $user) use ($organization, $role) {
            $organization ??= Organization::factory()->create();
            $organization->members()->attach($user->id, ['role' => $role->value]);
            $user->forceFill(['current_organization_id' => $organization->id])->save();
        });
    }

    public function admin(): static
    {
        return $this->state(fn (array $attributes) => ['role' => 'admin']);
    }
}
