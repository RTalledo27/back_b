<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UserInvitation>
 */
class UserInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'invited_by_user_id' => User::factory()->admin(),
            'token_hash' => hash('sha256', Str::random(64)),
            'expires_at' => now()->addDay(),
            'consumed_at' => null,
            'revoked_at' => null,
        ];
    }

    public function consumed(): static
    {
        return $this->state(fn (array $attributes) => [
            'consumed_at' => now(),
            'revoked_at' => null,
        ]);
    }

    public function revoked(): static
    {
        return $this->state(fn (array $attributes) => [
            'consumed_at' => null,
            'revoked_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
