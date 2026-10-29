<?php

namespace Database\Factories;

use App\Models\Server;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Server>
 */
class ServerFactory extends Factory
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
            'machine_id' => fake()->unique()->md5(),
            'hostname' => fake()->unique()->domainWord(),
        ];
    }
}
