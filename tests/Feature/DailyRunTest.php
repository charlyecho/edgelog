<?php

namespace Tests\Feature;

use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Enums\TrafficGroup;
use App\Ingest\IngestLog;
use App\Jobs\AuditSiteDay;
use App\Jobs\CollectSiteDay;
use App\Models\Site;
use App\Models\SiteDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class DailyRunTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const FIREFOX = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64; rv:131.0) Gecko/20100101 Firefox/131.0';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-days-'.bin2hex(random_bytes(6))]);

        $this->site = Site::factory()->create(['url' => 'https://example.com', 'log_format' => 'auto']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    /**
     * A line in nginx's combined format.
     */
    private function line(string $time, string $address, string $path, string $agent, string $referrer = '-', int $status = 200, string $method = 'GET'): string
    {
        $when = date('d/M/Y:H:i:s +0000', strtotime("{$time} UTC"));

        return "{$address} - - [{$when}] \"{$method} {$path} HTTP/1.1\" {$status} 512 \"{$referrer}\" \"{$agent}\"";
    }

    /**
     * @param  list<string>  $lines
     */
    private function writeLog(array $lines, string $file = IngestLog::BASE): void
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        $content = implode("\n", $lines)."\n";

        file_put_contents("{$folder}/{$file}", str_ends_with($file, '.gz') ? gzencode($content) : $content);
    }

    /**
     * A day with one of each kind of visitor.
     *
     * @return list<string>
     */
    private function referenceDay(): array
    {
        return [
            $this->line('2026-10-20 23:59:00', '198.51.100.1', '/', self::CHROME),
            // A person from a search, who drew the pages and came back later.
            $this->line('2026-10-21 10:00:00', '198.51.100.1', '/', self::CHROME, 'https://www.google.com/'),
            $this->line('2026-10-21 10:00:01', '198.51.100.1', '/app.css', self::CHROME, 'https://example.com/'),
            $this->line('2026-10-21 10:05:00', '198.51.100.1', '/pricing/', self::CHROME, 'https://example.com/'),
            $this->line('2026-10-21 10:05:01', '198.51.100.1', '/app.css', self::CHROME, 'https://example.com/pricing/', 304),
            $this->line('2026-10-21 15:00:00', '198.51.100.1', '/docs/', self::CHROME),
            // A person who followed a citation.
            $this->line('2026-10-21 11:00:00', '198.51.100.2', '/blog/', self::FIREFOX, 'https://chatgpt.com/'),
            $this->line('2026-10-21 11:00:02', '198.51.100.2', '/app.js', self::FIREFOX, 'https://example.com/blog/'),
            // A scraper in a browser's clothes.
            $this->line('2026-10-21 12:00:00', '198.51.100.3', '/pricing/', self::CHROME),
            // AI assistants: a training crawl, a live question.
            $this->line('2026-10-21 13:00:00', '20.0.0.1', '/robots.txt', 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)'),
            $this->line('2026-10-21 13:00:01', '20.0.0.1', '/', 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)'),
            $this->line('2026-10-21 13:10:00', '20.0.0.2', '/pricing/', 'Mozilla/5.0 AppleWebKit/537.36; compatible; ChatGPT-User/1.0; +https://openai.com/bot'),
            // A search crawler.
            $this->line('2026-10-21 14:00:00', '66.249.66.1', '/sitemap.xml', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'),
            // A scanner, which marks its whole visit.
            $this->line('2026-10-21 16:00:00', '198.51.100.4', '/', self::CHROME),
            $this->line('2026-10-21 16:00:05', '198.51.100.4', '/.env', self::CHROME, '-', 404),
            // A script.
            $this->line('2026-10-21 17:00:00', '198.51.100.5', '/health', 'curl/8.5.0'),
            // Another host, in the extended format.
            '2026-10-21T18:00:00+00:00 198.51.100.9 api.example.com "GET /v1 HTTP/1.1" 200 10 "-" "curl/8.5.0" 0.010 cc=FR rid=0123456789abcdef',
            $this->line('2026-10-22 00:00:01', '198.51.100.1', '/', self::CHROME),
        ];
    }

    private function collect(string $day = '2026-10-21'): void
    {
        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => $day, '--sync' => true])->assertSuccessful();
    }

    private function day(string $day = '2026-10-21'): ?SiteDay
    {
        return $this->site->days()->whereDate('day', $day)->with(['groups', 'counts'])->first();
    }

    public function test_a_day_is_counted_and_verified(): void
    {
        $this->writeLog($this->referenceDay());

        $this->collect();

        $day = $this->day();
        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));
        $this->assertNull($day->partial_from);
        $this->assertSame(16, $day->parsed);
        $this->assertSame(15, $day->hits);
        $this->assertSame(1, $day->other_hosts);
        $this->assertSame(1, $day->cited_visits);

        $groups = $day->groups->keyBy(fn ($group) => $group->group->value);
        $this->assertSame(['requests' => 7, 'views' => 4, 'visits' => 3, 'readers' => 3, 'probes' => 0], $groups['people']->only('requests', 'views', 'visits', 'readers', 'probes'));
        $this->assertSame(['requests' => 3, 'views' => 2, 'visits' => 2, 'readers' => 2, 'probes' => 0], $groups['answer_engines']->only('requests', 'views', 'visits', 'readers', 'probes'));
        $this->assertSame(['requests' => 1, 'views' => 0, 'visits' => 1, 'readers' => 0, 'probes' => 0], $groups['search_engines']->only('requests', 'views', 'visits', 'readers', 'probes'));
        $this->assertSame(['requests' => 4, 'views' => 3, 'visits' => 3, 'readers' => 3, 'probes' => 1], $groups['bots']->only('requests', 'views', 'visits', 'readers', 'probes'));

        $counts = fn (CountKind $kind) => $day->counts->where('kind', $kind)->pluck('count', 'name')->sortKeys()->all();
        $this->assertSame(['AI assistant' => 1, 'Direct or unknown' => 1, 'Search engine' => 1], $counts(CountKind::PeopleSource));
        $this->assertSame(['/' => 1, '/blog/' => 1, '/docs/' => 1, '/pricing/' => 1], $counts(CountKind::PeoplePage));
        $this->assertSame(['chatgpt.com' => 1], $counts(CountKind::CitedBy));
        $this->assertSame(['asked' => 1, 'training' => 2], $counts(CountKind::EngineTier));
        $this->assertSame(['ChatGPT-User' => 1, 'GPTBot' => 2], $counts(CountKind::EngineName));
        $this->assertSame(['Googlebot' => 1], $counts(CountKind::SearchEngine));
        $this->assertSame(['Chrome' => 2, 'Firefox' => 1], $counts(CountKind::PeopleBrowser));
        $this->assertSame(['api.example.com' => 1], $counts(CountKind::OtherHost));
        $this->assertSame([
            'browser-like, never rendered the page' => 1,
            'curl' => 1,
            'scanner (asked for secret or config files)' => 1,
            'scanner (probe)' => 1,
        ], $counts(CountKind::BotAgent));
    }

    public function test_the_recount_withholds_a_day_that_was_counted_wrong(): void
    {
        $this->writeLog($this->referenceDay());
        dispatch_sync(new CollectSiteDay($this->site, '2026-10-21'));

        $day = $this->day();
        $this->assertSame(DayStatus::Collected, $day->status);
        $day->groups->firstWhere('group', TrafficGroup::People)->increment('visits');

        dispatch_sync(new AuditSiteDay($this->site, '2026-10-21'));

        $day = $this->day();
        $this->assertSame(DayStatus::Withheld, $day->status);
        $this->assertStringContainsString('people.visits', implode("\n", $day->problems));
    }

    public function test_a_day_with_too_many_unreadable_lines_is_withheld(): void
    {
        $this->writeLog([
            $this->line('2026-10-20 23:00:00', '198.51.100.1', '/', self::CHROME),
            $this->line('2026-10-21 10:00:00', '198.51.100.1', '/', self::CHROME),
            'this is not a log line',
            $this->line('2026-10-21 10:00:05', '198.51.100.1', '/app.css', self::CHROME),
        ]);

        $this->collect();

        $day = $this->day();
        $this->assertSame(DayStatus::Withheld, $day->status);
        $this->assertSame(1, $day->unparsed);
    }

    public function test_a_day_before_the_logs_begin_is_missing_not_zero(): void
    {
        $this->writeLog([$this->line('2026-10-25 10:00:00', '198.51.100.1', '/', self::CHROME)]);

        $this->collect();

        $this->assertNull($this->day());
    }

    public function test_a_quiet_day_the_logs_cover_is_zero(): void
    {
        $this->writeLog([
            $this->line('2026-10-20 10:00:00', '198.51.100.1', '/', 'curl/8.5.0'),
            $this->line('2026-10-22 10:00:00', '198.51.100.1', '/', 'curl/8.5.0'),
        ]);

        $this->collect();

        $this->assertSame(0, $this->day()->hits);
        $this->assertSame(DayStatus::Verified, $this->day()->status);
    }

    public function test_a_day_the_logs_begin_in_is_partial(): void
    {
        $this->writeLog([
            $this->line('2026-10-21 10:00:00', '198.51.100.1', '/', self::CHROME),
            $this->line('2026-10-21 10:00:01', '198.51.100.1', '/app.css', self::CHROME),
        ]);

        $this->collect();

        $this->assertTrue($this->day()->isPartial());
        $this->assertSame(DayStatus::Verified, $this->day()->status);
    }

    public function test_rotated_files_are_read(): void
    {
        $this->writeLog([$this->line('2026-10-20 12:00:00', '198.51.100.1', '/', self::CHROME)], 'access.log.2.gz');
        $this->writeLog([$this->line('2026-10-21 10:00:00', '198.51.100.1', '/', self::CHROME)], 'access.log.1');
        $this->writeLog([$this->line('2026-10-21 23:59:59', '198.51.100.7', '/late/', 'curl/8.5.0')]);

        $this->collect();

        $this->assertSame(2, $this->day()->hits);
        $this->assertFalse($this->day()->isPartial());
    }

    public function test_the_day_follows_the_sites_time_zone(): void
    {
        $this->site->update(['timezone' => 'America/Toronto']);
        $this->writeLog([
            $this->line('2026-10-21 03:00:00', '198.51.100.1', '/before/', 'curl/8.5.0'),
            $this->line('2026-10-22 02:00:00', '198.51.100.1', '/late-evening/', 'curl/8.5.0'),
            $this->line('2026-10-22 05:00:00', '198.51.100.1', '/next-day/', 'curl/8.5.0'),
        ]);

        $this->collect('2026-10-21');

        $day = $this->day('2026-10-21');
        $this->assertSame('America/Toronto', $day->timezone);
        $this->assertSame(1, $day->hits);
    }

    public function test_the_daily_run_queues_yesterday_once_the_day_has_settled(): void
    {
        Bus::fake();
        $this->travelTo('2026-10-22 00:10:00');

        $this->artisan('edgelog:run-daily')->assertSuccessful();
        Bus::assertNothingDispatched();

        $this->travelTo('2026-10-22 00:45:00');
        $this->artisan('edgelog:run-daily')->assertSuccessful();

        Bus::assertChained([CollectSiteDay::class, AuditSiteDay::class]);
    }

    public function test_the_daily_run_leaves_a_collected_day_alone(): void
    {
        Bus::fake();
        $this->travelTo('2026-10-22 06:00:00');
        $this->site->days()->create(['day' => '2026-10-21', 'timezone' => 'UTC', 'status' => DayStatus::Verified]);

        $this->artisan('edgelog:run-daily')->assertSuccessful();

        Bus::assertNothingDispatched();
    }

    public function test_the_overview_shows_the_verified_figures(): void
    {
        $this->travelTo('2026-10-22 12:00:00');
        $this->writeLog($this->referenceDay());
        $this->collect();
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)
            ->get(route('teams.sites.show', [$this->site->team, $this->site]).'?days=7')
            ->assertOk()
            ->assertSee('1 day verified')
            ->assertSee('6 days not recorded')
            ->assertSee('/pricing/')
            ->assertSee('GPTBot')
            ->assertSee('chatgpt.com');
    }

    public function test_the_overview_never_shows_a_withheld_days_figures(): void
    {
        $this->travelTo('2026-10-22 12:00:00');
        $this->writeLog($this->referenceDay());
        dispatch_sync(new CollectSiteDay($this->site, '2026-10-21'));
        $this->day()->update(['status' => DayStatus::Withheld]);
        $owner = User::factory()->create();
        $this->site->team->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->get(route('teams.sites.show', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('1 day withheld')
            ->assertSee('No figures yet')
            ->assertDontSee('GPTBot');
    }

    public function test_the_time_zone_must_be_a_real_one(): void
    {
        $owner = User::factory()->create();
        $this->site->team->members()->attach($owner, ['role' => 'owner']);

        $this->actingAs($owner)
            ->put(route('teams.sites.update', [$this->site->team, $this->site]), ['url' => $this->site->url, 'timezone' => 'Mars/Olympus'])
            ->assertSessionHasErrors('timezone');

        $this->actingAs($owner)
            ->put(route('teams.sites.update', [$this->site->team, $this->site]), ['url' => $this->site->url, 'timezone' => 'Europe/Paris'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Europe/Paris', $this->site->fresh()->timezone);
    }
}
