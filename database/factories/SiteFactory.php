<?php

namespace Database\Factories;

use App\Models\Site;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Site>
 */
class SiteFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $host = fake()->unique()->domainName();

        return [
            'team_id' => Team::factory(),
            'name' => $host,
            'url' => "https://{$host}",
        ];
    }

    /**
     * Configure the model factory.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (Site $site) {
            if ($site->token_hash === null) {
                $site->fillNewToken();
            }
        });
    }
}
