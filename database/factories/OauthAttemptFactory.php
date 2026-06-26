<?php

namespace Database\Factories;

use App\Models\OauthAttempt;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OauthAttempt>
 */
class OauthAttemptFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => 'google',
            'purpose' => 'login',
            'initiated_by_user_id' => null,
            'state_hash' => hash('sha256', fake()->unique()->uuid()),
            'exchange_code_hash' => null,
            'user_id' => null,
            'expires_at' => now()->addMinutes(10),
            'consumed_at' => null,
        ];
    }

    public function facebook(): static
    {
        return $this->state(['provider' => 'facebook']);
    }

    public function withExchangeCode(string $plainCode): static
    {
        return $this->state(['exchange_code_hash' => hash('sha256', $plainCode)]);
    }

    public function forUser(User $user): static
    {
        return $this->state(['user_id' => $user->id]);
    }

    public function consumed(): static
    {
        return $this->state(['consumed_at' => now()]);
    }

    public function expired(): static
    {
        return $this->state(['expires_at' => now()->subMinute()]);
    }

    public function forLink(User $user): static
    {
        return $this->state([
            'purpose' => 'link',
            'initiated_by_user_id' => $user->id,
        ]);
    }
}
