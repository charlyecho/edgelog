<?php

namespace Tests\Feature;

use App\Analytics\Live\LiveCollector;
use App\Ingest\IngestLog;
use App\Jobs\RefreshLiveSnapshot;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RealtimeTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-live-'.bin2hex(random_bytes(6))]);
        $this->travelTo('2026-10-24 14:20:00');
        $this->site = Site::factory()->create(['url' => 'https://example.com']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    private function line(string $time, string $address, string $path, string $agent, string $referrer = '-'): string
    {
        $when = date('d/M/Y:H:i:s +0000', strtotime("{$time} UTC"));

        return "{$address} - - [{$when}] \"GET {$path} HTTP/1.1\" 200 512 \"{$referrer}\" \"{$agent}\"";
    }

    private function writeToday(): void
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);

        file_put_contents("{$folder}/access.log", implode("\n", [
            // This morning: a visitor from a search, gone since.
            $this->line('2026-10-24 09:00:00', '198.51.100.1', '/', self::CHROME, 'https://www.google.com/'),
            $this->line('2026-10-24 09:00:01', '198.51.100.1', '/app.css', self::CHROME, 'https://example.com/'),
            // Twenty minutes ago: a visitor from ChatGPT, on a phone.
            $this->line('2026-10-24 14:00:00', '198.51.100.2', '/pricing/', self::IPHONE, 'https://chatgpt.com/'),
            $this->line('2026-10-24 14:00:01', '198.51.100.2', '/app.css', self::IPHONE, 'https://example.com/pricing/'),
            // Right now: a visitor reading two pages.
            $this->line('2026-10-24 14:17:00', '198.51.100.3', '/blog/', self::CHROME),
            $this->line('2026-10-24 14:17:01', '198.51.100.3', '/app.js', self::CHROME, 'https://example.com/blog/'),
            $this->line('2026-10-24 14:18:00', '198.51.100.3', '/docs/', self::CHROME, 'https://example.com/blog/'),
            // Machines.
            $this->line('2026-10-24 14:10:00', '20.0.0.1', '/', 'Mozilla/5.0 AppleWebKit/537.36; compatible; ChatGPT-User/1.0; +https://openai.com/bot'),
            $this->line('2026-10-24 14:11:00', '66.249.66.1', '/', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'),
            $this->line('2026-10-24 14:12:00', '198.51.100.9', '/health', 'curl/8.5.0'),
            // Later lines are not counted yet.
            $this->line('2026-10-24 18:00:00', '198.51.100.4', '/future/', self::CHROME),
        ])."\n");
    }

    public function test_today_so_far_is_counted(): void
    {
        $this->writeToday();

        $live = app(LiveCollector::class)->collect($this->site);

        $this->assertSame(3, $live->visitorsToday);
        $this->assertSame(1, $live->activeNow);
        $this->assertSame(2, $live->recentVisitors);
        $this->assertSame(3, $live->pagesRecent);
        $this->assertSame(['/blog/' => 1, '/docs/' => 1, '/pricing/' => 1], collect($live->list('pages'))->sortKeys()->all());
        $this->assertSame(['ChatGPT-User' => 1], $live->list('engines'));
        $this->assertSame(['Googlebot' => 1], $live->list('search'));
        $this->assertSame(1, $live->bots);
        $this->assertSame(['Desktop' => 1, 'Mobile' => 1], collect($live->list('devices'))->sortKeys()->all());
        $this->assertSame('chatgpt.com', $live->newest[1]['from']);
        $this->assertSame('now', $live->newest[0]['active']);
        $this->assertSame(2, $live->newest[0]['pages']);
        // The window's 30 minutes start at 13:51: the visitor reading now is in 14:17 and 14:18.
        $this->assertSame(1, $live->visitorsByMinute[26]);
        $this->assertSame(1, $live->visitorsByMinute[27]);
        $this->assertSame(1, $live->visitorsByMinute[9]);
        $this->assertSame(0, $live->visitorsByMinute[29]);
        $this->assertSame(8, array_sum($live->requestsByMinute));
    }

    public function test_the_real_time_tab_shows_the_snapshot(): void
    {
        $this->writeToday();
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->get(route('teams.sites.realtime', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('Active now')
            ->assertSee('/blog/')
            ->assertSee('from chatgpt.com')
            ->assertSee('ChatGPT-User');

        $this->assertIsArray(Cache::get(RefreshLiveSnapshot::cacheKey($this->site)));
    }

    public function test_the_snapshot_survives_a_cache_that_unserializes_no_object(): void
    {
        config(['cache.default' => 'database', 'cache.serializable_classes' => false]);
        $this->writeToday();
        $first = RefreshLiveSnapshot::latest($this->site);

        $kept = RefreshLiveSnapshot::latest($this->site);

        $this->assertSame($first->toArray(), $kept->toArray());
        $this->assertSame(3, $kept->visitorsToday);
    }

    public function test_only_sites_that_sent_lines_lately_are_refreshed(): void
    {
        Bus::fake();
        $quiet = Site::factory()->create();
        $quiet->ingestStatus()->create(['last_contact_at' => now()->subDay()]);
        $this->site->ingestStatus()->create(['last_contact_at' => now()->subMinutes(3)]);

        $this->artisan('edgelog:live')->assertSuccessful();

        Bus::assertDispatched(RefreshLiveSnapshot::class, fn (RefreshLiveSnapshot $job) => $job->site->is($this->site));
        Bus::assertNotDispatched(RefreshLiveSnapshot::class, fn (RefreshLiveSnapshot $job) => $job->site->is($quiet));
    }

    public function test_others_cannot_see_the_real_time_tab(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('teams.sites.realtime', [$this->site->team, $this->site]))
            ->assertForbidden();
    }

    public function test_a_snapshot_older_than_three_minutes_is_counted_again(): void
    {
        $this->writeToday();
        $first = RefreshLiveSnapshot::latest($this->site);

        $this->travel(2)->minutes();
        $this->assertTrue(RefreshLiveSnapshot::latest($this->site)->asOf->equalTo($first->asOf));

        $this->travel(2)->minutes();
        $this->assertTrue(RefreshLiveSnapshot::latest($this->site)->asOf->greaterThan($first->asOf));
    }
}
