<?php

namespace Tests\Feature;

use App\Analytics\Server\ServerRange;
use App\Analytics\SitePeriod;
use App\Enums\DayStatus;
use App\Enums\TrafficGroup;
use App\Models\Site;
use App\Models\SiteMetricMinute;
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
    private function send(array|string $sample, ?string $token = null): TestResponse
    {
        $headers = [
            'Authorization' => 'Bearer '.($token ?? $this->token),
            'Content-Type' => 'application/json',
            'Content-Encoding' => 'gzip',
            'X-Edgelog-Metrics' => '1',
        ];
        $body = gzencode(is_string($sample) ? $sample : json_encode($sample));

        return $this->call('POST', route('ingest.metrics', $this->site), [], [], [], $this->transformHeadersToServerVars($headers), $body);
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

        $minute = $this->site->metricMinutes()->sole();
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

        $this->assertSame(20.0, $this->site->metricMinutes()->sole()->cpu);
    }

    public function test_the_first_sample_after_a_start_may_have_no_rates(): void
    {
        $this->send($this->sample(['cpu' => null, 'iowait' => null, 'disk_read' => null, 'network_out' => null]))->assertOk();

        $this->assertNull($this->site->metricMinutes()->sole()->cpu);
    }

    public function test_a_sample_that_does_not_read_is_refused(): void
    {
        $this->send('not json')->assertStatus(400)->assertJson(['reason' => 'bad_sample']);
        $this->send($this->sample(['cpu' => 140]))->assertStatus(400);
        $this->send($this->sample(['memory_total' => 0]))->assertStatus(400);
        $this->send($this->sample(['disks' => array_fill(0, 33, ['mount' => '/', 'size' => 1, 'used' => 1, 'free' => 0])]))->assertStatus(400);

        $this->assertSame(0, SiteMetricMinute::query()->count());
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $this->send($this->sample(), 'edg_nope')->assertStatus(401);

        $this->assertSame(0, SiteMetricMinute::query()->count());
    }

    public function test_the_hours_that_are_over_are_summed_up_and_old_figures_forgotten(): void
    {
        foreach (['08:00' => 10, '08:01' => 30, '08:59' => 20, '09:00' => 90] as $time => $cpu) {
            $this->site->metricMinutes()->create(['minute' => "2026-10-23 {$time}:00", ...$this->sample(['cpu' => $cpu, 'load1' => $cpu / 10, 'memory_available' => (8 - $cpu / 10) * 1024 ** 3])]);
        }
        $this->site->metricMinutes()->create(['minute' => '2026-10-15 08:00:00', ...$this->sample()]);
        $this->site->metricHours()->create(['hour' => '2025-09-01 00:00:00', 'samples' => 1, 'load_average' => 0, 'load_peak' => 0, 'cores' => 1,
            'memory_total' => 1, 'memory_used_average' => 0, 'memory_used_peak' => 0, 'swap_total' => 0, 'swap_used_peak' => 0, 'disks' => []]);

        $this->travelTo('2026-10-23 09:02:00');
        $this->artisan('edgelog:roll-metrics')->assertSuccessful();
        $this->artisan('edgelog:roll-metrics')->assertSuccessful();

        $hour = $this->site->metricHours()->sole();
        $this->assertSame('2026-10-23 08:00:00', $hour->hour->toDateTimeString());
        $this->assertSame(3, $hour->samples);
        $this->assertSame(20.0, $hour->cpu_average);
        $this->assertSame(30.0, $hour->cpu_peak);
        $this->assertSame(3.0, $hour->load_peak);
        $this->assertSame(3 * 1024 ** 3, $hour->memory_used_peak);
        $this->assertSame(4, $this->site->metricMinutes()->count(), 'the minute of 15 October is past the week kept');
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
        $this->site->metricMinutes()->create(['minute' => '2026-10-22 10:00:00', ...$this->sample(['cpu' => 33.3])]);
        $this->site->metricMinutes()->create(['minute' => '2026-10-21 10:00:00', ...$this->sample(['cpu' => 77.7])]);

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
        $this->site->metricHours()->create(['hour' => '2026-10-20 13:00:00', 'samples' => 60, 'cpu_average' => 40, 'load_average' => 1, 'load_peak' => 2, 'cores' => 4,
            'memory_total' => 8, 'memory_used_average' => 4, 'memory_used_peak' => 6, 'swap_total' => 0, 'swap_used_peak' => 0, 'disks' => []]);

        $server = ServerRange::overPeriod($this->site, SitePeriod::lastDays($this->site, 30));

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
        $this->assertNotNull($this->site->metricMinutes()->sole()->cpu);
    }
}
