<?php

namespace Tests\Feature;

use App\Analytics\Geo\Place;
use App\Analytics\Geo\Places;
use App\Analytics\Retention;
use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Ingest\IngestLog;
use App\Models\Site;
use App\Models\SiteDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class TrafficTabsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-tabs-'.bin2hex(random_bytes(6))]);
        $this->travelTo('2026-10-23 08:00:00');
        $this->site = Site::factory()->create(['url' => 'https://example.com', 'log_format' => 'auto']);

        $this->app->instance(Places::class, new class implements Places
        {
            public function of(string $address, string $loggedCountry = ''): Place
            {
                return $address === '198.51.100.1' ? new Place('FR', 'Lyon', 'Auvergne-Rhône-Alpes') : new Place('CA');
            }

            public function isAvailable(): bool
            {
                return true;
            }
        });
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

    private function collect(string $date, string $address = '198.51.100.1'): SiteDay
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/access.log", implode("\n", [
            $this->line("{$date} 00:00:00", '198.51.100.9', '/', self::CHROME),
            // From a LinkedIn post, tagged.
            $this->line("{$date} 09:15:00", $address, '/blog/?utm_source=linkedin&utm_medium=social&utm_campaign=fall', self::CHROME, 'https://www.linkedin.com/feed/update/123'),
            $this->line("{$date} 09:15:01", $address, '/app.css', self::CHROME, 'https://example.com/blog/'),
            $this->line("{$date} 09:16:00", $address, '/pricing/', self::CHROME, 'https://example.com/blog/'),
            // Straight in, on a phone.
            $this->line("{$date} 14:00:00", '198.51.100.2', '/pricing/', self::IPHONE),
            $this->line("{$date} 14:00:01", '198.51.100.2', '/app.css', self::IPHONE, 'https://example.com/pricing/'),
            // Google, and an assistant answering a question.
            $this->line("{$date} 15:00:00", '66.249.66.1', '/blog/', 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'),
            $this->line("{$date} 16:00:00", '20.0.0.2', '/pricing/', 'Mozilla/5.0 AppleWebKit/537.36; compatible; ChatGPT-User/1.0; +https://openai.com/bot'),
            $this->line("{$date} 23:59:59", '198.51.100.9', '/', self::CHROME),
        ])."\n");

        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => $date, '--sync' => true])->assertSuccessful();

        return $this->site->days()->whereDate('day', $date)->with('counts')->first();
    }

    public function test_a_day_keeps_what_the_traffic_tabs_read(): void
    {
        $day = $this->collect('2026-10-21');
        $counts = fn (CountKind $kind) => $day->counts->where('kind', $kind)->pluck('count', 'name')->sortKeys()->all();

        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));
        $this->assertSame(['Windows' => 1, 'iOS' => 1], $counts(CountKind::PeopleSystem));
        $this->assertSame(['FR'."\t".'Lyon'."\t".'Auvergne-Rhône-Alpes' => 1, 'Unknown' => 1], $counts(CountKind::PeopleCity));
        $this->assertSame(['LinkedIn' => 1], $counts(CountKind::PeopleSocial));
        $this->assertSame(['linkedin.com/feed/update/123' => 1], $counts(CountKind::PeopleReferrerPage));
        $this->assertSame(['9' => 1, '14' => 1], $counts(CountKind::VisitHour));
        $this->assertSame(['/blog/' => 1, '/pricing/' => 2], $counts(CountKind::PeoplePageVisitors));
        $this->assertSame(['/pricing/' => 1], $counts(CountKind::EngineAskedPage));

        $utm = $day->engagements()->where('dimension', 'utm_campaign')->first();
        $this->assertSame(['fall', 1, 2], [$utm->name, $utm->visits, $utm->views]);

        $pages = $this->site->pages()->get()->keyBy('path');
        $this->assertSame('2026-10-21', $pages['/blog/']->first_google_on->toDateString());
        $this->assertSame('2026-10-21', $pages['/pricing/']->first_read_on->toDateString());
        $this->assertNull($pages['/pricing/']->first_google_on);
    }

    public function test_a_page_keeps_its_first_days_whatever_order_days_are_counted_in(): void
    {
        $this->collect('2026-10-22');
        $this->collect('2026-10-20');

        $this->assertSame('2026-10-20', $this->site->pages()->where('path', '/blog/')->first()->publishedOn()->toDateString());
    }

    public function test_every_traffic_tab_shows_the_period(): void
    {
        $this->collect('2026-10-21');
        $this->collect('2026-10-22', '198.51.100.7');
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);
        $tab = fn (string $name, array $query = []) => $this->actingAs($viewer)->get(route("teams.sites.traffic.{$name}", [$this->site->team, $this->site, 'days' => '7', ...$query]))->assertOk();

        $tab('sources')->assertSee('Source type')->assertSee('Referring sites, down to the page')->assertSee('linkedin.com');
        $tab('sources', ['list' => 'social'])->assertSee('LinkedIn');
        $tab('sources', ['utm' => 'campaign'])->assertSee('fall');
        $tab('pages')->assertSee('Pages by publish date')->assertSee('Page leaderboard')->assertSee('/pricing/');
        $tab('places')->assertSee('Lyon, Auvergne-Rhône-Alpes, FR')->assertSee('Windows')->assertSee('France');
        $tab('time')->assertSee('Each day, hour by hour')->assertSee('All hours together');
        $tab('retention')->assertSee('New visitors, and the share back each week after');
    }

    public function test_retention_follows_the_visitors_first_seen_each_day(): void
    {
        // 20 Oct: two new visitors; the one on a phone is back on the 21st, with a new one.
        $this->collect('2026-10-20');
        $this->collect('2026-10-21', '198.51.100.8');

        $retention = new Retention($this->site, 'day');
        $cohorts = collect($retention->cohorts)->keyBy(fn ($cohort) => $cohort['start']->toDateString());

        $this->assertSame(2, $cohorts['2026-10-20']['new']);
        $this->assertSame([1, null], array_slice($cohorts['2026-10-20']['back'], 0, 2));
        $this->assertSame(['counted', 'missing'], array_slice($cohorts['2026-10-20']['state'], 0, 2));
        $this->assertSame(1, $cohorts['2026-10-21']['new']);
        $this->assertSame(['missing', 'open'], array_slice($cohorts['2026-10-21']['state'], 0, 2));
        $this->assertSame(['back' => 1, 'of' => 2], $retention->backIn(1));
        $this->assertSame('2026-10-20', $retention->keptFrom->toDateString());
    }
}
