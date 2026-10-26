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

class SiteHealthTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private Site $site;

    protected function setUp(): void
    {
        parent::setUp();

        config(['ingest.root' => sys_get_temp_dir().'/edgelog-health-'.bin2hex(random_bytes(6))]);
        $this->travelTo('2026-10-23 08:00:00');
        $this->site = Site::factory()->create(['url' => 'https://example.com', 'log_format' => 'auto', 'timezone' => 'UTC']);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    private function line(string $time, string $address, string $request, int $status, string $agent, string $seconds = '0.050', string $referrer = '-'): string
    {
        return "2026-10-21T{$time}+00:00 {$address} example.com \"{$request} HTTP/1.1\" {$status} 512 \"{$referrer}\" \"{$agent}\" {$seconds} cc=- rid=-";
    }

    /**
     * A person reading three pages (one slow), meeting a 404 and sending a
     * form, Googlebot getting a 500 (the line written twice) and a 404, and
     * the heartbeat: silent from 10:00 to 10:30, and answered once with a 503.
     *
     * @return list<string>
     */
    private function day(): array
    {
        $lines = [
            $this->line('09:00:00', '198.51.100.7', 'GET /', 200, self::CHROME),
            $this->line('09:00:01', '198.51.100.7', 'GET /app.css', 200, self::CHROME, '0.002', 'https://example.com/'),
            $this->line('09:01:00', '198.51.100.7', 'GET /pricing/', 200, self::CHROME, '1.400'),
            $this->line('09:01:01', '198.51.100.7', 'GET /app.css', 200, self::CHROME, '0.002', 'https://example.com/pricing/'),
            $this->line('09:02:00', '198.51.100.7', 'GET /old-page', 404, self::CHROME, referrer: 'https://example.com/pricing/'),
            $this->line('09:03:00', '198.51.100.7', 'POST /contact', 200, self::CHROME, '0.300', 'https://example.com/pricing/'),
            $this->line('11:00:00', '66.249.66.1', 'GET /pricing/', 500, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', '0.300'),
            // The same line twice: a duplicate.
            $this->line('11:00:00', '66.249.66.1', 'GET /pricing/', 500, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', '0.300'),
            $this->line('11:30:00', '66.249.66.1', 'GET /gone-page', 404, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)'),
        ];

        for ($minute = 0; $minute < 24 * 60; $minute += 5) {
            if ($minute >= 600 && $minute < 630) {
                continue;
            }

            $lines[] = $this->line(sprintf('%02d:%02d:30', intdiv($minute, 60), $minute % 60), '192.0.2.1', 'GET /robots.txt', $minute === 720 ? 503 : 200, 'edgelog-heartbeat', '0.001');
        }

        sort($lines);

        return [
            '2026-10-20T23:55:30+00:00 192.0.2.1 example.com "GET /robots.txt HTTP/1.1" 200 512 "-" "edgelog-heartbeat" 0.001 cc=- rid=-',
            ...$lines,
            '2026-10-22T00:00:30+00:00 192.0.2.1 example.com "GET /robots.txt HTTP/1.1" 200 512 "-" "edgelog-heartbeat" 0.001 cc=- rid=-',
        ];
    }

    private function collect(): SiteDay
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/access.log", implode("\n", $this->day())."\n");

        $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => '2026-10-21', '--sync' => true])->assertSuccessful();

        return $this->site->days()->whereDate('day', '2026-10-21')->with('counts')->first();
    }

    public function test_a_day_keeps_its_answers_speeds_posts_and_heartbeat(): void
    {
        $day = $this->collect();
        $counts = fn (CountKind $kind) => $day->counts->where('kind', $kind)->pluck('count', 'name')->sortKeys()->all();

        $this->assertSame(DayStatus::Verified, $day->status, implode("\n", $day->problems ?? []));
        $this->assertSame(['ours:2xx' => 281, 'ours:5xx' => 1, 'people:2xx' => 5, 'people:404' => 1, 'search_engines:404' => 1, 'search_engines:5xx' => 2], $counts(CountKind::HealthStatus));
        $this->assertSame(['ours:200' => 281, 'ours:503' => 1, 'people:200' => 5, 'people:404' => 1, 'search_engines:404' => 1, 'search_engines:500' => 2], $counts(CountKind::HealthCode));
        $this->assertSame(["500\t/pricing/" => 2, "503\t/robots.txt" => 1], $counts(CountKind::HealthErrorPage));
        $this->assertSame(['/pricing/' => 2, '/robots.txt' => 1], $counts(CountKind::HealthServerError));
        $this->assertSame(['/old-page' => 1], $counts(CountKind::HealthNotFound));
        $this->assertSame(["/old-page\t(this site)" => 1], $counts(CountKind::HealthNotFoundFrom));
        $this->assertSame(['/gone-page' => 1], $counts(CountKind::HealthEngineNotFound));
        $this->assertSame(['/' => 50, '/pricing/' => 1400], $counts(CountKind::HealthPageMs));
        $this->assertSame(['/' => 1, '/pricing/' => 1], $counts(CountKind::HealthPageTimed));
        $this->assertSame(['1 to 3 s' => 1, 'under 0.2 s' => 1], $counts(CountKind::HealthSpeed));
        $this->assertSame(['/pricing/' => 1], $counts(CountKind::HealthSlowPage));
        $this->assertSame(["people\t/contact" => 1], $counts(CountKind::HealthPost));

        $this->assertSame(['expected' => 288, 'up' => 281, 'beats' => 282, 'failed' => 1], array_intersect_key($day->heartbeat, array_flip(['expected', 'up', 'beats', 'failed'])));
        $this->assertSame([35], array_column($day->heartbeat['gaps'], 'minutes'));

        $this->assertSame(['duplicates' => 1, 'short' => 0], array_intersect_key($day->quality, array_flip(['duplicates', 'short'])));
        $this->assertSame([34], array_column($day->quality['silences'], 'minutes'));
    }

    public function test_the_health_tab_shows_the_period(): void
    {
        $this->collect();
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('teams.sites.site.health', [$this->site->team, $this->site, 'days' => '7']))
            ->assertOk()
            ->assertSee('The five groups add up to 291 exactly.')
            ->assertSee('97.57%')
            ->assertSee('281 of 288 expected beats answered.')
            ->assertSee('21 Oct 09:55')
            ->assertSee('/pricing/')
            ->assertSee('/contact')
            ->assertDontSee('Not set up');
    }

    public function test_without_a_heartbeat_the_tab_says_how_to_set_it_up(): void
    {
        $this->collect();
        $this->site->days()->update(['heartbeat' => null]);
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('teams.sites.site.health', [$this->site->team, $this->site, 'days' => '7']))
            ->assertOk()
            ->assertSee('Not set up')
            ->assertSee("-A 'edgelog-heartbeat' https://example.com/robots.txt");
    }

    public function test_someone_outside_the_team_cannot_see_the_health_tab(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('teams.sites.site.health', [$this->site->team, $this->site]))
            ->assertForbidden();
    }

    public function test_the_not_found_speed_and_quality_tabs_show_the_period(): void
    {
        $this->collect();
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);
        $tab = fn (string $name) => $this->actingAs($viewer)->get(route("teams.sites.site.{$name}", [$this->site->team, $this->site, 'days' => '7']))->assertOk();

        $tab('not-found')->assertSee('/old-page')->assertSee('(this site) 1')->assertSee('open')->assertSee('/gone-page');
        $tab('speed')->assertSee('Answer time by page')->assertSee('1,400 ms')->assertSee('50%');
        $tab('quality')->assertSee('Worth a look')->assertSee('1 line repeats an earlier one exactly.')->assertSee('Verified')->assertSee('Not recorded')->assertSee('access.log');
    }

    public function test_the_status_codes_tab_shows_each_code_and_the_errors(): void
    {
        $this->collect();
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);

        $this->actingAs($viewer)->get(route('teams.sites.site.statuses', [$this->site->team, $this->site, 'days' => '7']))
            ->assertOk()
            ->assertSee('1.7%')
            ->assertSeeInOrder(['200', 'OK', '281', '5', '286'])
            ->assertSeeInOrder(['500', 'Internal Server Error'])
            ->assertSee('503 Service Unavailable')
            ->assertSee('/robots.txt')
            ->assertDontSee('exact status codes were kept');
    }

    public function test_the_requests_tab_lists_the_last_hours_with_their_rule(): void
    {
        $this->collect();
        $this->travelTo('2026-10-21 12:00:00');
        $viewer = User::factory()->create();
        $this->site->team->members()->attach($viewer, ['role' => 'viewer']);
        $requests = fn (array $query = []) => $this->actingAs($viewer)->get(route('teams.sites.site.requests', [$this->site->team, $this->site, ...$query]))->assertOk();

        $requests()->assertSee('people: page view')->assertSee('ours: heartbeat')->assertSee('search: Googlebot')->assertSee('1,400 ms');
        $requests(['show' => 'people'])->assertSee('/old-page')->assertDontSee('ours: heartbeat');
        $requests(['q' => 'old-page'])->assertSee('/old-page')->assertDontSee('search: Googlebot');
        $requests(['status' => '5xx'])->assertSee('/pricing/')->assertDontSee('/old-page');
    }
}
