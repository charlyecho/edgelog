<?php

namespace Tests\Feature;

use App\Analytics\Stack\ServerSoftware;
use App\Jobs\CheckServerSoftware;
use App\Models\Server;
use App\Models\Site;
use App\Models\User;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ServerSoftwareTest extends TestCase
{
    use RefreshDatabase;

    private Site $site;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.min_free_gb' => 0]);
        $this->travelTo('2026-11-19 08:30:20');
        Sleep::fake();

        $this->site = Site::factory()->make(['timezone' => 'UTC']);
        $this->token = $this->site->fillNewToken();
        $this->site->save();
    }

    /**
     * What a shipper on Ubuntu 22.04 says runs there: the archive's nginx and
     * MySQL, PHP from ondrej's repository.
     *
     * @return array<string, mixed>
     */
    private function software(array $changes = []): array
    {
        return [
            'os' => 'ubuntu',
            'os_version' => '22.04',
            'programs' => [
                ['product' => 'php', 'version' => '8.3.13', 'package' => 'php8.3', 'package_version' => '8.3.13-1+ubuntu22.04.1+deb.sury.org+1'],
                ['product' => 'php', 'version' => '8.1.2', 'package' => 'php8.1', 'package_version' => '8.1.2-1+ubuntu22.04.1+deb.sury.org+1'],
                ['product' => 'nginx', 'version' => '1.18.0', 'package' => 'nginx', 'package_version' => '1.18.0-6ubuntu14.4'],
                ['product' => 'mysql', 'version' => '8.0.39-0ubuntu0.22.04.1', 'package' => 'mysql-8.0', 'package_version' => '8.0.39-0ubuntu0.22.04.1'],
                ['product' => 'varnish', 'version' => '7.0.2', 'package' => 'varnish', 'package_version' => '7.0.2-1ubuntu0.1'],
            ],
            ...$changes,
        ];
    }

    /**
     * Post a sample the way the shipper does, with what runs on the machine.
     *
     * @param  ?array<string, mixed>  $software
     */
    private function send(?array $software): TestResponse
    {
        $sample = [
            'uptime' => 90061, 'cores' => 4, 'cpu' => 12.5, 'iowait' => 0.5, 'load1' => 0.42, 'load5' => 0.3, 'load15' => 0.25,
            'memory_total' => 8 * 1024 ** 3, 'memory_available' => 6 * 1024 ** 3, 'swap_total' => 0, 'swap_free' => 0,
            'disk_read' => null, 'disk_write' => null, 'network_in' => null, 'network_out' => null, 'disks' => [],
            'machine_id' => str_repeat('a', 32), 'hostname' => 'web-1',
            ...($software === null ? [] : ['software' => $software]),
        ];
        $headers = ['Authorization' => "Bearer {$this->token}", 'Content-Type' => 'application/json', 'Content-Encoding' => 'gzip', 'X-Edgelog-Metrics' => '1'];

        return $this->call('POST', route('ingest.metrics', $this->site), [], [], [], $this->transformHeadersToServerVars($headers), gzencode(json_encode($sample)));
    }

    /**
     * The answers of endoflife.date, OSV.dev and the NVD for the software above.
     */
    private function fakeSources(): void
    {
        Http::fake([
            'endoflife.date/api/ubuntu.json' => Http::response([['cycle' => '22.04', 'eol' => '2027-04-01', 'latest' => '22.04.5'], ['cycle' => '20.04', 'eol' => '2025-05-29', 'latest' => '20.04.6']]),
            'endoflife.date/api/php.json' => Http::response([['cycle' => '8.3', 'eol' => '2027-12-31', 'latest' => '8.3.20'], ['cycle' => '8.1', 'eol' => '2025-12-31', 'latest' => '8.1.34']]),
            'api.osv.dev/*' => function (Request $request) {
                return match ($request['package']['name']) {
                    'nginx' => Http::response(['vulns' => [
                        $this->osvVulnerability('CVE-2026-42945', 'high', 'nginx', '1.18.0-6ubuntu14.11'),
                        $this->osvVulnerability('CVE-2026-118899', 'medium', 'nginx', null),
                        $this->osvVulnerability('CVE-2024-7347', 'low', 'nginx', '1.18.0-6ubuntu14.5'),
                        ['id' => 'USN-8271-1', 'affected' => []],
                    ]]),
                    default => Http::response([]),
                };
            },
            'services.nvd.nist.gov/*' => function (Request $request) {
                return str_contains($request['virtualMatchString'], 'php:php:8.3.13')
                    ? Http::response(['vulnerabilities' => [
                        ['cve' => ['id' => 'CVE-2025-1219', 'vulnStatus' => 'Analyzed', 'metrics' => ['cvssMetricV31' => [['cvssData' => ['baseScore' => 3.9, 'baseSeverity' => 'LOW']]]]]],
                        ['cve' => ['id' => 'CVE-2025-1736', 'vulnStatus' => 'Analyzed', 'metrics' => ['cvssMetricV31' => [['cvssData' => ['baseScore' => 7.3, 'baseSeverity' => 'HIGH']]]]]],
                        ['cve' => ['id' => 'CVE-2025-0000', 'vulnStatus' => 'Rejected']],
                    ]])
                    : Http::response(['vulnerabilities' => []]);
            },
        ]);
    }

    /**
     * An entry of Ubuntu's tracker on OSV.dev.
     *
     * @return array<string, mixed>
     */
    private function osvVulnerability(string $cve, string $priority, string $package, ?string $fixed): array
    {
        return [
            'id' => "UBUNTU-{$cve}",
            'severity' => [['type' => 'Ubuntu', 'score' => $priority]],
            'affected' => [
                ['package' => ['ecosystem' => 'Ubuntu:20.04:LTS', 'name' => $package], 'ranges' => [['events' => [['introduced' => '0'], ['fixed' => '1.17.10-0ubuntu1.9']]]]],
                ['package' => ['ecosystem' => 'Ubuntu:22.04:LTS', 'name' => $package], 'ranges' => [['events' => [['introduced' => '0'], ...($fixed ? [['fixed' => $fixed]] : [])]]]],
            ],
        ];
    }

    private function server(): Server
    {
        return $this->site->refresh()->server;
    }

    /**
     * Run the check of the site's server here (the queue is faked).
     */
    private function check(): void
    {
        $this->app->call([new CheckServerSoftware($this->server()), 'handle']);
    }

    public function test_what_runs_on_the_machine_is_kept_and_judged_when_it_changes(): void
    {
        Queue::fake();

        $this->send(null)->assertOk();
        $this->assertNull($this->server()->software);

        $this->send($this->software())->assertOk();
        $software = $this->server()->software;
        $this->assertSame(['ubuntu', '22.04'], [$software['os'], $software['os_version']]);
        $this->assertSame(['mysql', 'nginx', 'php', 'php'], array_column($software['programs'], 'product'), 'Unknown programs are left out.');
        Queue::assertPushed(CheckServerSoftware::class, 1);

        $this->travel(1)->hours();
        $this->send($this->software())->assertOk();
        Queue::assertPushed(CheckServerSoftware::class, 1);
        $this->assertSame('2026-11-19 09:30:20', $this->server()->software_read_at->toDateTimeString());

        // The faked queue never runs the first check, which holds its lock.
        (new UniqueLock(cache()->store()))->release(new CheckServerSoftware($this->server()));
        $this->send($this->software(['os_version' => '24.04']))->assertOk();
        Queue::assertPushed(CheckServerSoftware::class, 2);
        $this->assertSame('24.04', $this->server()->software['os_version']);
    }

    public function test_software_that_does_not_read_is_refused(): void
    {
        $this->send(['os' => 'ubuntu', 'os_version' => '22.04', 'programs' => [['product' => 'php']]])->assertStatus(400);
        $this->send(['os' => 'ubuntu', 'os_version' => '22.04', 'programs' => array_fill(0, 33, ['product' => 'php', 'version' => '8.3.1'])])->assertStatus(400);
    }

    public function test_each_program_is_judged_by_the_tracker_of_where_it_was_built(): void
    {
        Queue::fake();
        $this->fakeSources();
        $this->send($this->software())->assertOk();

        $this->check();

        $software = $this->server()->software;
        $programs = collect($software['programs'])->keyBy(fn (array $program) => ServerSoftware::label($program));

        $this->assertSame('current', $software['os_status']['status'], 'A newer point release of the system is no update to make.');

        $nginx = $programs['nginx 1.18.0'];
        $this->assertSame(['distro', 'distro'], [$nginx['source'], $nginx['status']]);
        $this->assertSame(['CVE-2026-42945', 'CVE-2026-118899', 'CVE-2024-7347'], array_column($nginx['flaws'], 'id'));
        $this->assertSame([['high', '1.18.0-6ubuntu14.11'], ['medium', null], ['low', '1.18.0-6ubuntu14.5']], array_map(fn (array $flaw) => [$flaw['severity'], $flaw['fixed']], $nginx['flaws']));

        $php83 = $programs['PHP 8.3.13'];
        $this->assertSame(['upstream', 'update', '8.3.20'], [$php83['source'], $php83['status'], $php83['latest']]);
        $this->assertSame(['CVE-2025-1736', 'CVE-2025-1219'], array_column($php83['flaws'], 'id'));

        $this->assertSame('eol', $programs['PHP 8.1.2']['status']);
        $this->assertSame([], $programs['MySQL 8.0.39']['flaws']);

        Http::assertSent(fn (Request $request) => str_contains($request->url(), 'api.osv.dev') && $request['package'] === ['name' => 'nginx', 'ecosystem' => 'Ubuntu:22.04:LTS'] && $request['version'] === '1.18.0-6ubuntu14.4');
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'nvd') && str_contains($request['virtualMatchString'] ?? '', 'nginx'));
    }

    public function test_a_read_that_fails_keeps_the_flaws_found_before(): void
    {
        Queue::fake();
        $this->fakeSources();
        $this->send($this->software())->assertOk();
        $this->check();

        cache()->flush();
        Http::fake(['*' => Http::response('', 503)]);
        $this->check();

        $nginx = collect($this->server()->software['programs'])->firstWhere('product', 'nginx');
        $this->assertCount(3, $nginx['flaws']);
    }

    public function test_the_servers_page_warns_of_an_end_of_life_and_of_flaws_an_update_fixes(): void
    {
        Queue::fake();
        $this->fakeSources();
        $this->send($this->software())->assertOk();
        $this->check();

        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('teams.servers.index', $this->site->team))
            ->assertOk()
            ->assertSee('PHP 8.1.2 end of life')
            ->assertSee('PHP 8.3.13: 1 flaw')
            ->assertSee('nginx 1.18.0: 1 flaw')
            ->assertDontSee('MySQL 8.0.39:');

        $this->actingAs($viewer)->get(route('teams.servers.show', [$this->site->team, $this->server()]))
            ->assertOk()
            ->assertSee('What runs on it')
            ->assertSee('Ubuntu 22.04')
            ->assertSee('CVE-2026-42945')
            ->assertSee('fixed in 1.18.0-6ubuntu14.11')
            ->assertSee('nginx 1.18.0-6ubuntu14.4');
    }

    public function test_a_system_no_longer_supported_is_an_alert(): void
    {
        $alerts = ServerSoftware::alerts([
            'os' => 'ubuntu', 'os_version' => '20.04',
            'os_status' => ['status' => 'eol', 'cycle' => '20.04', 'latest' => '20.04.6', 'eol' => '2025-05-29', 'newest' => '24.04.3'],
            'programs' => [],
        ]);

        $this->assertSame([['bad', 'Ubuntu 20.04 end of life']], array_map(fn (array $alert) => [$alert['tone'], $alert['label']], $alerts));
    }

    public function test_builds_from_outside_the_systems_archive_are_told_apart(): void
    {
        $build = fn (string $version) => ServerSoftware::isDistroBuild(['product' => 'x', 'version' => '1.0', 'package' => 'x', 'package_version' => $version], 'ubuntu');

        $this->assertTrue($build('1.18.0-6ubuntu14.4'));
        $this->assertTrue($build('8.0.39-0ubuntu0.22.04.1'));
        $this->assertTrue($build('2.4.52-1'));
        $this->assertFalse($build('8.3.13-1+ubuntu22.04.1+deb.sury.org+1'));
        $this->assertFalse($build('1.26.2-1~jammy'));
        $this->assertFalse($build('1:10.11.9+maria~ubu2204'));
        $this->assertFalse($build('8.0.39-1ubuntu22.04'));
        $this->assertFalse($build('8.0.39-1debian12'));
        $this->assertFalse(ServerSoftware::isDistroBuild(['product' => 'x', 'version' => '1.0', 'package' => 'x', 'package_version' => '1.0-1'], 'linuxmint'));

        $this->assertSame('Ubuntu:22.04:LTS', ServerSoftware::ecosystem('ubuntu', '22.04'));
        $this->assertSame('Ubuntu:24.10', ServerSoftware::ecosystem('ubuntu', '24.10'));
        $this->assertSame('Debian:12', ServerSoftware::ecosystem('debian', '12'));
        $this->assertNull(ServerSoftware::ecosystem('alpine', '3.20'));
    }

    /**
     * The machine's state, as the shipper sends it.
     *
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function state(array $changes = []): array
    {
        return [
            'reboot_required' => false,
            'reboot_packages' => [],
            'updates' => 0,
            'security_updates' => 0,
            'unattended_upgrades' => true,
            'listening' => ['0.0.0.0:80', '[::]:443', '127.0.0.1:3306', '10.0.0.5:6379'],
            'ssh' => ['permit_root_login' => 'prohibit-password', 'password_authentication' => 'no', 'partial' => '0'],
            'failed_units' => [],
            ...$changes,
        ];
    }

    /**
     * The labels of what is wrong with the machine in that state.
     *
     * @param  array<string, mixed>  $changes
     * @return list<array{0: string, 1: string}>
     */
    private function stateAlerts(array $changes): array
    {
        $software = ServerSoftware::reported(['os' => 'ubuntu', 'os_version' => '22.04', 'programs' => [], 'system' => $this->state($changes)]);

        return array_map(fn (array $alert) => [$alert['tone'], $alert['label']], ServerSoftware::alerts($software));
    }

    public function test_a_machine_in_order_has_no_alert(): void
    {
        $this->assertSame([], $this->stateAlerts([]));
    }

    public function test_the_machines_state_is_an_alert_when_something_waits_or_is_open(): void
    {
        $this->assertSame([['bad', '2 failed services']], $this->stateAlerts(['failed_units' => ['php8.3-fpm.service', 'horizon.service']]));
        $this->assertSame([['warn', '3 security updates']], $this->stateAlerts(['updates' => 12, 'security_updates' => 3]));
        $this->assertSame([['warn', 'Reboot needed']], $this->stateAlerts(['reboot_required' => true, 'reboot_packages' => ['linux-image-6.8.0-150-generic']]));
        $this->assertSame([['warn', 'Redis open to all'], ['warn', 'MySQL / MariaDB open to all']], $this->stateAlerts(['listening' => ['*:6379', '127.0.0.1:6379', '[::]:3306', '[::]:3306']]));
        $this->assertSame([['warn', 'PostgreSQL open to all']], $this->stateAlerts(['listening' => ['203.0.113.7:5432', '192.168.1.4:3306']]));
    }

    public function test_ssh_is_judged_with_its_defaults_where_its_files_say_nothing(): void
    {
        $ssh = fn (?string $root, ?string $passwords, string $partial = '0') => $this->stateAlerts(['ssh' => ['permit_root_login' => $root, 'password_authentication' => $passwords, 'partial' => $partial]]);

        $this->assertSame([['warn', 'SSH takes passwords']], $ssh(null, null), 'sshd takes passwords unless told not to.');
        $this->assertSame([['bad', 'SSH: root with a password']], $ssh('yes', 'yes'));
        $this->assertSame([], $ssh('yes', 'no'));
        $this->assertSame([], $ssh(null, null, '1'), 'A file it could not read may say otherwise.');
    }

    public function test_the_machines_state_is_kept_every_hour_with_what_was_judged_before(): void
    {
        Queue::fake();
        $this->fakeSources();
        $this->send([...$this->software(), 'system' => $this->state()])->assertOk();
        $this->check();

        $this->travel(1)->hours();
        $this->send([...$this->software(), 'system' => $this->state(['security_updates' => 2, 'updates' => 5])])->assertOk();

        Queue::assertPushed(CheckServerSoftware::class, 1);
        $software = $this->server()->software;
        $this->assertSame(2, $software['system']['security_updates']);
        $this->assertSame('current', $software['os_status']['status']);
        $this->assertCount(3, collect($software['programs'])->firstWhere('product', 'nginx')['flaws']);
    }

    public function test_fpms_settings_join_its_versions_build_and_showing_errors_is_an_alert(): void
    {
        $build = ['product' => 'php', 'version' => '8.3.13', 'package' => 'php8.3', 'package_version' => '8.3.13-1+ubuntu22.04.1+deb.sury.org+1'];
        $software = ServerSoftware::reported(['os' => 'ubuntu', 'os_version' => '22.04', 'programs' => [
            [...$build, 'settings' => null],
            [...$build, 'settings' => ['display_errors' => 'STDOUT', 'expose_php' => 'Off', 'opcache.enable' => 'On', 'memory_limit' => '-1']],
        ]]);

        $this->assertCount(1, $software['programs']);
        $this->assertSame(['display_errors' => 'STDOUT', 'expose_php' => 'Off', 'opcache.enable' => 'On'], $software['programs'][0]['settings']);
        $this->assertSame(['PHP 8.3.13 shows its errors'], array_column(ServerSoftware::alerts($software), 'label'));
    }

    public function test_the_kernels_flaws_are_not_read(): void
    {
        Queue::fake();
        $this->fakeSources();
        $software = $this->software();
        $software['programs'][] = ['product' => 'linux', 'version' => '5.15.0-122-generic', 'package' => 'linux', 'package_version' => '5.15.0-122.132'];
        $this->send($software)->assertOk();
        $this->check();

        $kernel = collect($this->server()->software['programs'])->firstWhere('product', 'linux');
        $this->assertSame(['distro', null], [$kernel['source'], $kernel['flaws']]);
        Http::assertNotSent(fn (Request $request) => str_contains($request->url(), 'api.osv.dev') && $request['package']['name'] === 'linux');
    }

    public function test_the_server_page_shows_the_machines_state(): void
    {
        Queue::fake();
        $this->send([...$this->software(), 'system' => $this->state(['reboot_required' => true, 'reboot_packages' => ['libc6'], 'failed_units' => ['horizon.service']])])->assertOk();
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('teams.servers.show', [$this->site->team, $this->server()]))
            ->assertOk()
            ->assertSee('Updates waiting')
            ->assertSee('security updates installed by themselves')
            ->assertSee('libc6')
            ->assertSee('failed: horizon.service')
            ->assertSee('passwords: no')
            ->assertSee('0.0.0.0:80');
    }
}
