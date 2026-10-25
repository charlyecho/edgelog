<?php

namespace Tests\Feature;

use App\Analytics\Geo\Place;
use App\Analytics\Geo\Places;
use App\Enums\CountKind;
use App\Enums\DayStatus;
use App\Ingest\IngestLog;
use App\Models\Site;
use App\Models\SiteDay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TrafficVisitorsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-visitors-'.bin2hex(random_bytes(6))]);
        $this->travelTo('2026-10-23 08:00:00');
        $this->site = Site::factory()->create(['url' => 'https://example.com', 'log_format' => 'auto']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    private function line(string $time, string $address, string $path, string $agent, string $referrer = '-', int $status = 200): string
    {
        $when = date('d/M/Y:H:i:s +0000', strtotime("{$time} UTC"));

        return "{$address} - - [{$when}] \"GET {$path} HTTP/1.1\" {$status} 512 \"{$referrer}\" \"{$agent}\"";
    }

    /**
     * @param  list<string>  $lines
     */
    private function collect(string $day, array $lines): SiteDay
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/access.log", implode("\n", $lines)."\n");

        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => $day, '--sync' => true])->assertSuccessful();

        return $this->site->days()->whereDate('day', $day)->with('counts')->first();
    }

    /**
     * A visitor who reads three pages from a search, and one who reads one page from a phone.
     *
     * @return list<string>
     */
    private function day(string $date): array
    {
        return [
            $this->line("{$date} 09:59:00", '198.51.100.9', '/', self::CHROME),
            $this->line("{$date} 10:00:00", '198.51.100.1', '/', self::CHROME, 'https://www.google.com/'),
            $this->line("{$date} 10:00:01", '198.51.100.1', '/app.css', self::CHROME, 'https://example.com/'),
            $this->line("{$date} 10:01:00", '198.51.100.1', '/pricing/', self::CHROME, 'https://example.com/'),
            $this->line("{$date} 10:01:30", '198.51.100.1', '/missing/', self::CHROME, 'https://example.com/pricing/', 404),
            $this->line("{$date} 10:02:00", '198.51.100.1', '/signup/', self::CHROME, 'https://example.com/pricing/'),
            $this->line("{$date} 11:00:00", '198.51.100.2', '/blog/', self::IPHONE),
            $this->line("{$date} 11:00:01", '198.51.100.2', '/app.css', self::IPHONE, 'https://example.com/blog/'),
            $this->line("{$date} 23:59:59", '198.51.100.9', '/', self::CHROME),
        ];
    }

    public function test_a_day_keeps_its_visits_and_their_figures(): void
    {
        $day = $this->collect('2026-10-21', $this->day('2026-10-21'));

        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));
        $counts = fn (CountKind $kind) => $day->counts->where('kind', $kind)->pluck('count', 'name')->sortKeys()->all();
        $this->assertSame(['1' => 1, '3' => 1], $counts(CountKind::VisitPages));
        $this->assertSame(['/blog/' => 1, '/signup/' => 1], $counts(CountKind::VisitExit));
        $this->assertSame(['/ → /pricing/' => 1, '/pricing/ → /signup/' => 1], $counts(CountKind::VisitStep));
        $this->assertSame(['not_found' => 1], $counts(CountKind::VisitFlag));
        $this->assertSame(['new' => 2], $counts(CountKind::VisitorReturning));

        $visits = $day->visits()->orderBy('started_at')->get();
        $this->assertCount(2, $visits);
        $this->assertSame(['/', '/pricing/', '/signup/'], $visits[0]->trail);
        $this->assertSame(120, $visits[0]->seconds);
        $this->assertSame('Search engine', $visits[0]->source);
        $this->assertSame('google.com', $visits[0]->via);
        $this->assertSame(['Desktop', 'Chrome', 'Linux'], [$visits[0]->device, $visits[0]->browser, $visits[0]->system]);
        $this->assertTrue($visits[0]->not_found);
        $this->assertSame(['Mobile', 'Safari', 'iOS'], [$visits[1]->device, $visits[1]->browser, $visits[1]->system]);

        $this->assertSame(2, $day->visitors()->count());
        $this->assertSame(['Desktop' => 1, 'Mobile' => 1], $day->engagements()->where('dimension', 'device')->pluck('visits', 'name')->sortKeys()->all());
    }

    public function test_a_visitor_seen_on_an_earlier_day_is_returning(): void
    {
        $this->collect('2026-10-21', $this->day('2026-10-21'));
        $day = $this->collect('2026-10-22', $this->day('2026-10-22'));

        $this->assertSame(['returning' => 2], $day->counts->where('kind', CountKind::VisitorReturning)->pluck('count', 'name')->all());
        $this->assertSame(['Direct or unknown' => 1, 'Search engine' => 1], $day->counts->where('kind', CountKind::ReturningSource)->pluck('count', 'name')->sortKeys()->all());
        $this->assertTrue($day->visits()->first()->returning);
    }

    public function test_the_daily_run_forgets_old_visits_and_hashes(): void
    {
        $day = $this->collect('2026-10-21', $this->day('2026-10-21'));
        config(['analytics.journey_days' => 1, 'analytics.returning_days' => 3]);

        $this->artisan('edgelog:run-daily')->assertSuccessful();

        $this->assertSame(0, $day->visits()->count());
        $this->assertSame(2, $day->visitors()->count());
        $this->assertSame(2, $day->engagements()->where('dimension', 'source')->sum('visits'));
    }

    public function test_the_visitors_page_shows_the_flow_the_visits_and_the_engagement(): void
    {
        $this->collect('2026-10-21', $this->day('2026-10-21'));
        $this->collect('2026-10-22', $this->day('2026-10-22'));
        $user = User::factory()->create();
        $this->site->team->members()->attach($user, ['role' => 'viewer']);

        $this->actingAs($user)
            ->get(route('teams.sites.traffic.visitors', [$this->site->team, $this->site]).'?days=7')
            ->assertOk()
            ->assertSee('Visitor flow')
            ->assertSee('Left after 1 page')
            ->assertSee('4 visits in the last 7 days: 2 by new visitors and 2 by returning ones.')
            ->assertSee('/pricing/ → /signup/')
            ->assertSee('New and returning by source');

        $this->actingAs($user)
            ->get(route('teams.sites.traffic.visitors', [$this->site->team, $this->site]).'?days=7&device=Mobile')
            ->assertOk()
            ->assertSee('2 rows');
    }

    public function test_a_visit_is_placed_by_its_address_unless_the_log_names_its_country(): void
    {
        $this->app->instance(Places::class, new class implements Places
        {
            public function of(string $address, string $loggedCountry = ''): Place
            {
                return $loggedCountry !== '' ? new Place($loggedCountry) : new Place('CA', 'Toronto');
            }

            public function isAvailable(): bool
            {
                return true;
            }
        });

        $day = $this->collect('2026-10-21', [
            ...$this->day('2026-10-21'),
            '2026-10-21T12:00:00+00:00 198.51.100.3 example.com "GET /fr/ HTTP/1.1" 200 10 "-" "'.self::CHROME.'" 0.010 cc=FR rid=0123456789abcdef',
            '2026-10-21T12:00:01+00:00 198.51.100.3 example.com "GET /app.css HTTP/1.1" 200 10 "https://example.com/fr/" "'.self::CHROME.'" 0.010 cc=FR rid=0123456789abcdee',
        ]);

        $this->assertSame(['CA' => 2, 'FR' => 1], $day->counts->where('kind', CountKind::PeopleCountry)->pluck('count', 'name')->sortKeys()->all());
        $this->assertSame([['CA', 'Toronto'], ['CA', 'Toronto'], ['FR', '']], $day->visits()->orderBy('started_at')->get()->map(fn ($visit) => [$visit->country, $visit->city])->all());
    }

    public function test_the_geoip_update_fails_when_db_ip_has_no_file(): void
    {
        Http::fake(['download.db-ip.com/*' => Http::response('', 404)]);
        config(['analytics.geoip.country' => sys_get_temp_dir().'/edgelog-geoip-'.bin2hex(random_bytes(6)).'/country.mmdb']);

        $this->artisan('edgelog:geoip-update', ['--only' => 'country'])->assertFailed();

        $this->assertFileDoesNotExist(config('analytics.geoip.country'));
        Http::assertSentCount(2);
    }

    public function test_someone_outside_the_team_cannot_see_the_visitors(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('teams.sites.traffic.visitors', [$this->site->team, $this->site]))
            ->assertForbidden();
    }
}
