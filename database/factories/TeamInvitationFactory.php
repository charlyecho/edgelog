<?php

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TeamInvitation>
 */
class TeamInvitationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'email' => fake()->unique()->safeEmail(),
            'role' => TeamRole::Viewer,
            'token_hash' => TeamInvitation::hashToken(Str::random(40)),
            'expires_at' => now()->addDays(TeamInvitation::VALID_DAYS),
        ];
    }

    /**
     * Use the given plain token for the invitation.
     */
    public function withToken(string $token): static
    {
        return $this->state(fn (array $attributes) => [
            'token_hash' => TeamInvitation::hashToken($token),
        ]);
    }

    /**
     * Indicate that the invitation has expired.
     */
    public function expired(): static
    {
        return $this->state(fn (array $attributes) => [
            'expires_at' => now()->subMinute(),
        ]);
    }
}
