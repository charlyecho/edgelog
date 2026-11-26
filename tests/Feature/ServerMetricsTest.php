<?php

namespace Tests\Feature;

use App\Analytics\Server\ServerRange;
use App\Analytics\SitePeriod;
use App\Enums\DayStatus;
use App\Enums\TeamRole;
use App\Enums\TrafficGroup;
use App\Models\Server;
use App\Models\ServerMetricMinute;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ServerMetricsTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.min_free_gb' => 0]);
        $this->travelTo('2026-10-23 08:30:20');

        $this->site = Site::factory()->make(['timezone' => 'UTC']);
        $this->token = $this->site->fillNewToken();
        $this->site->save();
    }

    /**
     * A sample the way edgelog-ship --metrics writes it.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function sample(array $changes = []): array
    {
        return [
            'uptime' => 90061,
            'cores' => 4,
            'cpu' => 12.5,
            'iowait' => 0.5,
            'load1' => 0.42,
            'load5' => 0.3,
            'load15' => 0.25,
            'memory_total' => 8 * 1024 ** 3,
            'memory_available' => 6 * 1024 ** 3,
            'swap_total' => 2 * 1024 ** 3,
            'swap_free' => 2 * 1024 ** 3,
            'disk_read' => 4096,
            'disk_write' => 81920,
            'network_in' => 12000,
            'network_out' => 48000,
            'disks' => [
                ['mount' => '/', 'size' => 100 * 1024 ** 3, 'used' => 91 * 1024 ** 3, 'free' => 4 * 1024 ** 3],
                ['mount' => '/boot/efi', 'size' => 512 * 1024 ** 2, 'used' => 6 * 1024 ** 2, 'free' => 506 * 1024 ** 2],
            ],
            ...$changes,
        ];
    }

    /**
     * Post a sample the way the shipper does: gzip'd JSON with the site token.
     */
    private function send(array|string $sample, ?string $token = null, ?Site $site = null): TestResponse
    {
        $headers = [
            'Authorization' => 'Bearer '.($token ?? $this->token),
            'Content-Type' => 'application/json',
            'Content-Encoding' => 'gzip',
            'X-Edgelog-Metrics' => '1',
        ];
        $body = gzencode(is_string($sample) ? $sample : json_encode($sample));

        return $this->call('POST', route('ingest.metrics', $site ?? $this->site), [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /**
     * The site's server, made the way its first sample makes it.
     */
    private function server(): Server
    {
        if ($this->site->server === null) {
            $this->site->server()->associate(Server::factory()->for($this->site->team)->create())->save();
        }

        return $this->site->server;
    }

    private function viewer(): User
    {
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        return $viewer;
    }

    public function test_a_sample_is_kept_for_the_minute_it_came_in(): void
    {
        $this->send($this->sample())->assertOk()->assertJson(['ok' => true, 'minute' => '2026-10-23T08:30:00+00:00']);

        $minute = $this->site->refresh()->server->metricMinutes()->sole();
        $this->assertSame('2026-10-23 08:30:00', $minute->minute->toDateTimeString());
        $this->assertSame(12.5, $minute->cpu);
        $this->assertSame(2 * 1024 ** 3, $minute->memoryUsed());
        $this->assertSame('/', $minute->disks[0]['mount']);
    }

    public function test_a_second_sample_in_the_same_minute_takes_the_place_of_the_first(): void
    {
        $this->send($this->sample(['cpu' => 10]))->assertOk();
        $this->travel(30)->seconds();
        $this->send($this->sample(['cpu' => 20]))->assertOk();

        $this->assertSame(20.0, $this->site->refresh()->server->metricMinutes()->sole()->cpu);
    }

    public function test_the_first_sample_after_a_start_may_have_no_rates(): void
    {
        $this->send($this->sample(['cpu' => null, 'iowait' => null, 'disk_read' => null, 'network_out' => null]))->assertOk();

        $this->assertNull($this->site->refresh()->server->metricMinutes()->sole()->cpu);
    }

    public function test_a_sample_that_does_not_read_is_refused(): void
    {
        $this->send('not json')->assertStatus(400)->assertJson(['reason' => 'bad_sample']);
        $this->send($this->sample(['cpu' => 140]))->assertStatus(400);
        $this->send($this->sample(['memory_total' => 0]))->assertStatus(400);
        $this->send($this->sample(['disks' => array_fill(0, 33, ['mount' => '/', 'size' => 1, 'used' => 1, 'free' => 0])]))->assertStatus(400);

        $this->assertSame(0, ServerMetricMinute::query()->count());
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $this->send($this->sample(), 'edg_nope')->assertStatus(401);

        $this->assertSame(0, ServerMetricMinute::query()->count());
    }

    public function test_the_hours_that_are_over_are_summed_up_and_old_figures_forgotten(): void
    {
        foreach (['08:00' => 10, '08:01' => 30, '08:59' => 20, '09:00' => 90] as $time => $cpu) {
            $this->server()->metricMinutes()->create(['minute' => "2026-10-23 {$time}:00", ...$this->sample(['cpu' => $cpu, 'load1' => $cpu / 10, 'memory_available' => (8 - $cpu / 10) * 1024 ** 3])]);
        }
        $this->server()->metricMinutes()->create(['minute' => '2026-10-15 08:00:00', ...$this->sample()]);
        $this->server()->metricHours()->create(['hour' => '2025-09-01 00:00:00', 'samples' => 1, 'load_average' => 0, 'load_peak' => 0, 'cores' => 1,
            'memory_total' => 1, 'memory_used_average' => 0, 'memory_used_peak' => 0, 'swap_total' => 0, 'swap_used_peak' => 0, 'disks' => []]);

        $this->travelTo('2026-10-23 09:02:00');
        $this->artisan('edgelog:roll-metrics')->assertSuccessful();
        $this->artisan('edgelog:roll-metrics')->assertSuccessful();

        $hour = $this->server()->metricHours()->sole();
        $this->assertSame('2026-10-23 08:00:00', $hour->hour->toDateTimeString());
        $this->assertSame(3, $hour->samples);
        $this->assertSame(20.0, $hour->cpu_average);
        $this->assertSame(30.0, $hour->cpu_peak);
        $this->assertSame(3.0, $hour->load_peak);
        $this->assertSame(3 * 1024 ** 3, $hour->memory_used_peak);
        $this->assertSame(4, $this->server()->metricMinutes()->count(), 'the minute of 15 October is past the week kept');
    }

    public function test_the_server_tab_explains_how_to_start_without_figures(): void
    {
        $this->actingAs($this->viewer())
            ->get(route('teams.sites.site.server', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('No figures from the server yet')
            ->assertSee('--print-metrics');
    }

    public function test_the_server_tab_shows_the_last_minute_and_the_disks(): void
    {
        $this->send($this->sample())->assertOk();
        $viewer = $this->viewer();

        $this->actingAs($viewer)
            ->get(route('teams.sites.site.server', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('12.5%')
            ->assertSee('/boot/efi')
            ->assertSee('95.8%')
            ->assertDontSee('The server has gone quiet');

        $this->travel(10)->minutes();

        $this->actingAs($viewer)
            ->get(route('teams.sites.site.server', [$this->site->team, $this->site, 'range' => '30d']))
            ->assertOk()
            ->assertSee('The server has gone quiet');
    }

    public function test_the_server_tab_is_for_the_sites_team_only(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('teams.sites.site.server', [$this->site->team, $this->site]))
            ->assertForbidden();
    }

    public function test_the_overview_sums_up_the_server_over_its_period_against_the_one_before(): void
    {
        $this->site->days()->create(['day' => '2026-10-22', 'timezone' => 'UTC', 'status' => DayStatus::Verified])
            ->groups()->create(['group' => TrafficGroup::People, 'readers' => 3, 'requests' => 9, 'views' => 3, 'visits' => 3]);
        $this->server()->metricMinutes()->create(['minute' => '2026-10-22 10:00:00', ...$this->sample(['cpu' => 33.3])]);
        $this->server()->metricMinutes()->create(['minute' => '2026-10-21 10:00:00', ...$this->sample(['cpu' => 77.7])]);

        $this->actingAs($this->viewer())
            ->get(route('teams.sites.show', [$this->site->team, $this->site, 'days' => '1']))
            ->assertOk()
            ->assertSee('Sent over the network')
            ->assertSee('33.3%')
            ->assertSee('from 77.7%')
            ->assertSee('/ 95.8% full, 4 GB free');
    }

    public function test_the_overview_shows_no_server_without_figures(): void
    {
        $this->site->days()->create(['day' => '2026-10-22', 'timezone' => 'UTC', 'status' => DayStatus::Verified])
            ->groups()->create(['group' => TrafficGroup::People, 'readers' => 3, 'requests' => 9, 'views' => 3, 'visits' => 3]);

        $this->actingAs($this->viewer())
            ->get(route('teams.sites.show', [$this->site->team, $this->site, 'days' => '1']))
            ->assertOk()
            ->assertDontSee('Sent over the network');
    }

    public function test_a_period_starts_at_midnight_where_the_site_is(): void
    {
        $this->site->update(['timezone' => 'Europe/Paris']);
        $this->server()->metricHours()->create(['hour' => '2026-10-20 13:00:00', 'samples' => 60, 'cpu_average' => 40, 'load_average' => 1, 'load_peak' => 2, 'cores' => 4,
            'memory_total' => 8, 'memory_used_average' => 4, 'memory_used_peak' => 6, 'swap_total' => 0, 'swap_used_peak' => 0, 'disks' => []]);

        $server = ServerRange::overPeriod($this->server(), SitePeriod::lastDays($this->site, 30));

        $this->assertSame('2026-10-23 00:00:00', $server->until->setTimezone('Europe/Paris')->toDateTimeString());
        $this->assertSame(3 * 3600, $server->step());
        $this->assertSame([40.0], array_values(array_filter(array_column($server->points(), 'cpu'))));
    }

    public function test_the_shipper_reads_a_sample_the_endpoint_takes(): void
    {
        if (! is_readable('/proc/stat')) {
            $this->markTestSkipped('No /proc on this system.');
        }

        $result = Process::run(['sh', base_path('tools/edgelog-ship'), '--print-metrics']);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->send($result->output())->assertOk();
        $this->assertNotNull($this->site->refresh()->server->metricMinutes()->sole()->cpu);
    }

    /**
     * Another site of the same team, with its own token.
     *
     * @return array{0: Site, 1: string}
     */
    private function otherSite(?Site $of = null): array
    {
        $site = Site::factory()->for(($of ?? $this->site)->team)->make();
        $token = $site->fillNewToken();
        $site->save();

        return [$site, $token];
    }

    public function test_the_sites_of_one_machine_share_its_server_and_its_figures_are_kept_once(): void
    {
        [$other, $otherToken] = $this->otherSite();
        $machine = ['machine_id' => str_repeat('a1', 16), 'hostname' => 'web-1'];

        $this->send($this->sample([...$machine, 'cpu' => 10]))->assertOk();
        $this->travel(20)->seconds();
        $this->send($this->sample([...$machine, 'cpu' => 30]), $otherToken, $other)->assertOk();

        $server = Server::query()->sole();
        $this->assertSame('web-1', $server->hostname);
        $this->assertSame([$this->site->id, $other->id], $server->sites()->orderBy('id')->pluck('id')->all());
        $this->assertSame(30.0, $server->metricMinutes()->sole()->cpu, 'one row a minute, whichever shipper sent it');
    }

    public function test_the_same_machine_in_another_team_is_another_server(): void
    {
        $stranger = Site::factory()->make();
        $strangerToken = $stranger->fillNewToken();
        $stranger->save();
        $machine = ['machine_id' => str_repeat('b2', 16)];

        $this->send($this->sample($machine))->assertOk();
        $this->send($this->sample($machine), $strangerToken, $stranger)->assertOk();

        $this->assertSame(2, Server::query()->count());
        $this->assertFalse($this->site->fresh()->server->is($stranger->fresh()->server));
    }

    public function test_a_shipper_that_sends_no_machine_keeps_the_sites_own_server(): void
    {
        $this->send($this->sample())->assertOk();
        $this->travel(1)->minutes();
        $this->send($this->sample())->assertOk();

        $server = Server::query()->sole();
        $this->assertNull($server->machine_id);
        $this->assertSame(2, $server->metricMinutes()->count());
    }

    public function test_a_server_made_before_machine_ids_takes_the_first_one_sent(): void
    {
        $this->send($this->sample())->assertOk();
        $server = $this->site->fresh()->server;

        $this->travel(1)->minutes();
        $this->send($this->sample(['machine_id' => str_repeat('c3', 16), 'hostname' => 'web-2']))->assertOk();

        $this->assertTrue($this->site->fresh()->server->is($server));
        $this->assertSame(str_repeat('c3', 16), $server->fresh()->machine_id);
        $this->assertSame('web-2', $server->fresh()->hostname);
    }

    public function test_a_machine_id_that_is_not_a_hash_is_refused(): void
    {
        $this->send($this->sample(['machine_id' => 'not a hash']))->assertStatus(400);

        $this->assertSame(0, Server::query()->count());
    }

    public function test_the_servers_page_lists_each_server_with_its_sites(): void
    {
        [$other, $otherToken] = $this->otherSite();
        $machine = ['machine_id' => str_repeat('d4', 16), 'hostname' => 'web-3'];
        $this->send($this->sample($machine))->assertOk();
        $this->send($this->sample($machine), $otherToken, $other)->assertOk();
        [$waiting] = $this->otherSite();

        $this->actingAs($this->viewer())
            ->get(route('teams.servers.index', $this->site->team))
            ->assertOk()
            ->assertSee('web-3')
            ->assertSee($this->site->name)
            ->assertSee($other->name)
            ->assertSee('Disk / 96%')
            ->assertSee('No log yet')
            ->assertSee('No known server')
            ->assertSee(route('teams.sites.show', [$this->site->team, $other]), false)
            ->assertSee(route('teams.sites.show', [$this->site->team, $waiting]), false);
    }

    public function test_the_server_page_shows_its_figures_and_its_sites(): void
    {
        [$other, $otherToken] = $this->otherSite();
        $machine = ['machine_id' => str_repeat('e5', 16), 'hostname' => 'web-4'];
        $this->send($this->sample($machine))->assertOk();
        $this->send($this->sample($machine), $otherToken, $other)->assertOk();
        $server = Server::query()->sole();

        $this->actingAs($this->viewer())
            ->get(route('teams.servers.show', [$this->site->team, $server]))
            ->assertOk()
            ->assertSee('web-4')
            ->assertSee('The 2 sites it runs')
            ->assertSee('No log yet')
            ->assertSee($other->name)
            ->assertSee('12.5%')
            ->assertSee('/boot/efi')
            ->assertSeeInOrder(['The machine', '4 cores', '8 GB', '2 GB', '/', '100 GB', '/boot/efi', '512 MB'])
            ->assertDontSee('Forget the server');

        $this->actingAs($this->viewer())
            ->get(route('teams.sites.site.server', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('Runs on')
            ->assertSee("Its figures are shared with 1 other site: {$other->name}.");
    }

    public function test_the_server_page_is_for_its_team_only(): void
    {
        $this->send($this->sample())->assertOk();
        $server = Server::query()->sole();
        $stranger = User::factory()->create();
        $strangersTeam = Team::factory()->withMember($stranger)->create();

        $this->actingAs($stranger)->get(route('teams.servers.show', [$this->site->team, $server]))->assertForbidden();
        $this->actingAs($stranger)->get(route('teams.servers.show', [$strangersTeam, $server]))->assertNotFound();
    }

    public function test_a_manager_forgets_a_server_and_its_figures_but_not_its_sites(): void
    {
        $this->send($this->sample())->assertOk();
        $server = Server::query()->sole();
        $team = $this->site->team;

        $this->actingAs($this->viewer())->delete(route('teams.servers.destroy', [$team, $server]))->assertForbidden();

        $admin = User::factory()->create();
        $team->members()->attach($admin, ['role' => TeamRole::Admin]);

        $this->actingAs($admin)
            ->delete(route('teams.servers.destroy', [$team, $server]))
            ->assertRedirect(route('teams.servers.index', $team));

        $this->assertModelMissing($server);
        $this->assertSame(0, ServerMetricMinute::query()->count());
        $this->assertNull($this->site->fresh()->server_id);
    }

    private function admin(): User
    {
        $admin = User::factory()->create();
        $this->site->team->members()->attach($admin, ['role' => TeamRole::Admin->value]);

        return $admin;
    }

    public function test_a_site_put_on_a_server_by_hand_stays_there_whatever_its_shipper_says(): void
    {
        [$other, $otherToken] = $this->otherSite();
        $this->send($this->sample(['machine_id' => str_repeat('a1', 16)]))->assertOk();
        $this->send($this->sample(['machine_id' => str_repeat('b2', 16)]), $otherToken, $other)->assertOk();
        $first = $this->site->fresh()->server;

        $this->actingAs($this->admin())
            ->put(route('teams.sites.server.update', [$this->site->team, $other]), ['server' => $first->uuid])
            ->assertSessionHasNoErrors();

        $this->travel(1)->minutes();
        $this->send($this->sample(['machine_id' => str_repeat('b2', 16), 'cpu' => 55]), $otherToken, $other)->assertOk();

        $this->assertTrue($other->fresh()->server->is($first));
        $this->assertTrue($other->fresh()->server_pinned);
        $this->assertSame(55.0, $first->metricMinutes()->latest('minute')->first()->cpu);
    }

    public function test_a_new_server_split_from_a_cloned_machine_takes_the_sites_samples(): void
    {
        [$other, $otherToken] = $this->otherSite();
        $clone = ['machine_id' => str_repeat('c3', 16)];
        $this->send($this->sample($clone))->assertOk();
        $this->send($this->sample($clone), $otherToken, $other)->assertOk();
        $this->assertSame(1, Server::query()->count());

        $this->actingAs($this->admin())
            ->put(route('teams.sites.server.update', [$this->site->team, $other]), ['server' => 'new', 'name' => 'vm-2'])
            ->assertSessionHasNoErrors();

        $this->travel(1)->minutes();
        $this->send($this->sample([...$clone, 'cpu' => 77]), $otherToken, $other)->assertOk();
        $this->send($this->sample([...$clone, 'cpu' => 11]))->assertOk();

        $split = $other->fresh()->server;
        $this->assertSame('vm-2', $split->name());
        $this->assertNull($split->machine_id, 'the machine id stays with the first server');
        $this->assertSame(77.0, $split->metricMinutes()->sole()->cpu);
        $this->assertSame(11.0, $this->site->fresh()->server->metricMinutes()->latest('minute')->first()->cpu);
    }

    public function test_back_to_automatic_the_next_sample_ties_the_site_to_its_machine(): void
    {
        $this->send($this->sample(['machine_id' => str_repeat('d4', 16)]))->assertOk();
        $machine = $this->site->fresh()->server;
        $admin = $this->admin();
        $this->actingAs($admin)->put(route('teams.sites.server.update', [$this->site->team, $this->site]), ['server' => 'new']);

        $this->actingAs($admin)
            ->put(route('teams.sites.server.update', [$this->site->team, $this->site]), ['server' => 'auto'])
            ->assertSessionHasNoErrors();
        $this->travel(1)->minutes();
        $this->send($this->sample(['machine_id' => str_repeat('d4', 16)]))->assertOk();

        $this->assertTrue($this->site->fresh()->server->is($machine));
        $this->assertFalse($this->site->fresh()->server_pinned);
    }

    public function test_only_a_manager_picks_a_server_of_the_sites_team(): void
    {
        $elsewhere = Server::factory()->create();

        $this->actingAs($this->viewer())
            ->put(route('teams.sites.server.update', [$this->site->team, $this->site]), ['server' => 'new'])
            ->assertForbidden();

        $this->actingAs($this->admin())
            ->put(route('teams.sites.server.update', [$this->site->team, $this->site]), ['server' => $elsewhere->uuid])
            ->assertSessionHasErrors('server');

        $this->assertNull($this->site->fresh()->server_id);
    }

    public function test_a_manager_renames_a_server_and_an_empty_name_shows_its_host_name_again(): void
    {
        $this->send($this->sample(['machine_id' => str_repeat('e5', 16), 'hostname' => 'web-9']))->assertOk();
        $server = Server::query()->sole();
        $admin = $this->admin();

        $this->actingAs($this->viewer())->put(route('teams.servers.update', [$this->site->team, $server]), ['name' => 'nope'])->assertForbidden();

        $this->actingAs($admin)->put(route('teams.servers.update', [$this->site->team, $server]), ['name' => 'Main box'])->assertSessionHasNoErrors();
        $this->assertSame('Main box', $server->fresh()->name());

        $this->actingAs($admin)->put(route('teams.servers.update', [$this->site->team, $server]), ['name' => ''])->assertSessionHasNoErrors();
        $this->assertSame('web-9', $server->fresh()->name());
    }

    public function test_the_site_settings_offer_the_teams_servers(): void
    {
        $this->send($this->sample(['hostname' => 'web-7']))->assertOk();

        $this->actingAs($this->admin())
            ->get(route('teams.sites.edit', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('Runs on web-7, found from its shipper.')
            ->assertSee('A new server');
    }

    /**
     * PHP-FPM's pools and nginx, as the shipper adds them given their status pages.
     *
     * @param  array<string, mixed>  $fpm
     * @param  array<string, mixed>  $nginx
     * @return array<string, mixed>
     */
    private function webServer(array $fpm = [], array $nginx = []): array
    {
        return [
            'php_fpm' => ['pools' => 1, 'active' => 3, 'total' => 50, 'queue' => 0, 'requests' => 4.25, 'saturated' => 0, 'slow' => 0, ...$fpm],
            'nginx' => ['connections' => 16, 'requests' => 9.5, ...$nginx],
        ];
    }

    public function test_php_fpm_and_nginx_are_kept_with_the_minute(): void
    {
        $this->send($this->sample($this->webServer(['saturated' => 2])))->assertOk();

        $minute = $this->site->refresh()->server->metricMinutes()->sole();
        $this->assertSame(3, $minute->fpm_active);
        $this->assertSame(50, $minute->fpm_total);
        $this->assertSame(4.25, $minute->fpm_requests);
        $this->assertSame(2, $minute->fpm_saturated);
        $this->assertSame(16, $minute->nginx_connections);
        $this->assertSame(9.5, $minute->nginx_requests);
    }

    public function test_a_sample_without_them_leaves_those_another_shipper_sent_for_the_minute(): void
    {
        [$other, $otherToken] = $this->otherSite();
        $machine = ['machine_id' => str_repeat('e5', 16)];
        $this->send($this->sample([...$machine, ...$this->webServer()]))->assertOk();
        $this->send($this->sample([...$machine, 'cpu' => 30]), $otherToken, $other)->assertOk();

        $minute = Server::query()->sole()->metricMinutes()->sole();
        $this->assertSame(30.0, $minute->cpu);
        $this->assertSame(3, $minute->fpm_active);
        $this->assertSame(16, $minute->nginx_connections);
    }

    public function test_php_fpm_figures_that_do_not_read_are_refused(): void
    {
        $this->send($this->sample(['php_fpm' => ['pools' => 1, 'active' => -1, 'total' => 50, 'queue' => 0, 'requests' => null, 'saturated' => null, 'slow' => null]]))->assertStatus(400);
        $this->send($this->sample(['nginx' => ['connections' => 3]]))->assertStatus(400);
    }

    public function test_php_fpm_and_nginx_are_summed_up_by_the_hour(): void
    {
        foreach (['08:00' => [2, 0, 1], '08:01' => [8, 3, 2], '08:02' => [5, 0, null]] as $time => [$active, $queue, $saturated]) {
            $this->server()->metricMinutes()->create(['minute' => "2026-10-23 {$time}:00", ...$this->sample(), 'fpm_active' => $active, 'fpm_total' => 20, 'fpm_queue' => $queue,
                'fpm_requests' => 2.0, 'fpm_saturated' => $saturated, 'fpm_slow' => 0, 'nginx_connections' => $active * 2, 'nginx_requests' => 4.0]);
        }

        $this->travelTo('2026-10-23 09:02:00');
        $this->artisan('edgelog:roll-metrics')->assertSuccessful();

        $hour = $this->server()->metricHours()->sole();
        $this->assertSame(5.0, $hour->fpm_active_average);
        $this->assertSame(8, $hour->fpm_active_peak);
        $this->assertSame(3, $hour->fpm_queue_peak);
        $this->assertSame(3, $hour->fpm_saturated);
        $this->assertSame(16, $hour->nginx_connections_peak);
        $this->assertSame(4.0, $hour->nginx_requests);

        $range = ServerRange::last($this->server(), '7d');
        $this->assertSame(3, $range->total('fpm_saturated'));
        $this->assertSame(8, $range->peak('fpm_active_peak'));
    }

    public function test_the_server_tab_shows_php_fpm_and_nginx_and_warns_when_the_pool_is_full(): void
    {
        $this->send($this->sample($this->webServer(['saturated' => 4, 'queue' => 6])))->assertOk();

        $this->actingAs($this->viewer())
            ->get(route('teams.sites.site.server', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('PHP workers busy')
            ->assertSee('3 / 50')
            ->assertSee('nginx connections')
            ->assertSee('9.5/s')
            ->assertDontSee('pm.status_path = /php-fpm-status');

        $this->actingAs($this->viewer())
            ->get(route('teams.servers.index', $this->site->team))
            ->assertOk()
            ->assertSee('PHP-FPM full');
    }

    public function test_the_shipper_tells_each_pool_apart_by_its_name(): void
    {
        if (! is_readable('/proc/stat') || ! is_executable('/usr/bin/curl')) {
            $this->markTestSkipped('No /proc or curl on this system.');
        }

        $folder = storage_path('framework/testing/status-'.uniqid());
        mkdir($folder, 0777, true);
        file_put_contents("{$folder}/www.json", '{"pool":"www","start time":1795278181,"accepted conn":2542,"listen queue":0,"active processes":2,"total processes":4,"max children reached":0,"slow requests":0}');
        file_put_contents("{$folder}/music.json", '{"pool":"music stream","start time":1795278100,"accepted conn":10,"listen queue":1,"active processes":6,"total processes":6,"max children reached":3,"slow requests":0}');

        $result = Process::run(['sh', base_path('tools/edgelog-ship'), '--print-metrics', '--fpm-status', "file://{$folder}/www.json", '--fpm-status', "file://{$folder}/music.json"]);
        array_map('unlink', glob("{$folder}/*"));
        rmdir($folder);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $fpm = json_decode($result->output(), true)['php_fpm'];
        $this->assertSame(2, $fpm['pools']);
        $this->assertSame(6, $fpm['active']);
        $this->assertSame([
            ['name' => 'www', 'active' => 1, 'total' => 4, 'queue' => 0, 'requests' => 0.0, 'saturated' => 0],
            ['name' => 'musicstream', 'active' => 5, 'total' => 6, 'queue' => 1, 'requests' => 0.0, 'saturated' => 0],
        ], $fpm['by_pool'], 'a name keeps only what is safe to send');
    }

    public function test_each_pool_is_kept_with_the_minute_and_summed_up_by_the_hour(): void
    {
        $byPool = fn (int $www, int $music, ?int $saturated) => ['by_pool' => [
            ['name' => 'www', 'active' => $www, 'total' => 4, 'queue' => 0, 'requests' => 1.5, 'saturated' => 0],
            ['name' => 'music', 'active' => $music, 'total' => 6, 'queue' => 2, 'requests' => 0.5, 'saturated' => $saturated],
        ]];

        $this->travelTo('2026-10-23 08:00:10');
        $this->send($this->sample($this->webServer($byPool(1, 6, null))))->assertOk();
        $this->travelTo('2026-10-23 08:01:10');
        $this->send($this->sample($this->webServer($byPool(3, 2, 4))))->assertOk();
        $this->site->refresh();

        $minute = $this->server()->metricMinutes()->latest('minute')->first();
        $this->assertSame(['active' => 2, 'total' => 6, 'queue' => 2, 'requests' => 0.5, 'saturated' => 4], $minute->fpm_pools['music']);

        $range = ServerRange::last($this->server(), '6h');
        $this->assertSame(['music', 'www'], $range->pools());
        $this->assertSame(6, $range->peak(ServerRange::poolKey('active_peak', 'music')));
        $this->assertSame(4, $range->total(ServerRange::poolKey('saturated', 'music')));

        $this->travelTo('2026-10-23 09:02:00');
        $this->artisan('edgelog:roll-metrics')->assertSuccessful();

        $hour = $this->server()->metricHours()->sole();
        $this->assertEquals(['active_average' => 4.0, 'active_peak' => 6, 'total_peak' => 6, 'queue_peak' => 2, 'requests' => 0.5, 'saturated' => 4], $hour->fpm_pools['music']);
        $this->assertEquals(2.0, $hour->fpm_pools['www']['active_average'], 'a JSON column keeps 2.0 as 2');

        $range = ServerRange::last($this->server(), '7d');
        $this->assertSame(['music', 'www'], $range->pools());
        $this->assertSame(6, $range->peak(ServerRange::poolKey('active_peak', 'music')));
    }

    public function test_pool_names_that_do_not_read_are_refused(): void
    {
        $pool = ['active' => 1, 'total' => 2, 'queue' => 0, 'requests' => null, 'saturated' => null];

        $this->send($this->sample($this->webServer(['by_pool' => [['name' => 'www pool', ...$pool]]])))->assertStatus(400);
        $this->send($this->sample($this->webServer(['by_pool' => [['name' => 'www', ...$pool], ['name' => 'www', ...$pool]]])))->assertStatus(400);
    }

    public function test_the_server_tab_draws_a_line_by_pool_and_names_the_full_one(): void
    {
        $this->send($this->sample($this->webServer(['saturated' => 3, 'by_pool' => [
            ['name' => 'cloud', 'active' => 1, 'total' => 4, 'queue' => 0, 'requests' => 1.5, 'saturated' => 0],
            ['name' => 'music', 'active' => 2, 'total' => 2, 'queue' => 0, 'requests' => 0.5, 'saturated' => 3],
        ]])))->assertOk();

        $this->actingAs($this->viewer())
            ->get(route('teams.sites.site.server', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('PHP workers busy by pool')
            ->assertSee('PHP requests a second by pool')
            ->assertSeeInOrder(['cloud', 'music']);

        $this->actingAs($this->viewer())
            ->get(route('teams.servers.index', $this->site->team))
            ->assertOk()
            ->assertSee('PHP-FPM full (music)');
    }

    public function test_a_single_pool_draws_no_chart_by_pool(): void
    {
        $this->send($this->sample($this->webServer(['by_pool' => [
            ['name' => 'www', 'active' => 3, 'total' => 50, 'queue' => 0, 'requests' => 4.25, 'saturated' => 0],
        ]])))->assertOk();

        $this->actingAs($this->viewer())
            ->get(route('teams.sites.site.server', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('PHP workers busy')
            ->assertDontSee('by pool');
    }

    public function test_the_server_tab_explains_how_to_read_php_fpm_and_nginx(): void
    {
        $this->send($this->sample())->assertOk();

        $this->actingAs($this->viewer())
            ->get(route('teams.sites.site.server', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('pm.status_path = /php-fpm-status')
            ->assertSee('--fpm-status http://127.0.0.1:8081/php-fpm-status')
            ->assertDontSee('PHP workers busy');
    }

    public function test_the_shipper_reads_the_status_pages_into_a_sample_the_endpoint_takes(): void
    {
        if (! is_readable('/proc/stat') || ! is_executable('/usr/bin/curl')) {
            $this->markTestSkipped('No /proc or curl on this system.');
        }

        $folder = storage_path('framework/testing/status-'.uniqid());
        mkdir($folder, 0777, true);
        file_put_contents("{$folder}/fpm.json", '{"pool":"www","start time":1795278181,"accepted conn":2542,"listen queue":2,"max listen queue":5,"listen queue len":0,"idle processes":46,"active processes":4,"total processes":50,"max active processes":30,"max children reached":7,"slow requests":1}');
        file_put_contents("{$folder}/nginx", "Active connections: 17 \nserver accepts handled requests\n 202964 202964 525532 \nReading: 0 Writing: 1 Waiting: 16 \n");

        $result = Process::run(['sh', base_path('tools/edgelog-ship'), '--print-metrics', '--fpm-status', "file://{$folder}/fpm.json", '--nginx-status', "file://{$folder}/nginx"]);
        array_map('unlink', glob("{$folder}/*"));
        rmdir($folder);

        $this->assertTrue($result->successful(), $result->errorOutput());
        $sample = json_decode($result->output(), true);
        $this->assertSame(['pools' => 1, 'active' => 3, 'total' => 50, 'queue' => 2, 'requests' => 0.0, 'saturated' => 0, 'slow' => 0,
            'by_pool' => [['name' => 'www', 'active' => 3, 'total' => 50, 'queue' => 2, 'requests' => 0.0, 'saturated' => 0]]], $sample['php_fpm'], 'the status request itself is not counted');
        $this->assertSame(['connections' => 16, 'requests' => 0.0], $sample['nginx']);

        $this->send($result->output())->assertOk();
        $this->assertSame(3, $this->site->refresh()->server->metricMinutes()->sole()->fpm_active);
    }
}
