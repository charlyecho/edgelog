<?php

namespace Database\Factories;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Team>
 */
class TeamFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->company(),
        ];
    }

    /**
     * Attach the given user to the team with the given role.
     */
    public function withMember(User $user, TeamRole $role = TeamRole::Viewer): static
    {
        return $this->hasAttached($user, ['role' => $role->value], 'members');
    }
}
