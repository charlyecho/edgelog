<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_the_user_with_a_team_they_own(): void
    {
        $response = $this->post(route('register'), [
            'name' => 'Ada Lovelace',
            'email' => 'Ada@Example.com',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
            'team' => 'Analytical Engines',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticated();

        $user = User::firstWhere('email', 'ada@example.com');
        $this->assertSame('Analytical Engines', $user->currentTeam->name);
        $this->assertSame(TeamRole::Owner, $user->roleIn($user->currentTeam));
    }

    public function test_the_team_name_defaults_to_the_users_name(): void
    {
        $this->post(route('register'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ]);

        $this->assertSame("Ada Lovelace's Team", User::firstWhere('email', 'ada@example.com')->currentTeam->name);
    }

    public function test_passwords_shorter_than_twelve_characters_are_refused(): void
    {
        $this->post(route('register'), [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'short-pass1',
            'password_confirmation' => 'short-pass1',
        ])->assertSessionHasErrors('password');

        $this->assertGuest();
    }

    public function test_the_dashboard_sends_a_user_without_a_team_to_create_one(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('dashboard'))
            ->assertRedirect(route('teams.create'));
    }
}
