<?php

namespace Tests\Feature;

use App\Analytics\ServerAlerts;
use App\Analytics\SiteAlerts;
use App\Analytics\SiteSparklines;
use App\Enums\DayStatus;
use App\Enums\IngestRefusalReason;
use App\Enums\TrafficGroup;
use App\Models\Server;
use App\Models\Site;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SiteListTest extends TestCase
{
    use RefreshDatabase;

    private function storeDay(Site $site, string $day, DayStatus $status, int $readers): void
    {
        $siteDay = $site->days()->create(['day' => $day, 'timezone' => $site->timezone, 'status' => $status]);
        $siteDay->groups()->create(['group' => TrafficGroup::People, 'readers' => $readers, 'requests' => $readers, 'views' => $readers, 'visits' => $readers]);
    }

    public function test_each_site_gets_its_last_30_days_of_visitors(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $site = Site::factory()->create();
        $this->storeDay($site, '2026-10-23', DayStatus::Verified, 12);
        $this->storeDay($site, '2026-10-22', DayStatus::Withheld, 99);
        $this->storeDay($site, '2026-10-21', DayStatus::Verified, 7);
        $this->storeDay($site, '2026-09-01', DayStatus::Verified, 50);

        $series = SiteSparklines::visitors(collect([$site]))[$site->id];

        $this->assertCount(30, $series);
        $this->assertSame(12, $series[29]);
        $this->assertNull($series[28], 'a withheld day is never drawn');
        $this->assertSame(7, $series[27]);
        $this->assertNull($series[0]);
    }

    public function test_the_list_shows_the_sparkline_and_the_total(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $user = User::factory()->create();
        $team = Team::factory()->withMember($user)->create();
        $busy = Site::factory()->for($team)->create(['name' => 'busy.example.com']);
        Site::factory()->for($team)->create(['name' => 'quiet.example.com']);
        $this->storeDay($busy, '2026-10-23', DayStatus::Verified, 12);
        $this->storeDay($busy, '2026-10-22', DayStatus::Verified, 8);

        $this->actingAs($user)
            ->get(route('teams.sites.index', $team))
            ->assertOk()
            ->assertSee('20')
            ->assertSee('visitors, last 30 days')
            ->assertSee('2 of 30 days')
            ->assertSee('No figures for the last 30 days yet.');
    }

    /**
     * A minute of the server, healthy unless changed.
     *
     * @param  array<string, mixed>  $changes
     */
    private function storeMinute(Server $server, string $minute, array $changes = []): void
    {
        $server->metricMinutes()->create([
            'minute' => $minute,
            'cpu' => 10,
            'iowait' => 0,
            'load1' => 0.5,
            'load5' => 0.5,
            'load15' => 0.5,
            'cores' => 4,
            'memory_total' => 8 * 1024 ** 3,
            'memory_available' => 6 * 1024 ** 3,
            'swap_total' => 0,
            'swap_free' => 0,
            'uptime' => 3600,
            'disks' => [['mount' => '/', 'size' => 100, 'used' => 40, 'free' => 60]],
            ...$changes,
        ]);
    }

    /**
     * @return list<string>
     */
    private function labels(Site $site): array
    {
        return array_column(SiteAlerts::for(collect([$site]))[$site->id], 'label');
    }

    /**
     * @return list<string>
     */
    private function serverLabels(Server $server): array
    {
        return array_column(ServerAlerts::for(collect([$server]))[$server->id], 'label');
    }

    public function test_a_healthy_site_has_no_alert(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $site = Site::factory()->create();
        $site->ingestStatus()->create(['last_batch_at' => now()->subMinute(), 'last_contact_at' => now()->subMinute()]);
        $site->days()->create(['day' => '2026-10-23', 'timezone' => 'UTC', 'status' => DayStatus::Verified, 'collected_at' => now()->subHours(10)]);

        $this->assertSame([], $this->labels($site));
    }

    public function test_a_healthy_server_has_no_alert(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $server = Server::factory()->create();
        $this->storeMinute($server, '2026-10-24 11:59:00');

        $this->assertSame([], $this->serverLabels($server));
    }

    public function test_a_site_without_log_says_so(): void
    {
        $site = Site::factory()->create();

        $this->assertSame(['No log yet'], $this->labels($site));
    }

    public function test_a_late_or_refused_log_is_an_alert(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $site = Site::factory()->create();
        $site->ingestStatus()->create([
            'last_batch_at' => now()->subHours(3),
            'last_refusal_at' => now()->subMinutes(5),
            'last_refusal_status' => 401,
            'last_refusal_reason' => IngestRefusalReason::BadToken,
        ]);
        $site->days()->create(['day' => '2026-10-23', 'timezone' => 'UTC', 'status' => DayStatus::Withheld, 'collected_at' => now()->subHours(10)]);

        $alerts = SiteAlerts::for(collect([$site]))[$site->id];

        $this->assertSame(['Log refused', 'No log for 3h', '1 day withheld'], array_column($alerts, 'label'));
        $this->assertSame(['bad', 'bad', 'bad'], array_column($alerts, 'tone'));
    }

    public function test_a_late_daily_count_is_an_alert(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $site = Site::factory()->create();
        $site->ingestStatus()->create(['last_batch_at' => now()->subMinute()]);
        $site->days()->create(['day' => '2026-10-21', 'timezone' => 'UTC', 'status' => DayStatus::Verified, 'collected_at' => now()->subDays(2)]);

        $this->assertSame(['Daily count late'], $this->labels($site));
    }

    public function test_a_busy_or_full_server_is_an_alert(): void
    {
        $this->travelTo('2026-10-24 12:00:30');
        $server = Server::factory()->create();
        $this->storeMinute($server, '2026-10-24 11:58:00', ['cpu' => 90]);
        $this->storeMinute($server, '2026-10-24 12:00:00', [
            'cpu' => 96,
            'load5' => 6.2,
            'memory_available' => 1024 ** 3,
            'disks' => [['mount' => '/', 'size' => 100, 'used' => 80, 'free' => 20], ['mount' => '/boot', 'size' => 100, 'used' => 10, 'free' => 90]],
        ]);

        $alerts = ServerAlerts::for(collect([$server]))[$server->id];

        $this->assertSame(['CPU 93%', 'Load 6.2', 'Memory 88%', 'Disk / 80%'], array_column($alerts, 'label'));
        $this->assertSame(['bad', 'bad', 'warn', 'warn'], array_column($alerts, 'tone'));
    }

    public function test_a_quiet_server_only_says_it_is_quiet(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $server = Server::factory()->create();
        $this->storeMinute($server, '2026-10-24 11:40:00', ['cpu' => 99]);

        $this->assertSame(['Server quiet for 20m'], $this->serverLabels($server));
    }

    public function test_the_list_tells_the_server_of_each_site_once_with_its_alerts(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $user = User::factory()->create();
        $team = Team::factory()->withMember($user)->create();
        $server = Server::factory()->for($team)->create(['hostname' => 'web-1']);
        Site::factory()->for($team)->for($server)->count(2)->create();
        $this->storeMinute($server, '2026-10-24 11:59:00', ['memory_available' => 0]);

        $this->actingAs($user)
            ->get(route('teams.sites.index', $team))
            ->assertOk()
            ->assertSee('web-1')
            ->assertSee('1 server alert')
            ->assertSee('title="Memory 100%"', false);
    }

    public function test_the_list_shows_each_sites_alerts(): void
    {
        $this->travelTo('2026-10-24 12:00:00');
        $user = User::factory()->create();
        $team = Team::factory()->withMember($user)->create();
        $site = Site::factory()->for($team)->create();
        $site->ingestStatus()->create(['last_batch_at' => now()->subMinutes(40)]);

        $this->actingAs($user)
            ->get(route('teams.sites.index', $team))
            ->assertOk()
            ->assertSee('No log for 40m')
            ->assertSee('Is edgelog-ship still running?');
    }

    public function test_the_rail_lists_the_sites_under_their_server_with_the_open_ones_sections(): void
    {
        $user = User::factory()->create();
        $team = Team::factory()->withMember($user)->create();
        $server = Server::factory()->for($team)->create(['hostname' => 'web-1']);
        Site::factory()->for($team)->for($server)->create(['name' => 'b.example.com']);
        $current = Site::factory()->for($team)->for($server)->create(['name' => 'a.example.com']);
        Site::factory()->for($team)->create(['name' => 'alone.example.com']);

        $this->actingAs($user)
            ->get(route('teams.edit', $team))
            ->assertOk()
            ->assertSeeInOrder(['web-1', 'a.example.com', 'b.example.com', 'No known server', 'alone.example.com', 'Team settings'])
            ->assertDontSee('Leads &amp; sales', false);

        $this->actingAs($user)
            ->get(route('teams.sites.traffic.visitors', [$team, $current]))
            ->assertOk()
            ->assertSeeInOrder(['web-1', 'a.example.com', 'Overview', 'Traffic', 'Leads &amp; sales', 'b.example.com', 'No known server'], false);
    }
}
