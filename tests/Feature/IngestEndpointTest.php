<?php

namespace Tests\Feature;

use App\Enums\IngestRefusalReason;
use App\Enums\LogFormat;
use App\Ingest\IngestLog;
use App\Ingest\TeamDailyQuota;
use App\Models\Site;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IngestEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const LINE = '203.0.113.7 - - [21/Oct/2026:10:42:05 +0000] "GET / HTTP/1.1" 200 512 "-" "Mozilla/5.0"';

    private Site $site;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ingest.root' => sys_get_temp_dir().'/edgelog-ingest-'.bin2hex(random_bytes(6)),
            'ingest.min_free_gb' => 0,
        ]);

        $this->site = Site::factory()->make();
        $this->token = $this->site->fillNewToken();
        $this->site->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    /**
     * Post a batch the way the shipper does.
     *
     * @param  array<string, string>  $headers
     */
    private function ship(string $body, array $headers = [], ?Site $site = null): TestResponse
    {
        $headers = [
            'Authorization' => "Bearer {$this->token}",
            'X-Edgelog-Batch' => 'shipper1:1',
            'Content-Encoding' => 'gzip',
            ...$headers,
        ];
        $content = $headers['Content-Encoding'] === 'gzip' ? gzencode($body) : $body;

        return $this->call('POST', route('ingest.store', $site ?? $this->site), [], [], [], $this->transformHeadersToServerVars($headers), $content);
    }

    public function test_the_lines_are_kept_in_the_sites_log(): void
    {
        $this->ship(self::LINE."\n".self::LINE."\n")
            ->assertOk()
            ->assertExactJson(['ok' => true, 'accepted' => 2, 'rejected' => 0, 'duplicate' => false]);

        $this->assertSame(self::LINE."\n".self::LINE."\n", file_get_contents(IngestLog::for($this->site)->path()));
    }

    public function test_plain_bodies_are_taken_too(): void
    {
        $this->ship(self::LINE, ['Content-Encoding' => ''])->assertOk()->assertJson(['accepted' => 1]);
    }

    public function test_lines_in_another_format_are_counted_and_dropped(): void
    {
        $this->ship(self::LINE."\nnot a log line\n\n")
            ->assertOk()
            ->assertJson(['accepted' => 1, 'rejected' => 1]);

        $this->assertStringNotContainsString('not a log line', file_get_contents(IngestLog::for($this->site)->path()));
    }

    public function test_the_sites_format_decides_what_is_read(): void
    {
        $this->site->update(['log_format' => LogFormat::NginxExtended]);

        $this->ship(self::LINE)->assertOk()->assertJson(['accepted' => 0, 'rejected' => 1]);
    }

    public function test_a_batch_sent_again_is_a_duplicate(): void
    {
        $this->ship(self::LINE)->assertOk();

        $this->ship(self::LINE)->assertOk()->assertJson(['duplicate' => true, 'accepted' => 0]);

        $this->assertSame(self::LINE."\n", file_get_contents(IngestLog::for($this->site)->path()));
    }

    public function test_a_batch_without_token_is_refused(): void
    {
        $this->ship(self::LINE, ['Authorization' => ''])
            ->assertUnauthorized()
            ->assertHeader('WWW-Authenticate', 'Bearer')
            ->assertJson(['ok' => false, 'reason' => 'no_token']);
    }

    public function test_a_wrong_token_is_refused_and_noted(): void
    {
        $this->ship(self::LINE, ['Authorization' => 'Bearer edg_nope'])->assertUnauthorized()->assertJson(['reason' => 'bad_token']);

        $this->assertSame(IngestRefusalReason::BadToken, $this->site->ingestStatus->last_refusal_reason);
    }

    public function test_a_token_of_another_site_is_refused(): void
    {
        $other = Site::factory()->create();

        $this->ship(self::LINE, [], $other)->assertForbidden()->assertJson(['reason' => 'wrong_site']);

        $this->assertFileDoesNotExist(IngestLog::for($other)->path());
    }

    public function test_an_unknown_site_gets_the_same_answer_as_another_sites(): void
    {
        $this->call('POST', '/ingest/01a16f6d-0000-7000-8000-000000000000', [], [], [], $this->transformHeadersToServerVars([
            'Authorization' => "Bearer {$this->token}",
            'X-Edgelog-Batch' => 'shipper1:1',
        ]), self::LINE)->assertForbidden()->assertJson(['reason' => 'wrong_site']);
    }

    public function test_a_batch_needs_its_number(): void
    {
        $this->ship(self::LINE, ['X-Edgelog-Batch' => 'nope'])->assertBadRequest()->assertJson(['reason' => 'bad_batch']);
    }

    public function test_a_broken_gzip_body_is_refused(): void
    {
        $this->call('POST', route('ingest.store', $this->site), [], [], [], $this->transformHeadersToServerVars([
            'Authorization' => "Bearer {$this->token}",
            'X-Edgelog-Batch' => 'shipper1:1',
            'Content-Encoding' => 'gzip',
        ]), 'not gzip at all')->assertBadRequest()->assertJson(['reason' => 'bad_body']);
    }

    public function test_other_encodings_are_refused(): void
    {
        $this->ship(self::LINE, ['Content-Encoding' => 'br'])->assertStatus(415);
    }

    public function test_a_body_too_large_once_unpacked_is_refused(): void
    {
        config(['ingest.max_body' => 1024]);

        $this->ship(str_repeat(self::LINE."\n", 100))->assertStatus(413)->assertJson(['reason' => 'too_large']);
    }

    public function test_too_many_batches_a_minute_are_refused(): void
    {
        config(['ingest.per_minute' => 2]);

        $this->ship(self::LINE, ['X-Edgelog-Batch' => 's:1'])->assertOk();
        $this->ship(self::LINE, ['X-Edgelog-Batch' => 's:2'])->assertOk();
        $this->ship(self::LINE, ['X-Edgelog-Batch' => 's:3'])->assertStatus(429)->assertHeader('Retry-After');
    }

    public function test_a_team_past_its_daily_bytes_is_refused(): void
    {
        config(['ingest.team_daily_mb' => 150 / 1024 / 1024]);

        $this->ship(self::LINE, ['X-Edgelog-Batch' => 's:1'])->assertOk();
        $this->ship(self::LINE, ['X-Edgelog-Batch' => 's:2'])->assertStatus(429)->assertJson(['reason' => 'team_limit']);
    }

    public function test_a_full_day_is_refused_and_its_bytes_given_back(): void
    {
        config(['ingest.max_mb' => 50 / 1024 / 1024]);

        $this->ship(self::LINE)->assertStatus(429)->assertJson(['reason' => 'day_full'])->assertHeader('Retry-After');

        $this->assertSame(0, TeamDailyQuota::for($this->site->team)->used());
    }

    public function test_a_server_short_of_disk_refuses_every_batch(): void
    {
        config(['ingest.min_free_gb' => 1024 * 1024 * 1024]);

        $this->ship(self::LINE)->assertStatus(507)->assertHeader('Retry-After', '600');
    }

    public function test_accepted_batches_are_noted_for_the_connection_test(): void
    {
        $this->ship(self::LINE."\njunk\n", ['X-Edgelog-Batch' => 'abc:1']);
        $this->ship(self::LINE, ['X-Edgelog-Batch' => 'abc:2']);

        $status = $this->site->ingestStatus;
        $this->assertSame(1, $status->last_batch_lines);
        $this->assertSame(0, $status->last_batch_rejected);
        $this->assertSame('abc', $status->last_batch_shipper);
        $this->assertSame(2, $status->today_lines);
    }

    public function test_the_shipper_can_be_downloaded(): void
    {
        $this->get(route('shipper.download'))->assertOk()->assertDownload('edgelog-ship');
    }

    public function test_the_site_settings_show_the_setup_and_the_status(): void
    {
        $owner = User::factory()->create();
        $this->site->team->members()->attach($owner, ['role' => 'owner']);
        $this->ship(self::LINE);

        $this->actingAs($owner)
            ->get(route('teams.sites.edit', [$this->site->team, $this->site]))
            ->assertOk()
            ->assertSee(route('ingest.store', $this->site))
            ->assertSee('1 line kept');
    }
}
