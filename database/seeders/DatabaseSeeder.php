<?php

namespace Database\Seeders;

use App\Models\Site;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $user = User::factory()->withTeam()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Site::factory()->for($user->currentTeam)->create(['name' => 'example.com', 'url' => 'https://example.com']);
        Site::factory()->for($user->currentTeam)->create(['name' => 'shop.example.com', 'url' => 'https://shop.example.com']);
    }
}
