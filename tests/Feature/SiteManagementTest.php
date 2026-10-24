<?php

namespace Tests\Feature;

use App\Enums\TeamRole;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Create a user holding the given role in a new team.
     *
     * @return array{0: User, 1: Team}
     */
    private function memberOf(TeamRole $role): array
    {
        $user = User::factory()->create();
        $team = Team::factory()->withMember($user, $role)->create();

        return [$user, $team];
    }

    public function test_admins_can_add_a_site_and_see_its_token_once(): void
    {
        [$admin, $team] = $this->memberOf(TeamRole::Admin);

        $response = $this->actingAs($admin)->post(route('teams.sites.store', $team), [
            'url' => 'Example.COM/some/page',
        ]);

        $site = $team->sites()->sole();
        $response->assertRedirect(route('teams.sites.edit', [$team, $site]));
        $this->assertSame('https://example.com', $site->url);
        $this->assertSame('example.com', $site->name);
        $this->assertTrue(str($site->uuid)->isUuid());

        $token = $response->getSession()->get('site_token');
        $this->assertStringStartsWith(Site::TOKEN_PREFIX, $token);
        $this->assertDatabaseMissing('sites', ['token_hash' => $token]);
        $this->assertTrue(Site::findByToken($token)->is($site));
    }

    public function test_viewers_can_see_sites_but_not_add_them(): void
    {
        [$viewer, $team] = $this->memberOf(TeamRole::Viewer);
        $site = Site::factory()->for($team)->create();

        $this->actingAs($viewer)->get(route('teams.sites.index', $team))->assertOk()->assertSee($site->name);
        $this->actingAs($viewer)->get(route('teams.sites.show', [$team, $site]))->assertOk();
        $this->actingAs($viewer)->get(route('teams.sites.edit', [$team, $site]))->assertForbidden();
        $this->actingAs($viewer)
            ->post(route('teams.sites.store', $team), ['url' => 'https://example.com'])
            ->assertForbidden();
    }

    public function test_site_urls_use_the_team_and_site_uuids(): void
    {
        $site = Site::factory()->create();

        $this->assertSame(
            url("/teams/{$site->team->uuid}/sites/{$site->uuid}"),
            route('teams.sites.show', [$site->team, $site]),
        );
    }

    public function test_a_site_is_not_reachable_under_another_team(): void
    {
        [$owner, $team] = $this->memberOf(TeamRole::Owner);
        $site = Site::factory()->create();

        $this->actingAs($owner)->get("/teams/{$team->uuid}/sites/{$site->uuid}")->assertNotFound();
    }

    public function test_non_members_cannot_see_a_site(): void
    {
        $site = Site::factory()->create();

        $this->actingAs(User::factory()->create())
            ->get(route('teams.sites.show', [$site->team, $site]))
            ->assertForbidden();
    }

    public function test_a_url_cannot_be_registered_twice(): void
    {
        Site::factory()->create(['url' => 'https://example.com']);
        [$admin, $team] = $this->memberOf(TeamRole::Admin);

        $this->actingAs($admin)
            ->post(route('teams.sites.store', $team), ['url' => 'https://EXAMPLE.com/'])
            ->assertSessionHasErrors('url');
    }

    public function test_invalid_urls_are_refused(): void
    {
        [$admin, $team] = $this->memberOf(TeamRole::Admin);

        $this->actingAs($admin)
            ->post(route('teams.sites.store', $team), ['url' => 'ftp://example.com'])
            ->assertSessionHasErrors('url');
    }

    public function test_admins_can_rename_a_site(): void
    {
        [$admin, $team] = $this->memberOf(TeamRole::Admin);
        $site = Site::factory()->for($team)->create(['url' => 'https://example.com']);

        $this->actingAs($admin)
            ->put(route('teams.sites.update', [$team, $site]), ['url' => 'https://example.com', 'name' => 'Marketing'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Marketing', $site->fresh()->name);
    }

    public function test_generating_a_new_token_stops_the_previous_one(): void
    {
        [$admin, $team] = $this->memberOf(TeamRole::Admin);
        $site = Site::factory()->for($team)->make();
        $oldToken = $site->fillNewToken();
        $site->save();

        $newToken = $this->actingAs($admin)
            ->post(route('teams.sites.token.store', [$team, $site]))
            ->getSession()->get('site_token');

        $this->assertNull(Site::findByToken($oldToken));
        $this->assertTrue(Site::findByToken($newToken)->is($site));
    }

    public function test_a_site_can_be_moved_to_another_team_the_user_manages(): void
    {
        [$admin, $team] = $this->memberOf(TeamRole::Admin);
        $target = Team::factory()->withMember($admin, TeamRole::Owner)->create();
        $site = Site::factory()->for($team)->create();

        $this->actingAs($admin)
            ->put(route('teams.sites.team.update', [$team, $site]), ['team' => $target->uuid])
            ->assertRedirect(route('teams.sites.edit', [$target, $site]));

        $this->assertTrue($site->fresh()->team->is($target));
    }

    public function test_a_site_cannot_be_moved_to_a_team_where_the_user_is_viewer(): void
    {
        [$admin, $team] = $this->memberOf(TeamRole::Admin);
        $target = Team::factory()->withMember($admin, TeamRole::Viewer)->create();
        $site = Site::factory()->for($team)->create();

        $this->actingAs($admin)
            ->put(route('teams.sites.team.update', [$team, $site]), ['team' => $target->uuid])
            ->assertForbidden();

        $this->assertTrue($site->fresh()->team->is($team));
    }

    public function test_a_site_cannot_be_moved_to_a_team_the_user_is_not_in(): void
    {
        [$owner, $team] = $this->memberOf(TeamRole::Owner);
        $site = Site::factory()->for($team)->create();

        $this->actingAs($owner)
            ->put(route('teams.sites.team.update', [$team, $site]), ['team' => Team::factory()->create()->uuid])
            ->assertNotFound();
    }

    public function test_admins_can_delete_a_site(): void
    {
        [$admin, $team] = $this->memberOf(TeamRole::Admin);
        $site = Site::factory()->for($team)->create();

        $this->actingAs($admin)
            ->delete(route('teams.sites.destroy', [$team, $site]))
            ->assertRedirect(route('teams.sites.index', $team));

        $this->assertModelMissing($site);
    }
}
