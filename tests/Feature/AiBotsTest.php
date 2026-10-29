<?php

namespace Tests\Feature;

use App\Analytics\Crawlers\CrawlHealth;
use App\Analytics\Crawlers\RobotsTxt;
use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Ingest\IngestLog;
use App\Jobs\FetchRobotsTxt;
use App\Jobs\FetchSitemap;
use App\Models\Site;
use App\Models\SiteDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiBotsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const CHATGPT_USER = 'Mozilla/5.0 AppleWebKit/537.36; compatible; ChatGPT-User/1.0; +https://openai.com/bot';

    private const GPTBOT = 'Mozilla/5.0 (compatible; GPTBot/1.2; +https://openai.com/gptbot)';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-ai-'.bin2hex(random_bytes(6))]);
        $this->travelTo('2026-10-23 08:00:00');
        $this->site = Site::factory()->create(['url' => 'https://example.com', 'log_format' => 'auto']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    private function line(string $time, string $address, string $path, string $agent, string $referrer = '-', int $size = 2048): string
    {
        $when = date('d/M/Y:H:i:s +0000', strtotime("2026-10-21 {$time} UTC"));

        return "{$address} - - [{$when}] \"GET {$path} HTTP/1.1\" 200 {$size} \"{$referrer}\" \"{$agent}\" 0.120";
    }

    private function collect(): SiteDay
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/access.log", implode("\n", [
            '198.51.100.9 - - [20/Oct/2026:23:59:00 +0000] "GET / HTTP/1.1" 200 512 "-" "'.self::CHROME.'"',
            // ChatGPT reads /pricing/ for someone, who clicks through without a referrer.
            $this->line('10:00:00', '20.0.0.2', '/pricing/', self::CHATGPT_USER),
            $this->line('10:04:00', '198.51.100.1', '/pricing/', self::CHROME),
            $this->line('10:04:01', '198.51.100.1', '/app.css', self::CHROME, 'https://example.com/pricing/'),
            // It reads /blog/, and its answer sends a visitor there with a citation.
            $this->line('11:00:00', '20.0.0.2', '/blog/', self::CHATGPT_USER),
            $this->line('11:02:00', '198.51.100.2', '/blog/', self::CHROME, 'https://chatgpt.com/'),
            $this->line('11:02:01', '198.51.100.2', '/app.css', self::CHROME, 'https://example.com/blog/'),
            // GPTBot collects for training, one path the rules keep it out of.
            $this->line('12:00:00', '20.0.0.1', '/llms.txt', self::GPTBOT, size: 300),
            $this->line('12:00:01', '20.0.0.1', '/blog/post.md', self::GPTBOT),
            $this->line('12:00:02', '20.0.0.1', '/private/report', self::GPTBOT),
            '198.51.100.9 - - [22/Oct/2026:00:00:01 +0000] "GET / HTTP/1.1" 200 512 "-" "'.self::CHROME.'"',
        ])."\n");

        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => '2026-10-21', '--sync' => true])->assertSuccessful();

        return $this->site->days()->whereDate('day', '2026-10-21')->with('counts')->first();
    }

    public function test_a_day_keeps_the_paths_each_bot_fetched_and_the_crawlers_tab_lists_them(): void
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/access.log", implode("\n", [
            '198.51.100.9 - - [20/Oct/2026:23:59:00 +0000] "GET / HTTP/1.1" 200 512 "-" "'.self::CHROME.'"',
            $this->line('08:00:00', '192.0.2.1', '/api/status', 'Go-http-client/1.1'),
            $this->line('08:01:00', '192.0.2.1', '/api/status', 'Go-http-client/1.1'),
            $this->line('08:02:00', '192.0.2.1', '/api/status?x=1', 'Go-http-client/1.1'),
            $this->line('09:00:00', '192.0.2.2', '/', 'curl/8.5.0'),
            $this->line('09:00:01', '192.0.2.2', '/api/status', 'curl/8.5.0'),
            '198.51.100.9 - - [22/Oct/2026:00:00:01 +0000] "GET / HTTP/1.1" 200 512 "-" "'.self::CHROME.'"',
        ])."\n");
        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => '2026-10-21', '--sync' => true])->assertSuccessful();
        $day = $this->site->days()->whereDate('day', '2026-10-21')->with('counts')->first();

        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));
        $this->assertSame(["Go-http-client\t/api/status" => 3, "curl\t/" => 1, "curl\t/api/status" => 1],
            $day->counts->where('kind', CountKind::BotPath)->pluck('count', 'name')->sortKeys()->all());

        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);
        $tab = fn (array $query = []) => $this->actingAs($viewer)->get(route('teams.sites.ai.crawlers', [$this->site->team, $this->site, 'days' => '7', ...$query]))->assertOk();

        $tab()->assertSee('What bots fetch')->assertSeeInOrder(['/api/status', '4', 'Go-http-client 3, curl 1']);
        $tab(['bot' => 'curl'])->assertSee('What curl fetches')->assertSeeInOrder(['/api/status', '1'])->assertDontSee('Go-http-client 3');
        $tab(['bot' => 'nobody'])->assertSee('What bots fetch');
    }

    public function test_robots_txt_is_read_as_google_reads_it(): void
    {
        $robots = RobotsTxt::parse("User-agent: *\nDisallow: /private/\nAllow: /private/open\n\nUser-agent: CCBot\nUser-agent: Bytespider\nDisallow: /\n\nUser-agent: GPTBot\nDisallow: /*.pdf\$\nSitemap: https://example.com/sitemap.xml");

        $this->assertFalse($robots->allows('Googlebot', '/private/x'));
        $this->assertTrue($robots->allows('Googlebot', '/private/open'));
        $this->assertTrue($robots->allows('Googlebot', '/'));
        $this->assertFalse($robots->allows('Bytespider', '/'));
        $this->assertSame('ccbot', $robots->groupFor('CCBot'));
        $this->assertTrue($robots->allows('GPTBot', '/private/x'));
        $this->assertFalse($robots->allows('GPTBot', '/a/file.pdf'));
        $this->assertTrue($robots->allows('GPTBot', '/a/file.pdf?download'));
        $this->assertSame(['https://example.com/sitemap.xml'], $robots->sitemaps);
    }

    public function test_a_day_keeps_what_assistants_and_crawlers_fetched_and_cost(): void
    {
        $day = $this->collect();
        $counts = fn (CountKind $kind) => $day->counts->where('kind', $kind)->pluck('count', 'name')->sortKeys()->all();

        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));
        $this->assertSame(['chatgpt.com' => 2], $counts(CountKind::AiAskedFetch));
        $this->assertSame(['chatgpt.com' => 1], $counts(CountKind::AiLikelyVisit));
        $this->assertSame(['chatgpt.com' => 1], $counts(CountKind::AiFetchFollowed));
        $this->assertSame(['chatgpt.com' => 1], $counts(CountKind::AiCitedReadFirst));
        $this->assertSame(['chatgpt.com' => 1], $counts(CountKind::AiCitedLandedRead));
        $this->assertSame(["ChatGPT-User\tpage" => 2, "GPTBot\tllms" => 1, "GPTBot\tmarkdown" => 1, "GPTBot\tpage" => 1], $counts(CountKind::CrawlerFetch));

        $gptbot = $day->crawlers()->where('name', 'GPTBot')->first();
        $this->assertSame([3, 300 + 2048 * 2, 360, 3, 3], [$gptbot->requests, (int) $gptbot->bytes, (int) $gptbot->time_ms, $gptbot->timed, $gptbot->paths]);
    }

    public function test_the_ai_tabs_show_the_period_and_judge_robots_txt(): void
    {
        Http::fake(['example.com/robots.txt' => Http::response("User-agent: GPTBot\nDisallow: /private/\n")]);
        $this->collect();
        $this->site->robots()->create(['url' => 'https://example.com/robots.txt', 'status' => 200, 'body' => "User-agent: GPTBot\nDisallow: /private/\n", 'fetched_at' => now()]);
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);
        $tab = fn (string $name) => $this->actingAs($viewer)->get(route("teams.sites.ai.{$name}", [$this->site->team, $this->site, 'days' => '7']))->assertOk();

        $tab('assistants')->assertSee('ChatGPT-User')->assertSee('Likely from AI assistants (inferred)')->assertSee('What each assistant costs');
        $tab('crawlers')->assertSee('GPTBot')->assertSee('What each crawler costs');
        $tab('rules')->assertSee('Partly off-limits, fetched anyway')->assertSee('/private/report (1)');
        $tab('llms')->assertSee('# example.com')->assertSee('[/pricing/](https://example.com/pricing/)');

        $this->actingAs($viewer)->get(route('teams.sites.ai.llms.download', [$this->site->team, $this->site]))->assertOk()->assertDownload('llms.txt');
        $this->actingAs($viewer)->post(route('teams.sites.ai.robots.refresh', [$this->site->team, $this->site]))->assertForbidden();
    }

    public function test_crawl_health_sets_what_crawlers_fetched_against_the_sitemap(): void
    {
        Http::fake([
            'example.com/robots.txt' => Http::response("User-agent: *\nDisallow:\nSitemap: https://example.com/sitemap-index.xml\n"),
            'example.com/sitemap-index.xml' => Http::response('<?xml version="1.0"?><sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><sitemap><loc>https://example.com/pages.xml.gz</loc></sitemap><sitemap><loc>https://elsewhere.example/x.xml</loc></sitemap></sitemapindex>'),
            'example.com/pages.xml.gz' => Http::response(gzencode('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"><url><loc>https://example.com/pricing</loc></url><url><loc>https://example.com/blog/</loc></url><url><loc>https://example.com/about/</loc></url></urlset>')),
        ]);

        FetchRobotsTxt::dispatchSync($this->site);
        FetchSitemap::dispatchSync($this->site);

        $this->assertSame(['/pricing', '/blog/', '/about/'], $this->site->sitemap()->first()->paths);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'elsewhere.example'));

        $this->collect();
        $health = new CrawlHealth($this->site->fresh(), 7);

        $this->assertSame(['GPTBot', 'ChatGPT-User'], array_keys($health->crawlers));
        $this->assertSame(['/pricing', '/blog/'], array_values($health->covered('ChatGPT-User')));
        $this->assertSame(['/about/'], $health->neverFetched('ChatGPT-User'));
        $this->assertSame(['/private/report'], $health->notInSitemap('GPTBot'));

        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);
        $this->actingAs($viewer)->get(route('teams.sites.ai.crawl', [$this->site->team, $this->site, 'window' => 7, 'crawler' => 'GPTBot']))
            ->assertOk()
            ->assertSee('Coverage by crawler')
            ->assertSee('Sitemap pages GPTBot never fetched')
            ->assertSee('/private/report');
    }

    public function test_robots_txt_is_read_only_from_a_public_address(): void
    {
        $this->assertFalse(FetchRobotsTxt::isPublic('127.0.0.1'));
        $this->assertFalse(FetchRobotsTxt::isPublic('10.1.2.3'));
        $this->assertTrue(FetchRobotsTxt::isPublic('93.184.216.34'));
    }
}
