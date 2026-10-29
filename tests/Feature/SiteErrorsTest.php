<?php

namespace Tests\Feature;

use App\Enums\ErrorStatus;
use App\Ingest\IngestLog;
use App\Models\Site;
use App\Models\SiteError;
use App\Models\SiteErrorOccurrence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SiteErrorsTest extends TestCase
{
    use RefreshDatabase;

    private const string CSRF = <<<'LOG'
        CRITICAL - 2026-10-29 07:39:23 --> CodeIgniter\Security\Exceptions\SecurityException: The action you requested is not allowed.
        [Method: POST, Route: user/project/autosave]
        in SYSTEMPATH/Security/Security.php on line 258.
         1 SYSTEMPATH/Security/Security.php(258): CodeIgniter\Security\Exceptions\SecurityException::forDisallowedAction()
         2 SYSTEMPATH/Filters/CSRF.php(52): CodeIgniter\Security\Security->verify()
        LOG;

    private const string LARAVEL = <<<'LOG'
        [2026-10-29 07:39:23] production.ERROR: Division by zero {"exception":"[object] (DivisionByZeroError(code: 0): Division by zero at /home/forge/example.com/app/Http/Controllers/CartController.php:31)
        [stacktrace]
        #0 /home/forge/example.com/vendor/laravel/framework/src/Illuminate/Routing/Controller.php(54): App\\Http\\Controllers\\CartController->show()
        #1 {main}
        "}
        LOG;

    private Site $site;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ingest.root' => sys_get_temp_dir().'/edgelog-errors-'.bin2hex(random_bytes(6)),
            'ingest.min_free_gb' => 0,
        ]);
        $this->travelTo('2026-10-29 07:40:00');

        $this->site = Site::factory()->make(['timezone' => 'UTC']);
        $this->token = $this->site->fillNewToken();
        $this->site->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(config('ingest.root'));

        parent::tearDown();
    }

    /**
     * Post a batch of an error log the way edgelog-ship --errors does.
     */
    private function ship(string $kind, string $body, int $seq = 1, ?string $token = null): TestResponse
    {
        $headers = [
            'Authorization' => 'Bearer '.($token ?? $this->token),
            'X-Edgelog-Batch' => "shipper1:{$seq}",
            'Content-Encoding' => 'gzip',
        ];

        return $this->call('POST', route('ingest.errors', [$this->site, $kind]), [], [], [], $this->transformHeadersToServerVars($headers), gzencode($body));
    }

    /**
     * Write the site's access log as the shipper of the access log would have.
     */
    private function accessLog(string ...$lines): void
    {
        $log = IngestLog::for($this->site);
        File::ensureDirectoryExists($log->folder());
        File::put($log->path(), implode("\n", $lines)."\n");
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        $this->site->team->members()->attach($user, ['role' => $role]);

        return $user;
    }

    public function test_the_last_entry_waits_for_the_next_one(): void
    {
        $this->ship('codeigniter', self::CSRF)->assertOk()->assertExactJson(['ok' => true, 'accepted' => 0, 'rejected' => 0, 'duplicate' => false]);
        $this->assertSame(0, SiteError::count());

        $this->ship('codeigniter', 'INFO - 2026-10-29 07:39:30 --> Session started', 2)->assertOk()->assertJson(['accepted' => 1]);

        $error = SiteError::sole();
        $this->assertSame('CodeIgniter\Security\Exceptions\SecurityException', $error->class);
        $this->assertSame('SYSTEMPATH/Security/Security.php', $error->file);
        $this->assertSame(1, $error->times_seen);
        $this->assertStringContainsString('CSRF.php(52)', $error->last_stack);
        $this->assertSame('/user/project/autosave', $error->occurrences()->sole()->path);
    }

    public function test_an_entry_split_between_two_batches_is_read_whole(): void
    {
        [$start, $end] = explode("\n 2 ", self::CSRF);

        $this->ship('codeigniter', $start);
        $this->ship('codeigniter', ' 2 '.$end."\nINFO - 2026-10-29 07:39:30 --> Next", 2);

        $this->assertStringContainsString('CSRF.php(52)', SiteError::sole()->last_stack);
    }

    public function test_the_same_error_is_one_error_seen_again(): void
    {
        $this->ship('codeigniter', self::CSRF."\n".str_replace('07:39:23', '07:39:25', self::CSRF)."\n".self::CSRF);
        $this->artisan('edgelog:errors');
        $this->travel(2)->minutes();
        $this->artisan('edgelog:errors');

        $error = SiteError::sole();
        $this->assertSame(3, $error->times_seen);
        $this->assertSame('2026-10-29 07:39:25', $error->last_seen_at->toDateTimeString());
    }

    public function test_a_batch_sent_again_is_read_once(): void
    {
        $this->ship('codeigniter', self::CSRF."\nINFO - 2026-10-29 07:39:30 --> Next");
        $this->ship('codeigniter', self::CSRF."\nINFO - 2026-10-29 07:39:30 --> Next")->assertOk()->assertJson(['duplicate' => true]);

        $this->assertSame(1, SiteError::sole()->times_seen);
    }

    public function test_an_entry_alone_is_read_once_its_log_said_nothing_for_a_minute(): void
    {
        $this->ship('laravel', self::LARAVEL);

        $this->artisan('edgelog:errors');
        $this->assertSame(0, SiteError::count());

        $this->travel(61)->seconds();
        $this->artisan('edgelog:errors');

        $this->assertSame('DivisionByZeroError', SiteError::sole()->class);
        $this->assertSame('app/Http/Controllers/CartController.php', SiteError::sole()->file);
    }

    public function test_the_request_is_found_in_the_access_log(): void
    {
        $this->accessLog(
            '203.0.113.7 - - [29/Oct/2026:07:39:22 +0000] "GET /cart HTTP/1.1" 200 512 "-" "Mozilla/5.0"',
            '203.0.113.8 - - [29/Oct/2026:07:39:23 +0000] "GET /cart/total?coupon=1 HTTP/1.1" 500 120 "https://example.com/cart" "Mozilla/5.0 (Firefox)"',
            '203.0.113.9 - - [29/Oct/2026:07:39:40 +0000] "GET /other HTTP/1.1" 500 120 "-" "Mozilla/5.0"',
        );
        $this->ship('laravel', self::LARAVEL."\n[2026-10-29 07:39:50] production.INFO: Next");

        $this->artisan('edgelog:errors');

        $occurrence = SiteErrorOccurrence::sole();
        $this->assertSame(['GET', '/cart/total?coupon=1', 'https://example.com/cart', '203.0.113.8', 'Mozilla/5.0 (Firefox)', 500], [$occurrence->method, $occurrence->path, $occurrence->referrer, $occurrence->address, $occurrence->user_agent, $occurrence->status]);
        $this->assertSame(SiteErrorOccurrence::FROM_ACCESS, $occurrence->request_from);
        $this->assertNotNull($occurrence->looked_up_at);
    }

    public function test_a_route_the_error_log_names_is_matched_by_its_path(): void
    {
        $this->accessLog(
            '203.0.113.7 - - [29/Oct/2026:07:39:23 +0000] "POST /other HTTP/1.1" 403 512 "-" "Mozilla/5.0"',
            '203.0.113.8 - - [29/Oct/2026:07:39:24 +0000] "POST /user/project/autosave HTTP/2.0" 403 120 "https://example.com/user/project" "Mozilla/5.0"',
        );
        $this->ship('codeigniter', self::CSRF."\nINFO - 2026-10-29 07:39:30 --> Next");

        $this->artisan('edgelog:errors');

        $this->assertSame(['203.0.113.8', 'https://example.com/user/project'], [SiteErrorOccurrence::sole()->address, SiteErrorOccurrence::sole()->referrer]);
    }

    public function test_two_requests_that_could_be_it_are_never_guessed_between(): void
    {
        $this->accessLog(
            '203.0.113.7 - - [29/Oct/2026:07:39:23 +0000] "GET /a HTTP/1.1" 500 512 "-" "Mozilla/5.0"',
            '203.0.113.8 - - [29/Oct/2026:07:39:23 +0000] "GET /b HTTP/1.1" 500 512 "-" "Mozilla/5.0"',
        );
        $this->ship('laravel', self::LARAVEL."\n[2026-10-29 07:39:50] production.INFO: Next");

        $this->artisan('edgelog:errors');
        $this->assertNull(SiteErrorOccurrence::sole()->path);
        $this->assertNull(SiteErrorOccurrence::sole()->looked_up_at);

        $this->travel(15)->minutes();
        $this->artisan('edgelog:errors');

        $this->assertNull(SiteErrorOccurrence::sole()->path);
        $this->assertNotNull(SiteErrorOccurrence::sole()->looked_up_at);
    }

    public function test_a_resolved_error_that_comes_back_is_open_again(): void
    {
        $this->ship('codeigniter', self::CSRF."\nINFO - 2026-10-29 07:39:30 --> Next");
        SiteError::sole()->update(['status' => ErrorStatus::Resolved, 'resolved_at' => now()]);

        $this->ship('codeigniter', str_replace('07:39:23', '07:45:00', self::CSRF)."\nINFO - 2026-10-29 07:45:30 --> Next", 2);

        $this->assertSame(ErrorStatus::Open, SiteError::sole()->status);
        $this->assertNotNull(SiteError::sole()->regressed_at);
    }

    public function test_an_unknown_log_or_a_wrong_token_is_refused(): void
    {
        $this->ship('symfony', self::CSRF)->assertNotFound();
        $this->ship('codeigniter', self::CSRF, token: 'nope')->assertUnauthorized();
        $this->assertSame(0, $this->site->errorStreams()->count());
    }

    public function test_old_occurrences_are_forgotten_and_their_error_stays(): void
    {
        $this->ship('codeigniter', self::CSRF."\nINFO - 2026-10-29 07:39:30 --> Next");

        $this->travel(31)->days();
        $this->artisan('edgelog:errors --prune');

        $this->assertSame(0, SiteErrorOccurrence::count());
        $this->assertSame(1, SiteError::sole()->times_seen);
    }

    public function test_the_errors_and_one_error_are_shown_to_the_team(): void
    {
        $this->ship('codeigniter', self::CSRF."\nINFO - 2026-10-29 07:39:30 --> Next");
        $error = SiteError::sole();
        $viewer = $this->member('viewer');

        $this->actingAs($viewer)->get(route('teams.sites.site.errors', [$this->site->team, $this->site]))
            ->assertOk()->assertSee('CodeIgniter\Security\Exceptions\SecurityException')->assertSee('SYSTEMPATH/Security/Security.php:258');

        $this->actingAs($viewer)->get(route('teams.sites.site.errors.show', [$this->site->team, $this->site, $error]))
            ->assertOk()->assertSee('The action you requested is not allowed.')->assertSee('/user/project/autosave')->assertSee('CSRF.php(52)')->assertDontSee(__('Resolve'));
    }

    public function test_the_setup_shows_while_no_error_log_is_sent(): void
    {
        $this->actingAs($this->member('viewer'))->get(route('teams.sites.site.errors', [$this->site->team, $this->site]))
            ->assertOk()->assertSee(__('No error log is sent yet'))->assertSee('--errors codeigniter')->assertSee(route('ingest.store', $this->site), false);
    }

    public function test_an_admin_resolves_and_ignores_a_viewer_cannot(): void
    {
        $this->ship('codeigniter', self::CSRF."\nINFO - 2026-10-29 07:39:30 --> Next");
        $error = SiteError::sole();
        $route = route('teams.sites.site.errors.update', [$this->site->team, $this->site, $error]);

        $this->actingAs($this->member('viewer'))->put($route, ['status' => 'resolved'])->assertForbidden();

        $this->actingAs($this->member('admin'))->put($route, ['status' => 'resolved'])->assertRedirect();
        $this->assertSame(ErrorStatus::Resolved, $error->fresh()->status);
        $this->assertNotNull($error->fresh()->resolved_at);

        $this->actingAs($this->member('admin'))->put($route, ['status' => 'ignored'])->assertRedirect();
        $this->assertSame(ErrorStatus::Ignored, $error->fresh()->status);
        $this->assertNull($error->fresh()->resolved_at);
    }

    public function test_an_error_of_another_site_is_not_found(): void
    {
        $this->ship('codeigniter', self::CSRF."\nINFO - 2026-10-29 07:39:30 --> Next");
        $other = Site::factory()->create(['team_id' => $this->site->team_id]);

        $this->actingAs($this->member('admin'))
            ->get(route('teams.sites.site.errors.show', [$this->site->team, $other, SiteError::sole()]))
            ->assertNotFound();
    }
}
