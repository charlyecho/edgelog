<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a team whose members hold the given roles.
     *
     * @param  array<string, TeamRole>  $roles
     * @return array{0: Team, 1: array<string, User>}
     */
    private function teamWith(array $roles): array
    {
        $users = array_map(fn () => User::factory()->create(), $roles);
        $factory = Team::factory();

        foreach ($roles as $key => $role) {
            $factory = $factory->withMember($users[$key], $role);
        }

        $team = $factory->create();

        foreach ($users as $user) {
            $user->switchTeam($team);
        }

        return [$team, $users];
    }

    public function test_a_user_can_create_a_team_and_becomes_its_owner(): void
    {
        $user = User::factory()->withTeam()->create();

        $this->actingAs($user)
            ->post(route('teams.store'), ['name' => 'Second team'])
            ->assertRedirect();

        $team = Team::firstWhere('name', 'Second team');
        $this->assertSame(TeamRole::Owner, $user->roleIn($team));
        $this->assertTrue($user->fresh()->currentTeam->is($team));
    }

    public function test_members_can_see_the_team_page_and_others_cannot(): void
    {
        [$team, $users] = $this->teamWith(['viewer' => TeamRole::Viewer]);

        $this->actingAs($users['viewer'])->get(route('teams.show', $team))->assertOk()->assertSee($team->name);
        $this->actingAs(User::factory()->create())->get(route('teams.show', $team))->assertForbidden();
    }

    public function test_team_urls_use_the_uuid(): void
    {
        [$team] = $this->teamWith(['owner' => TeamRole::Owner]);

        $this->assertSame(url("/teams/{$team->uuid}"), route('teams.show', $team));
        $this->assertTrue(str($team->uuid)->isUuid());
    }

    public function test_settings_are_shown_to_members(): void
    {
        [$team, $users] = $this->teamWith(['viewer' => TeamRole::Viewer]);

        $this->actingAs($users['viewer'])->get(route('teams.edit', $team))->assertOk()->assertSee($users['viewer']->email);
        $this->actingAs(User::factory()->create())->get(route('teams.edit', $team))->assertForbidden();
    }

    public function test_opening_a_team_makes_it_the_current_team(): void
    {
        $user = User::factory()->withTeam()->create();
        $team = Team::factory()->withMember($user, TeamRole::Viewer)->create();

        $this->actingAs($user)->get(route('teams.show', $team))->assertOk();

        $this->assertTrue($user->fresh()->currentTeam->is($team));
    }

    public function test_the_dashboard_redirects_to_the_last_used_team(): void
    {
        $user = User::factory()->withTeam()->create();
        $team = Team::factory()->withMember($user, TeamRole::Viewer)->create();
        $this->actingAs($user)->get(route('teams.show', $team));

        $this->actingAs($user)->get(route('dashboard'))->assertRedirect(route('teams.show', $team));
    }

    public function test_opening_a_team_of_someone_else_does_not_change_the_current_team(): void
    {
        $user = User::factory()->withTeam()->create();
        $ownTeam = $user->currentTeam;
        [$other] = $this->teamWith(['someone' => TeamRole::Owner]);

        $this->actingAs($user)->get(route('teams.show', $other))->assertForbidden();

        $this->assertTrue($user->fresh()->currentTeam->is($ownTeam));
    }

    public function test_viewers_cannot_rename_the_team(): void
    {
        [$team, $users] = $this->teamWith(['viewer' => TeamRole::Viewer]);

        $this->actingAs($users['viewer'])
            ->put(route('teams.update', $team), ['name' => 'Renamed'])
            ->assertForbidden();
    }

    public function test_admins_can_rename_the_team(): void
    {
        [$team, $users] = $this->teamWith(['admin' => TeamRole::Admin]);

        $this->actingAs($users['admin'])->put(route('teams.update', $team), ['name' => 'Renamed']);

        $this->assertSame('Renamed', $team->fresh()->name);
    }

    public function test_admins_can_change_viewers_but_not_owners(): void
    {
        [$team, $users] = $this->teamWith([
            'owner' => TeamRole::Owner,
            'admin' => TeamRole::Admin,
            'viewer' => TeamRole::Viewer,
        ]);

        $this->actingAs($users['admin'])
            ->put(route('teams.members.update', [$team, $users['viewer']]), ['role' => 'admin'])
            ->assertSessionHasNoErrors();
        $this->assertSame(TeamRole::Admin, $users['viewer']->roleIn($team));

        $this->actingAs($users['admin'])
            ->put(route('teams.members.update', [$team, $users['owner']]), ['role' => 'viewer'])
            ->assertForbidden();
    }

    public function test_admins_cannot_make_someone_owner(): void
    {
        [$team, $users] = $this->teamWith([
            'admin' => TeamRole::Admin,
            'viewer' => TeamRole::Viewer,
        ]);

        $this->actingAs($users['admin'])
            ->put(route('teams.members.update', [$team, $users['viewer']]), ['role' => 'owner'])
            ->assertSessionHasErrors('role');

        $this->assertSame(TeamRole::Viewer, $users['viewer']->roleIn($team));
    }

    public function test_the_last_owner_cannot_be_demoted_or_leave(): void
    {
        [$team, $users] = $this->teamWith(['owner' => TeamRole::Owner]);

        $this->actingAs($users['owner'])
            ->put(route('teams.members.update', [$team, $users['owner']]), ['role' => 'admin'])
            ->assertSessionHasErrors('role');

        $this->actingAs($users['owner'])
            ->delete(route('teams.members.destroy', [$team, $users['owner']]))
            ->assertSessionHasErrors('member');

        $this->assertSame(TeamRole::Owner, $users['owner']->roleIn($team));
    }

    public function test_an_owner_can_step_down_when_another_owner_remains(): void
    {
        [$team, $users] = $this->teamWith([
            'first' => TeamRole::Owner,
            'second' => TeamRole::Owner,
        ]);

        $this->actingAs($users['first'])
            ->put(route('teams.members.update', [$team, $users['first']]), ['role' => 'viewer'])
            ->assertSessionHasNoErrors();

        $this->assertSame(TeamRole::Viewer, $users['first']->roleIn($team));
    }

    public function test_a_member_can_leave_and_is_moved_to_another_team(): void
    {
        $viewer = User::factory()->withTeam()->create();
        $personalTeam = $viewer->currentTeam;
        [$team] = $this->teamWith(['owner' => TeamRole::Owner]);
        $team->members()->attach($viewer, ['role' => TeamRole::Viewer->value]);
        $viewer->switchTeam($team);

        $this->actingAs($viewer)
            ->delete(route('teams.members.destroy', [$team, $viewer]))
            ->assertRedirect(route('dashboard'));

        $this->assertFalse($team->hasMember($viewer));
        $this->assertTrue($viewer->fresh()->currentTeam->is($personalTeam));
    }

    public function test_viewers_cannot_remove_other_members(): void
    {
        [$team, $users] = $this->teamWith([
            'viewer' => TeamRole::Viewer,
            'other' => TeamRole::Viewer,
        ]);

        $this->actingAs($users['viewer'])
            ->delete(route('teams.members.destroy', [$team, $users['other']]))
            ->assertForbidden();
    }

    public function test_only_owners_can_delete_the_team(): void
    {
        [$team, $users] = $this->teamWith([
            'owner' => TeamRole::Owner,
            'admin' => TeamRole::Admin,
        ]);

        $this->actingAs($users['admin'])->delete(route('teams.destroy', $team))->assertForbidden();

        $this->actingAs($users['owner'])->delete(route('teams.destroy', $team))->assertRedirect(route('dashboard'));

        $this->assertModelMissing($team);
        $this->assertNull($users['admin']->fresh()->current_team_id);
    }
}
