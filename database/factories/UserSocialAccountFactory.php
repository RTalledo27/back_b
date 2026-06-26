<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\UserSocialAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserSocialAccount>
 */
class UserSocialAccountFactory extends Factory
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
            'provider' => 'google',
            'provider_user_id' => fake()->unique()->uuid(),
            'provider_email' => fake()->unique()->safeEmail(),
            'provider_email_verified_at' => now(),
        ];
    }

    public function facebook(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider' => 'facebook',
        ]);
    }

    public function withoutVerifiedEmail(): static
    {
        return $this->state(fn (array $attributes) => [
            'provider_email_verified_at' => null,
        ]);
    }
}
