<?php

namespace Tests\Feature;

use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Ingest\IngestLog;
use App\Models\Site;
use App\Models\SiteDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const SCANNER = 'Mozilla/5.0 zgrab/0.x';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-security-'.bin2hex(random_bytes(6))]);
        config(['analytics.security.login_posts' => 5, 'analytics.security.burst_requests' => 30]);
        $this->travelTo('2026-10-23 08:00:00');
        $this->site = Site::factory()->create(['url' => 'https://example.com', 'log_format' => 'auto']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    private function line(string $time, string $address, string $path, string $agent, int $status = 200, int $size = 512, string $method = 'GET', string $referrer = '-'): string
    {
        $when = date('d/M/Y:H:i:s +0000', strtotime("2026-10-21 {$time} UTC"));

        return "{$address} - - [{$when}] \"{$method} {$path} HTTP/1.1\" {$status} {$size} \"{$referrer}\" \"{$agent}\"";
    }

    /**
     * @return list<string>
     */
    private function day(): array
    {
        $lines = [
            '198.51.100.9 - - [20/Oct/2026:23:59:00 +0000] "GET / HTTP/1.1" 200 512 "-" "'.self::CHROME.'"',
            // A scanner: three probes get the site's 1,184-byte fallback page, one a file of its own size.
            $this->line('03:00:00', '203.0.113.5', '/wp-login.php', self::SCANNER, 200, 1184),
            $this->line('03:00:01', '203.0.113.5', '/phpmyadmin/', self::SCANNER, 200, 1184),
            $this->line('03:00:02', '203.0.113.5', '/xmlrpc.php', self::SCANNER, 200, 1190),
            $this->line('03:00:03', '203.0.113.5', '/.git/config', self::SCANNER, 200, 92),
            $this->line('03:00:04', '203.0.113.5', '/.env', self::SCANNER, 404, 150),
            $this->line('03:00:05', '203.0.113.5', '/?q=../../etc/passwd', self::SCANNER, 200, 4000),
            // The site's own admin, read by its owner: listed as the site's own paths.
            $this->line('09:00:00', '198.51.100.7', '/admin/home', self::CHROME),
            $this->line('09:00:01', '198.51.100.7', '/app.css', self::CHROME, referrer: 'https://example.com/admin/home'),
            // A browser-like crawler.
            $this->line('11:00:00', '203.0.113.8', '/robots.txt', self::CHROME),
            $this->line('11:00:05', '203.0.113.8', '/pricing/', self::CHROME),
        ];

        foreach (range(0, 5) as $second) {
            $lines[] = $this->line(sprintf('12:00:%02d', $second), '203.0.113.20', '/login', self::CHROME, 401, 300, 'POST');
        }

        foreach (range(0, 34) as $second) {
            $lines[] = $this->line(sprintf('14:00:%02d', $second), '203.0.113.30', '/search?q='.$second, 'python-requests/2.31');
        }

        $lines[] = '198.51.100.9 - - [22/Oct/2026:00:00:01 +0000] "GET / HTTP/1.1" 200 512 "-" "'.self::CHROME.'"';

        return $lines;
    }

    private function collect(): SiteDay
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/access.log", implode("\n", $this->day())."\n");

        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => '2026-10-21', '--sync' => true])->assertSuccessful();

        return $this->site->days()->whereDate('day', '2026-10-21')->with(['counts', 'security'])->first();
    }

    public function test_a_day_keeps_its_scanners_attacks_and_fake_browsers(): void
    {
        $this->site->update(['own_paths' => ['/admin/*']]);

        $day = $this->collect();
        $figures = $day->security->figures;

        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));
        $this->assertSame(6, $figures['probes']);
        $this->assertSame(1, $figures['scanners']);
        $this->assertSame(1, $figures['injection']);
        $this->assertSame(['query_only' => 1, 'answered_page' => 4, 'shell' => 3, 'possible' => 1], array_intersect_key($figures, array_flip(['answered_page', 'shell', 'possible', 'query_only'])));
        $this->assertSame([1184, 1190], $day->security->hosts['']['shells']);
        $this->assertSame([['/.git/config', 92]], array_map(fn ($exposure) => [$exposure['path'], $exposure['size']], $day->security->exposures));
        $this->assertSame(['admin' => 1, 'injection' => 1, 'secrets' => 2, 'wordpress' => 2],
            $day->counts->where('kind', CountKind::SecurityCategory)->pluck('count', 'name')->sortKeys()->all());

        $this->assertSame(6, $figures['login_posts']);
        $this->assertSame(35, $figures['burst_requests']);
        $this->assertSame(['burst' => 1, 'login' => 1], $day->attacks()->pluck('kind')->countBy()->sortKeys()->all());
        $this->assertSame(2, $figures['fake_requests']);

        $scanner = $day->suspects()->where('address', '203.0.113.5')->first();
        $this->assertSame([6, 6], [$scanner->requests, $scanner->probes]);
        $this->assertNull($day->suspects()->where('address', '198.51.100.7')->first());
    }

    public function test_without_its_own_paths_the_owners_admin_is_a_probe(): void
    {
        $day = $this->collect();

        $this->assertSame(7, $day->security->figures['probes']);
        $this->assertNotNull($day->suspects()->where('address', '198.51.100.7')->first());
    }

    public function test_the_security_tabs_show_the_period_and_export_the_block_list(): void
    {
        $this->site->update(['own_paths' => ['/admin/*']]);
        $this->collect();
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);
        $tab = fn (string $name, array $query = []) => $this->actingAs($viewer)->get(route("teams.sites.security.{$name}", [$this->site->team, $this->site, 'days' => '7', ...$query]))->assertOk();

        $tab('exposed')->assertSee('1 possible exposure in this period')->assertSee('/.git/config');
        $tab('scanners')->assertSee('203.0.113.5')->assertSee('Secret files (.env, keys)');
        $tab('attacks')->assertSee('Login and form hammering')->assertSee('203.0.113.20')->assertSee('203.0.113.30');
        $tab('fake')->assertSee('203.0.113.8')->assertSee('Browser-like crawler (robots.txt or sitemap)');
        $tab('block')->assertSee('203.0.113.0/24');

        $this->actingAs($viewer)->get(route('teams.sites.security.block.export', [$this->site->team, $this->site, 'nginx', 'days' => '7']))
            ->assertOk()
            ->assertSee("deny 203.0.113.0/24;\ndeny 203.0.113.5;", false);
    }

    public function test_an_ip_has_a_panel_with_what_it_did_and_its_every_request(): void
    {
        $this->site->update(['own_paths' => ['/admin/*']]);
        $this->collect();
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);
        $panel = fn (string $ip) => $this->actingAs($viewer)->get(route('teams.sites.security.ip', [$this->site->team, $this->site, 'ip' => $ip, 'days' => '7', 'panel' => 1]));

        $panel('203.0.113.5')->assertOk()
            ->assertSee('Secret files (.env, keys)')
            ->assertSee('Paths it asked for, from the log')
            ->assertSee('GET /?q=../../etc/passwd')
            ->assertSee('6 requests in the log since the period began')
            ->assertSee(self::SCANNER)
            ->assertSee('deny 203.0.113.5;')
            ->assertDontSee('<html', false);

        $panel('203.0.113.20')->assertOk()->assertSee('Login hammering: ');
        $panel('not-an-ip')->assertOk()->assertSee('not-an-ip is not an IP address.');

        $this->actingAs($viewer)->get(route('teams.sites.security.ip', [$this->site->team, $this->site, 'ip' => '203.0.113.5']))
            ->assertOk()->assertSee('Find an IP')->assertSee('203.0.113.5');
        $this->actingAs($viewer)->get(route('teams.sites.security.scanners', [$this->site->team, $this->site, 'days' => '7']))
            ->assertSee('data-drawer', false);
    }

    public function test_someone_outside_the_team_cannot_look_an_ip_up(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('teams.sites.security.ip', [$this->site->team, $this->site, 'ip' => '203.0.113.5']))
            ->assertForbidden();
    }

    public function test_the_daily_run_forgets_the_addresses_but_keeps_the_figures(): void
    {
        $day = $this->collect();
        config(['analytics.security.kept_days' => 1]);

        $this->artisan('edgelog:run-daily')->assertSuccessful();

        $this->assertSame(0, $day->suspects()->count());
        $this->assertSame(0, $day->attacks()->count());
        $this->assertSame(7, $day->security()->first()->figures['probes']);
    }
}
