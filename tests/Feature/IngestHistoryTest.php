<?php

namespace Tests\Feature;

use App\Enums\DayStatus;
use App\Enums\TeamRole;
use App\Ingest\HistoryLog;
use App\Ingest\IngestLog;
use App\Jobs\AuditSiteDay;
use App\Jobs\CollectSiteDay;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IngestHistoryTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

    private Site $site;

    private string $token;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ingest.root' => sys_get_temp_dir().'/edgelog-history-'.bin2hex(random_bytes(6)),
            'ingest.min_free_gb' => 0,
        ]);

        $this->site = Site::factory()->make(['url' => 'https://example.com', 'log_format' => 'auto']);
        $this->token = $this->site->fillNewToken();
        $this->site->save();
        $this->source = hash('sha256', 'access.log.2.gz');

        $this->shipLive([$this->line('2026-10-22 08:00:00', '/live/')]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    private function line(string $time, string $path, string $address = '198.51.100.1'): string
    {
        $when = date('d/M/Y:H:i:s +0000', strtotime("{$time} UTC"));

        return "{$address} - - [{$when}] \"GET {$path} HTTP/1.1\" 200 512 \"-\" \"".self::CHROME.'"';
    }

    /**
     * Write lines in the live log, as the shipper would have.
     *
     * @param  list<string>  $lines
     */
    private function shipLive(array $lines): void
    {
        $folder = IngestLog::for($this->site)->folder();
        File::ensureDirectoryExists($folder);
        file_put_contents("{$folder}/".IngestLog::BASE, implode("\n", $lines)."\n");
    }

    /**
     * Post a part of a past file the way the shipper does with --backfill.
     *
     * @param  list<string>  $lines
     */
    private function sendPast(array $lines, int $start = 0, ?int $end = null, ?string $source = null): TestResponse
    {
        $body = implode("\n", $lines)."\n";
        $range = ($source ?? $this->source).':'.$start.'-'.($end ?? $start + strlen($body));

        return $this->call('POST', route('ingest.history', $this->site), [], [], [], $this->transformHeadersToServerVars([
            'Authorization' => "Bearer {$this->token}",
            'Content-Encoding' => 'gzip',
            'X-Edgelog-Range' => $range,
        ]), gzencode($body));
    }

    /**
     * @return list<string>
     */
    private function keptOn(string $day): array
    {
        $path = HistoryLog::for($this->site)->path($day);

        return is_file($path) ? explode("\n", trim(implode('', gzfile($path)))) : [];
    }

    public function test_past_lines_are_kept_by_the_utc_day_of_their_time(): void
    {
        $lines = [
            $this->line('2026-10-20 23:59:59', '/one/'),
            $this->line('2026-10-21 00:00:00', '/two/'),
            'not a log line',
        ];

        $this->sendPast($lines)
            ->assertOk()
            ->assertExactJson(['ok' => true, 'accepted' => 2, 'rejected' => 1, 'shipped' => 0, 'offset' => strlen(implode("\n", $lines)."\n")]);

        $this->assertSame([$lines[0]], $this->keptOn('2026-10-20'));
        $this->assertSame([$lines[1]], $this->keptOn('2026-10-21'));
    }

    public function test_lines_the_live_log_already_holds_are_left_to_it(): void
    {
        $this->sendPast([
            $this->line('2026-10-22 07:59:59', '/before/'),
            $this->line('2026-10-22 08:00:00', '/live/'),
            $this->line('2026-10-22 09:00:00', '/after/'),
        ])->assertOk()->assertJson(['accepted' => 1, 'shipped' => 2]);

        $this->assertSame([$this->line('2026-10-22 07:59:59', '/before/')], $this->keptOn('2026-10-22'));
    }

    public function test_past_logs_wait_for_the_live_log(): void
    {
        File::delete(IngestLog::for($this->site)->path());

        $this->sendPast([$this->line('2026-10-20 10:00:00', '/')])->assertStatus(409)->assertJson(['reason' => 'no_live_log']);
    }

    public function test_a_file_goes_on_where_it_was_kept_up_to(): void
    {
        $first = [$this->line('2026-10-20 10:00:00', '/one/')];
        $offset = strlen($first[0]) + 1;
        $this->sendPast($first)->assertOk();

        $this->sendPast($first)->assertStatus(409)->assertJson(['reason' => 'offset', 'offset' => $offset]);
        $this->sendPast([$this->line('2026-10-20 11:00:00', '/two/')], start: 3)->assertStatus(409)->assertJson(['offset' => $offset]);
        $this->sendPast([$this->line('2026-10-20 11:00:00', '/two/')], start: $offset)->assertOk();

        $this->assertCount(2, $this->keptOn('2026-10-20'));
    }

    public function test_another_file_starts_from_its_own_beginning(): void
    {
        $this->sendPast([$this->line('2026-10-20 10:00:00', '/one/')])->assertOk();
        $this->sendPast([$this->line('2026-10-19 10:00:00', '/older/')], source: hash('sha256', 'access.log.3.gz'))->assertOk();

        $this->assertCount(1, $this->keptOn('2026-10-19'));
    }

    public function test_a_part_needs_its_range(): void
    {
        $this->sendPast([$this->line('2026-10-20 10:00:00', '/')], end: 0)->assertBadRequest()->assertJson(['reason' => 'bad_range']);
    }

    public function test_a_wrong_token_is_refused(): void
    {
        $this->token = 'edg_nope';

        $this->sendPast([$this->line('2026-10-20 10:00:00', '/')])->assertUnauthorized();
    }

    public function test_an_append_cut_by_a_crash_is_undone_and_sent_again(): void
    {
        $history = HistoryLog::for($this->site);
        $this->sendPast([$this->line('2026-10-20 10:00:00', '/one/')])->assertOk();
        $size = filesize($history->path('2026-10-20'));

        $statePath = dirname($history->path('2026-10-20')).'/.history-state.json';
        $state = json_decode(file_get_contents($statePath), true);
        $state['pending'] = ['source' => $this->source, 'sizes' => ['2026-10-20' => $size, '2026-10-21' => 0]];
        file_put_contents($statePath, json_encode($state));
        file_put_contents($history->path('2026-10-20'), 'half a gzip member', FILE_APPEND);
        file_put_contents($history->path('2026-10-21'), 'half a gzip member');

        $offset = strlen($this->line('2026-10-20 10:00:00', '/one/')) + 1;
        $this->sendPast([$this->line('2026-10-20 11:00:00', '/two/')], start: $offset)->assertOk();

        $this->assertCount(2, $this->keptOn('2026-10-20'));
        $this->assertFileDoesNotExist($history->path('2026-10-21'));
    }

    public function test_the_touched_days_are_counted_once_the_sending_settled(): void
    {
        Bus::fake();
        $this->travelTo('2026-10-26 12:00:00');
        $this->sendPast([$this->line('2026-10-20 10:00:00', '/one/'), $this->line('2026-10-21 10:00:00', '/two/')])->assertOk();

        $this->artisan('edgelog:count-history')->assertSuccessful();
        Bus::assertNothingDispatched();

        $this->travel(6)->minutes();
        $this->artisan('edgelog:count-history')->assertSuccessful();

        Bus::assertChained([
            new CollectSiteDay($this->site, '2026-10-20'),
            new AuditSiteDay($this->site, '2026-10-20'),
            new CollectSiteDay($this->site, '2026-10-21'),
            new AuditSiteDay($this->site, '2026-10-21'),
        ]);
    }

    public function test_a_utc_day_touches_both_days_it_spans_where_the_site_is(): void
    {
        Bus::fake();
        $this->site->update(['timezone' => 'America/Toronto']);
        $this->travelTo('2026-10-26 12:00:00');
        $this->sendPast([$this->line('2026-10-20 10:00:00', '/')])->assertOk();

        $this->travel(6)->minutes();
        $this->artisan('edgelog:count-history')->assertSuccessful();

        Bus::assertChained([
            new CollectSiteDay($this->site, '2026-10-19'),
            new AuditSiteDay($this->site, '2026-10-19'),
            new CollectSiteDay($this->site, '2026-10-20'),
            new AuditSiteDay($this->site, '2026-10-20'),
        ]);
    }

    public function test_past_days_are_counted_and_verified_with_the_live_log(): void
    {
        $this->sendPast([
            $this->line('2026-10-20 23:00:00', '/'),
            $this->line('2026-10-21 10:00:00', '/'),
            $this->line('2026-10-21 10:00:01', '/app.css'),
            $this->line('2026-10-22 07:00:00', '/early/'),
        ])->assertOk();

        foreach (['2026-10-20', '2026-10-21', '2026-10-22'] as $day) {
            $this->artisan('edgelog:collect', ['site' => $this->site->uuid, '--day' => $day, '--sync' => true])->assertSuccessful();
        }

        $days = $this->site->days()->orderBy('day')->get()->keyBy(fn ($day) => $day->day->toDateString());

        $this->assertTrue($days['2026-10-20']->isPartial());
        $this->assertSame(2, $days['2026-10-21']->hits);
        $this->assertFalse($days['2026-10-21']->isPartial());
        $this->assertSame(2, $days['2026-10-22']->hits);
        $this->assertSame([DayStatus::Verified], $days->pluck('status')->unique()->values()->all());
    }

    public function test_the_settings_offer_the_backfill_command(): void
    {
        $this->sendPast([$this->line('2026-10-20 10:00:00', '/')])->assertOk();

        $user = User::factory()->create();
        $this->site->team->members()->attach($user, ['role' => TeamRole::Owner->value]);

        $this->actingAs($user)
            ->get(route('teams.sites.edit', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee('--backfill /var/log/nginx/access.log*', false)
            ->assertSee('1 file');
    }
}
