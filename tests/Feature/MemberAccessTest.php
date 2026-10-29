<?php

namespace Tests\Feature;

use App\Enums\DayStatus;
use App\Enums\TeamRole;
use App\Enums\TrafficGroup;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MemberAccessTest extends TestCase
{
    use RefreshDatabase;

    private Team $team;

    private User $admin;

    private User $viewer;

    private Server $server;

    private Site $granted;

    private Site $hidden;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-10-24 12:00:30');
        $this->admin = User::factory()->create();
        $this->viewer = User::factory()->create();
        $this->team = Team::factory()->withMember($this->admin, TeamRole::Admin)->withMember($this->viewer)->create();
        $this->server = Server::factory()->for($this->team)->create(['hostname' => 'web-1']);
        $this->granted = Site::factory()->for($this->team)->for($this->server)->create(['name' => 'granted.example.com', 'timezone' => 'UTC']);
        $this->hidden = Site::factory()->for($this->team)->for($this->server)->create(['name' => 'hidden.example.com']);
        $this->server->metricMinutes()->create([
            'minute' => '2026-10-24 12:00:00', 'cpu' => 12.5, 'iowait' => 0, 'load1' => 0.5, 'load5' => 0.5, 'load15' => 0.5, 'cores' => 4,
            'memory_total' => 8 * 1024 ** 3, 'memory_available' => 6 * 1024 ** 3, 'swap_total' => 0, 'swap_free' => 0, 'uptime' => 3600,
            'disks' => [['mount' => '/', 'size' => 100, 'used' => 40, 'free' => 60]],
        ]);
    }

    /**
     * @param  list<Site>  $sites
     * @param  list<Server>  $servers
     */
    private function limitViewer(array $sites = [], array $servers = []): void
    {
        $this->viewer->membershipIn($this->team)->grant(true, array_map(fn (Site $site) => $site->id, $sites), array_map(fn (Server $server) => $server->id, $servers));
    }

    public function test_a_viewer_sees_everything_until_limited(): void
    {
        $this->actingAs($this->viewer)->get(route('teams.sites.show', [$this->team, $this->hidden]))->assertOk();
        $this->actingAs($this->viewer)->get(route('teams.servers.show', [$this->team, $this->server]))->assertOk();
    }

    public function test_a_limited_viewer_sees_only_the_sites_granted(): void
    {
        $this->limitViewer([$this->granted]);

        $this->actingAs($this->viewer)
            ->get(route('teams.sites.index', $this->team))
            ->assertOk()
            ->assertSee('granted.example.com')
            ->assertDontSee('hidden.example.com')
            ->assertDontSee('web-1');

        $this->actingAs($this->viewer)->get(route('teams.sites.traffic.visitors', [$this->team, $this->granted]))->assertOk();
        $this->actingAs($this->viewer)->get(route('teams.sites.show', [$this->team, $this->hidden]))->assertForbidden();
        $this->actingAs($this->viewer)->get(route('teams.sites.site.errors', [$this->team, $this->hidden]))->assertForbidden();
        $this->actingAs($this->viewer)->get(route('teams.sites.icon', [$this->team, $this->hidden]))->assertForbidden();
    }

    public function test_a_site_gives_nothing_of_its_server(): void
    {
        $this->limitViewer([$this->granted]);
        $this->granted->days()->create(['day' => '2026-10-23', 'timezone' => 'UTC', 'status' => DayStatus::Verified])
            ->groups()->create(['group' => TrafficGroup::People, 'readers' => 3, 'requests' => 9, 'views' => 3, 'visits' => 3]);

        $this->actingAs($this->viewer)->get(route('teams.servers.index', $this->team))->assertOk()->assertDontSee('web-1');
        $this->actingAs($this->viewer)->get(route('teams.servers.show', [$this->team, $this->server]))->assertForbidden();

        $this->actingAs($this->viewer)
            ->get(route('teams.sites.site.server', [$this->team, $this->granted]))
            ->assertOk()
            ->assertSee('No access to this server')
            ->assertDontSee('12.5%');

        $this->actingAs($this->viewer)
            ->get(route('teams.sites.show', [$this->team, $this->granted, 'days' => '1']))
            ->assertOk()
            ->assertDontSee('Sent over the network');
    }

    public function test_a_server_gives_nothing_of_its_sites(): void
    {
        $this->limitViewer([$this->granted], [$this->server]);

        $this->actingAs($this->viewer)
            ->get(route('teams.servers.show', [$this->team, $this->server]))
            ->assertOk()
            ->assertSee('12.5%')
            ->assertSee('granted.example.com')
            ->assertDontSee('hidden.example.com');

        $this->actingAs($this->viewer)
            ->get(route('teams.servers.index', $this->team))
            ->assertOk()
            ->assertSee('web-1')
            ->assertDontSee('hidden.example.com');

        $this->actingAs($this->viewer)
            ->get(route('teams.sites.site.server', [$this->team, $this->granted]))
            ->assertOk()
            ->assertSee('12.5%')
            ->assertDontSee('Its figures are shared with');
    }

    public function test_a_server_alone_shows_no_site(): void
    {
        $this->limitViewer([], [$this->server]);

        $this->actingAs($this->viewer)
            ->get(route('teams.sites.index', $this->team))
            ->assertOk()
            ->assertSee('No site yet.');

        $this->actingAs($this->viewer)
            ->get(route('teams.servers.show', [$this->team, $this->server]))
            ->assertOk()
            ->assertSee('No site runs on it any more.');
    }

    public function test_owners_and_admins_are_never_limited(): void
    {
        $this->admin->membershipIn($this->team)->grant(true, [$this->granted->id]);

        $this->assertFalse($this->admin->membershipIn($this->team)->limited);
        $this->actingAs($this->admin)->get(route('teams.sites.show', [$this->team, $this->hidden]))->assertOk();
    }

    public function test_a_viewer_made_admin_loses_the_limit(): void
    {
        $this->limitViewer([$this->granted]);

        $this->actingAs($this->admin)
            ->put(route('teams.members.update', [$this->team, $this->viewer]), ['role' => 'admin'])
            ->assertSessionHasNoErrors();

        $membership = $this->viewer->membershipIn($this->team);
        $this->assertFalse($membership->limited);
        $this->assertSame(0, $membership->sites()->count());
    }

    public function test_an_admin_limits_a_viewer_from_the_team_settings(): void
    {
        $this->actingAs($this->admin)
            ->get(route('teams.edit', $this->team))
            ->assertOk()
            ->assertSee(route('teams.members.access.edit', [$this->team, $this->viewer]));

        $this->actingAs($this->admin)
            ->get(route('teams.members.access.edit', [$this->team, $this->viewer]))
            ->assertOk()
            ->assertSee('granted.example.com')
            ->assertSee('web-1');

        $this->actingAs($this->admin)
            ->put(route('teams.members.access.update', [$this->team, $this->viewer]), [
                'access' => 'limited',
                'sites' => [$this->granted->uuid],
                'servers' => [$this->server->uuid],
            ])
            ->assertRedirect(route('teams.edit', $this->team));

        $membership = $this->viewer->membershipIn($this->team);
        $this->assertTrue($membership->isLimited());
        $this->assertSame([$this->granted->id], $membership->sites()->pluck('sites.id')->all());
        $this->assertSame([$this->server->id], $membership->servers()->pluck('servers.id')->all());

        $this->actingAs($this->admin)
            ->get(route('teams.edit', $this->team))
            ->assertSee('1 site, 1 server');

        $this->actingAs($this->admin)
            ->put(route('teams.members.access.update', [$this->team, $this->viewer]), ['access' => 'all', 'sites' => [$this->granted->uuid]])
            ->assertRedirect();

        $this->assertFalse($this->viewer->membershipIn($this->team)->isLimited());
        $this->assertSame(0, $this->viewer->membershipIn($this->team)->sites()->count());
    }

    public function test_a_site_of_another_team_cannot_be_granted(): void
    {
        $elsewhere = Site::factory()->create();

        $this->actingAs($this->admin)
            ->put(route('teams.members.access.update', [$this->team, $this->viewer]), ['access' => 'limited', 'sites' => [$elsewhere->uuid]])
            ->assertSessionHasErrors('sites.0');

        $this->assertFalse($this->viewer->membershipIn($this->team)->isLimited());
    }

    public function test_a_viewer_cannot_change_access_and_an_admin_has_none_to_change(): void
    {
        $this->actingAs($this->viewer)
            ->put(route('teams.members.access.update', [$this->team, $this->viewer]), ['access' => 'all'])
            ->assertForbidden();

        $other = User::factory()->create();
        $this->team->members()->attach($other, ['role' => TeamRole::Admin->value]);

        $this->actingAs($this->admin)->get(route('teams.members.access.edit', [$this->team, $other]))->assertNotFound();
    }

    public function test_an_invitation_carries_the_access_to_the_member(): void
    {
        Mail::fake();

        $url = $this->actingAs($this->admin)
            ->post(route('teams.invitations.store', $this->team), [
                'email' => 'new@example.com',
                'role' => 'viewer',
                'access' => 'limited',
                'sites' => [$this->granted->uuid],
                'servers' => [$this->server->uuid],
            ])
            ->assertSessionHasNoErrors()
            ->getSession()->get('invitation_url');

        $this->actingAs($this->admin)->get(route('teams.edit', $this->team))->assertSee('1 site, 1 server');

        $newcomer = User::factory()->create(['email' => 'new@example.com']);
        $this->actingAs($newcomer)->post(route('invitations.accept', basename($url)))->assertRedirect(route('dashboard'));

        $membership = $newcomer->membershipIn($this->team);
        $this->assertTrue($membership->isLimited());
        $this->assertSame([$this->granted->id], $membership->sites()->pluck('sites.id')->all());
        $this->assertSame([$this->server->id], $membership->servers()->pluck('servers.id')->all());
        $this->actingAs($newcomer)->get(route('teams.sites.show', [$this->team, $this->hidden]))->assertForbidden();
    }

    public function test_an_admin_invitation_is_never_limited(): void
    {
        Mail::fake();

        $this->actingAs($this->admin)
            ->post(route('teams.invitations.store', $this->team), ['email' => 'new@example.com', 'role' => 'admin', 'access' => 'limited', 'sites' => [$this->granted->uuid]])
            ->assertSessionHasNoErrors();

        $this->assertFalse($this->team->invitations()->sole()->limited);
    }

    public function test_a_site_moved_to_another_team_leaves_the_viewers_it_was_granted_to(): void
    {
        $this->limitViewer([$this->granted]);
        $target = Team::factory()->withMember($this->admin, TeamRole::Owner)->create();

        $this->actingAs($this->admin)
            ->put(route('teams.sites.team.update', [$this->team, $this->granted]), ['team' => $target->uuid])
            ->assertRedirect();

        $this->assertSame(0, $this->viewer->membershipIn($this->team)->sites()->count());
    }
}
